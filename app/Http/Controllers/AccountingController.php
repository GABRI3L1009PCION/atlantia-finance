<?php

namespace App\Http\Controllers;

use App\Actions\Accounting\ApproveAccountingPeriodReopening;
use App\Actions\Accounting\CloseAccountingPeriod;
use App\Actions\Accounting\ReopenAccountingPeriod;
use App\Actions\Accounting\RequestAccountingPeriodReopening;
use App\Models\Organization;
use App\Services\Accounting\AccountingDashboardService;
use App\Services\Accounting\AccountingPeriodCloseChecklistService;
use App\Services\Accounting\CostCenterService;
use App\Services\Accounting\LedgerService;
use App\Services\Accounting\TrialBalanceService;
use App\Services\AccountingService;
use App\Services\WorkManagement\CompanyAdministrationService;
use App\Services\WorkManagement\CompanyAssignmentService;
use App\Services\WorkManagement\CompanyPortfolioService;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkspaceContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccountingController extends Controller
{
    public function __construct(
        private WorkspaceContext $context,
        private AccountingService $accounting,
        private AccountingDashboardService $accountingDashboard,
        private LedgerService $ledgerService,
        private TrialBalanceService $trialBalanceService,
        private CompanyAssignmentService $companyAssignments,
    ) {}

    /** GET accounting: company directory, scoped by office and assignment. No writes. */
    public function index(Request $request): Response
    {
        $org = $this->context->organization($request->user());

        if (app(WorkAuthorizationService::class)->isExecutiveReadOnly($org, $request->user())) {
            return Inertia::render('work/director-companies', app(CompanyPortfolioService::class)->payload($org, $request->user(), $request->only(['period'])));
        }

        $search = mb_substr((string) $request->query('search', ''), 0, 100);
        $companies = $this->accounting->companies($org, $request->user());
        if ($search !== '') {
            $companies->where(fn (Builder $q) => $q->where('companies.name', 'like', '%'.$search.'%')->orWhere('companies.tax_id', 'like', '%'.$search.'%'));
        }

        return Inertia::render('accounting/index', [
            'organizationName' => $org->name,
            'canManage' => $org->pivot->role === 'owner',
            'canConsolidate' => $this->context->hasFeature($org, 'consolidated_dashboard'),
            'companyQuota' => $this->companyQuota($org),
            'companies' => $companies->orderBy('companies.name')->paginate(12)->withQueryString(),
            'offices' => $org->pivot->role === 'owner' ? $org->offices()->where('status', 'active')->get(['id', 'name']) : [],
            'search' => $search,
        ]);
    }

    /** @return array{limit: int|null, used: int} */
    private function companyQuota(Organization $organization): array
    {
        $limit = $this->context->limit($organization, 'active_cases');

        return [
            'limit' => $limit === PHP_INT_MAX ? null : $limit,
            'used' => DB::table('companies')->where('companies.organization_id', $organization->id)->count(),
        ];
    }

    /** POST accounting/companies: create company under an owned office; enforce plan quota. */
    public function storeCompany(Request $request): RedirectResponse
    {
        $org = $this->context->organization($request->user());
        abort_if(app(WorkAuthorizationService::class)->isExecutiveReadOnly($org, $request->user()), 403, 'El Socio Director solo consulta; la administración la realiza el Administrador de la firma.');
        $data = $request->validate(['name' => 'required|string|max:150', 'tax_id' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9-]+$/'], 'email' => 'nullable|email|max:255', 'office_id' => 'required|integer'], $this->messages());
        $created = app(CompanyAdministrationService::class)->create($org, $request->user(), [
            'name' => (string) $data['name'],
            'tax_id' => (string) $data['tax_id'],
            'email' => $data['email'] ?? null,
            'office_id' => (int) $data['office_id'],
        ]);
        $uuid = $created['public_id'];

        return back()->with('accounting_notice', 'Empresa creada correctamente. Ya tiene su catálogo contable inicial; ábrela para revisar las cuentas y el primer período.');
    }

    /** GET company workspace: accounts, periods, journal and posted ledger, never other tenants. */
    public function show(Request $request, string $company): Response
    {
        $org = $this->context->organization($request->user());
        $record = $this->accounting->company($org, $request->user(), $company);
        $this->accounting->assertModule($org, $record->id, $request->user(), 'journal.view');
        $owner = $org->pivot->role === 'owner';
        $periods = DB::table('accounting_periods')->where('company_id', $record->id)->orderByDesc('starts_on')->get();
        $periodStats = DB::table('journal_entries as e')
            ->leftJoin('journal_lines as l', 'l.journal_entry_id', '=', 'e.id')
            ->whereIn('e.accounting_period_id', $periods->pluck('id'))
            ->select('e.accounting_period_id')
            ->selectRaw('COUNT(DISTINCT e.id) as entries_count')
            ->selectRaw("COUNT(DISTINCT CASE WHEN e.status IN ('posted', 'reversed') THEN e.id END) as posted_count")
            ->selectRaw("COUNT(DISTINCT CASE WHEN e.status IN ('draft', 'pending_review', 'reviewed', 'approved') THEN e.id END) as draft_count")
            ->selectRaw("COUNT(DISTINCT CASE WHEN e.status = 'void' THEN e.id END) as void_count")
            ->selectRaw("CAST(COALESCE(SUM(CASE WHEN e.status IN ('posted', 'reversed') THEN l.debit_cents ELSE 0 END), 0) AS BIGINT) as debit_cents")
            ->selectRaw("CAST(COALESCE(SUM(CASE WHEN e.status IN ('posted', 'reversed') THEN l.credit_cents ELSE 0 END), 0) AS BIGINT) as credit_cents")
            ->groupBy('e.accounting_period_id')
            ->get()
            ->keyBy('accounting_period_id');
        $periods->transform(function ($period) use ($periodStats) {
            $stats = $periodStats->get($period->id);
            $period->entries_count = (int) ($stats->entries_count ?? 0);
            $period->posted_count = (int) ($stats->posted_count ?? 0);
            $period->draft_count = (int) ($stats->draft_count ?? 0);
            $period->void_count = (int) ($stats->void_count ?? 0);
            $period->debit_cents = (int) ($stats->debit_cents ?? 0);
            $period->credit_cents = (int) ($stats->credit_cents ?? 0);

            return $period;
        });
        $periodId = $request->integer('period');
        if (! $request->has('period') && $periods->isNotEmpty()) {
            $today = now()->toDateString();
            $defaultPeriod = $periods->first(fn ($period) => $period->closed_at === null && $period->starts_on <= $today && $period->ends_on >= $today)
                ?? $periods->first(fn ($period) => $period->closed_at === null)
                ?? $periods->first();
            $periodId = (int) $defaultPeriod->id;
        }
        // A link from a source document carries the entry to show: its period decides what the journal lists.
        $linkedEntry = (string) $request->query('entry', '');
        if (Str::isUuid($linkedEntry)) {
            $linkedPeriod = DB::table('journal_entries as e')->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')
                ->where('p.company_id', $record->id)->where('e.public_id', $linkedEntry)->value('e.accounting_period_id');
            $periodId = $linkedPeriod === null ? $periodId : (int) $linkedPeriod;
        } else {
            $linkedEntry = '';
        }
        $accountId = $request->integer('account');
        $journalSearch = trim((string) $request->query('journal_search', ''));
        $journalStatus = (string) $request->query('journal_status', '');
        $journalKind = (string) $request->query('journal_kind', '');
        $journalSource = (string) $request->query('journal_source', '');
        $journalSupport = (string) $request->query('journal_support', '');
        $journalThirdParty = trim((string) $request->query('journal_third_party', ''));
        $journalCostCenter = trim((string) $request->query('journal_cost_center', ''));
        $journalProject = trim((string) $request->query('journal_project', ''));
        $journalCreatedBy = $request->integer('journal_created_by');
        $journalFrom = (string) $request->query('journal_from', '');
        $journalTo = (string) $request->query('journal_to', '');
        $ledgerFilters = [
            'search' => $this->queryText($request, 'ledger_search'),
            'group' => $this->queryOption($request, 'ledger_group', ['asset', 'liability', 'equity', 'income', 'expense']),
            'account_mode' => $this->queryOption($request, 'ledger_account_mode', ['movement', 'group', 'auxiliary']),
            'nature' => $this->queryOption($request, 'ledger_nature', ['debit', 'credit']),
            'third_party' => $this->queryText($request, 'ledger_third_party'),
            'third_party_type' => $this->queryText($request, 'ledger_third_party_type', 60),
            'cost_center' => $this->queryText($request, 'ledger_cost_center'),
            'project' => $this->queryText($request, 'ledger_project'),
            'source' => $this->queryOption($request, 'ledger_source', ['manual', 'fel_document', 'sales_invoice', 'sales_receipt', 'purchase_document', 'purchase_payment', 'bank_transaction', 'cash_transaction', 'inventory_movement', 'payroll', 'fixed_asset', 'project']),
            'kind' => $this->queryOption($request, 'ledger_kind', ['standard', 'opening', 'adjustment', 'reclassification', 'provision', 'closing', 'reversal', 'automatic']),
            'entry_number' => $this->queryText($request, 'ledger_entry_number', 20),
            'document' => $this->queryText($request, 'ledger_document'),
            'reference' => $this->queryText($request, 'ledger_reference'),
            'posted_by' => $request->integer('ledger_posted_by'),
            'accounts' => $this->queryOption($request, 'ledger_accounts', ['with', 'without', 'all'], 'with'),
            'balance' => $this->queryOption($request, 'ledger_balance', ['nonzero', 'zero', 'unusual']),
            'side' => $this->queryOption($request, 'ledger_side', ['debit', 'credit']),
            'min_amount_cents' => $this->queryCents($request, 'ledger_min_amount'),
            'max_amount_cents' => $this->queryCents($request, 'ledger_max_amount'),
            'sort' => $this->queryOption($request, 'ledger_sort', ['date', 'entry', 'debit', 'credit', 'amount', 'third_party'], 'date'),
            'direction' => $this->queryOption($request, 'ledger_direction', ['asc', 'desc'], 'asc'),
        ];
        $ledgerFromQuery = $this->queryDate($request, 'ledger_from');
        $ledgerToQuery = $this->queryDate($request, 'ledger_to');
        $trialFilters = [
            'search' => $this->queryText($request, 'trial_search'),
            'group' => $this->queryOption($request, 'trial_group', ['asset', 'liability', 'equity', 'income', 'expense']),
            'level' => $this->queryOption($request, 'trial_level', ['1', '2', '3']),
            'nature' => $this->queryOption($request, 'trial_nature', ['debit', 'credit']),
            'account_mode' => $this->queryOption($request, 'trial_account_mode', ['movement', 'group', 'auxiliary']),
            'accounts' => $this->queryOption($request, 'trial_accounts', ['with', 'without', 'all'], 'with'),
            'balance' => $this->queryOption($request, 'trial_balance', ['zero', 'nonzero', 'unusual']),
            'third_party' => $this->queryText($request, 'trial_third_party'),
            'cost_center' => $this->queryText($request, 'trial_cost_center'),
            'project' => $this->queryText($request, 'trial_project'),
            'source' => $this->queryOption($request, 'trial_source', ['manual', 'fel_document', 'sales_invoice', 'sales_receipt', 'purchase_document', 'purchase_payment', 'bank_transaction', 'cash_transaction', 'inventory_movement', 'payroll', 'fixed_asset', 'project']),
            'kind' => $this->queryOption($request, 'trial_kind', ['standard', 'opening', 'adjustment', 'reclassification', 'provision', 'closing', 'reversal', 'automatic']),
            'view' => $this->queryOption($request, 'trial_view', ['compact', 'complete'], 'compact'),
        ];
        $trialFromQuery = $this->queryDate($request, 'trial_from');
        $trialToQuery = $this->queryDate($request, 'trial_to');
        $accounts = DB::table('ledger_accounts')->where('company_id', $record->id)->orderBy('code')->get();
        abort_if($periodId && ! $periods->contains('id', $periodId), 404);
        abort_if($accountId && ! $accounts->contains('id', $accountId), 404);
        $selectedPeriod = $periodId ? $periods->firstWhere('id', $periodId) : null;
        $ledgerFrom = $ledgerFromQuery ?: $selectedPeriod?->starts_on;
        $ledgerTo = $ledgerToQuery ?: $selectedPeriod?->ends_on;
        abort_if($ledgerFrom && $ledgerTo && $ledgerFrom > $ledgerTo, 422, 'La fecha final del Libro Mayor no puede ser anterior a la inicial.');
        $trialFrom = $trialFromQuery ?: $selectedPeriod?->starts_on;
        $trialTo = $trialToQuery ?: $selectedPeriod?->ends_on;
        abort_if($trialFrom && $trialTo && $trialFrom > $trialTo, 422, 'La fecha final del Balance de Comprobación no puede ser anterior a la inicial.');
        $bookEntries = DB::table('journal_entries as e')->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')
            ->where('p.company_id', $record->id)
            ->when($periodId, fn (Builder $q) => $q->where('p.id', $periodId));

        $journalEntries = (clone $bookEntries)
            ->when($journalStatus !== '', fn (Builder $q) => $q->where('e.status', $journalStatus))
            ->when($journalKind !== '', fn (Builder $q) => $q->where('e.kind', $journalKind))
            ->when($journalSource !== '', fn (Builder $q) => $q->where('e.source_type', $journalSource === 'manual' ? null : $journalSource))
            ->when($journalCreatedBy, fn (Builder $q) => $q->where('e.created_by', $journalCreatedBy))
            ->when($journalFrom !== '', fn (Builder $q) => $q->whereDate('e.entry_date', '>=', $journalFrom))
            ->when($journalTo !== '', fn (Builder $q) => $q->whereDate('e.entry_date', '<=', $journalTo))
            ->when($accountId, fn (Builder $q) => $q->whereExists(fn (Builder $line) => $line->selectRaw('1')->from('journal_lines as filter_line')->whereColumn('filter_line.journal_entry_id', 'e.id')->where('filter_line.ledger_account_id', $accountId)))
            ->when($journalThirdParty !== '', fn (Builder $q) => $q->whereExists(fn (Builder $line) => $line->selectRaw('1')->from('journal_lines as third_line')->whereColumn('third_line.journal_entry_id', 'e.id')->where(fn (Builder $nested) => $nested->where('third_line.third_party_type', 'like', "%{$journalThirdParty}%")->orWhere('third_line.third_party_id', (int) $journalThirdParty))))
            ->when($journalCostCenter !== '', fn (Builder $q) => $q->whereExists(fn (Builder $line) => $line->selectRaw('1')->from('journal_lines as cost_line')->whereColumn('cost_line.journal_entry_id', 'e.id')->where('cost_line.cost_center', 'like', "%{$journalCostCenter}%")))
            ->when($journalProject !== '', fn (Builder $q) => $q->whereExists(fn (Builder $line) => $line->selectRaw('1')->from('journal_lines as project_line')->whereColumn('project_line.journal_entry_id', 'e.id')->where('project_line.project', 'like', "%{$journalProject}%")))
            ->when($journalSupport === 'with', fn (Builder $q) => $q->whereExists(fn (Builder $doc) => $doc->selectRaw('1')->from('journal_entry_documents as filter_doc')->whereColumn('filter_doc.journal_entry_id', 'e.id')))
            ->when($journalSupport === 'without', fn (Builder $q) => $q->whereNotExists(fn (Builder $doc) => $doc->selectRaw('1')->from('journal_entry_documents as filter_doc')->whereColumn('filter_doc.journal_entry_id', 'e.id')));
        if ($journalSearch !== '') {
            $journalEntries->where(function (Builder $q) use ($journalSearch): void {
                $q->where('e.description', 'like', "%{$journalSearch}%")
                    ->orWhere('e.reference', 'like', "%{$journalSearch}%")
                    ->orWhere('e.public_id', 'like', "%{$journalSearch}%")
                    ->orWhere('e.number', 'like', "%{$journalSearch}%")
                    ->orWhereExists(fn (Builder $line) => $line->selectRaw('1')->from('journal_lines as search_line')
                        ->join('ledger_accounts as search_account', 'search_account.id', '=', 'search_line.ledger_account_id')
                        ->whereColumn('search_line.journal_entry_id', 'e.id')
                        ->where(fn (Builder $nested) => $nested->where('search_account.code', 'like', "%{$journalSearch}%")->orWhere('search_account.name', 'like', "%{$journalSearch}%")));
            });
        }
        $journal = (clone $journalEntries)->join('users as creator', 'creator.id', '=', 'e.created_by')
            ->leftJoin('users as reviewer', 'reviewer.id', '=', 'e.reviewed_by')
            ->leftJoin('users as approver', 'approver.id', '=', 'e.approved_by')
            ->leftJoin('users as poster', 'poster.id', '=', 'e.posted_by')
            ->select('e.*', 'p.name as period_name', 'creator.name as author', 'reviewer.name as reviewer', 'approver.name as approver', 'poster.name as poster')
            ->selectSub(DB::table('journal_entry_documents as support')->selectRaw('COUNT(*)')->whereColumn('support.journal_entry_id', 'e.id'), 'support_count')
            ->selectSub(DB::table('accounting_source_entries as source')->join('fel_documents as fel', 'fel.id', '=', 'source.source_id')
                ->where('source.source_type', 'fel_document')->where('source.company_id', $record->id)
                ->where('fel.company_id', $record->id)->whereColumn('source.journal_entry_id', 'e.id')
                ->select('fel.public_id')->limit(1), 'fel_document_id')
            ->selectSub(DB::table('journal_entries as r')->select('r.public_id')->whereColumn('r.reversal_of', 'e.id')->limit(1), 'reversed_by')
            ->orderByDesc('e.entry_date')->orderByDesc('e.id')->paginate(15, ['*'], 'journal_page')->withQueryString();
        $lines = DB::table('journal_lines as l')->join('ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')
            ->whereIn('l.journal_entry_id', $journal->getCollection()->pluck('id'))->where('a.company_id', $record->id)
            ->select('l.*', 'a.code', 'a.name')->orderBy('l.position')->get()->groupBy('journal_entry_id');
        $documents = DB::table('journal_entry_documents')->whereIn('journal_entry_id', $journal->getCollection()->pluck('id'))
            ->select('public_id', 'journal_entry_id', 'original_name', 'mime_type', 'size_bytes', 'created_at')->orderBy('created_at')->get()->groupBy('journal_entry_id');
        $history = DB::table('audit_logs as log')->leftJoin('users as actor', 'actor.id', '=', 'log.user_id')
            ->where('log.organization_id', $org->id)
            ->where('log.auditable_type', 'journal_entries')
            ->whereIn('log.auditable_id', $journal->getCollection()->pluck('id'))
            ->orderBy('log.id')
            ->select('log.id', 'log.auditable_id', 'log.action', 'log.new_values', 'log.occurred_at', 'actor.name as actor')
            ->get()
            ->groupBy('auditable_id');
        $journal->getCollection()->transform(function ($entry) use ($documents, $lines, $history) {
            $entry->lines = $lines->get($entry->id, collect());
            $entry->documents = $documents->get($entry->id, collect());
            $entry->history = $history->get($entry->id, collect())->map(fn ($log) => [
                'id' => $log->id,
                'action' => $log->action,
                'actor' => $log->actor,
                'date' => $log->occurred_at,
                'changes' => json_decode($log->new_values ?? '[]', true),
            ])->values();

            return $entry;
        });
        $includeEmptyLedgerAccounts = in_array($ledgerFilters['accounts'], ['without', 'all'], true);
        $balances = $this->ledgerService->accountBalances($record->id, $ledgerFrom, $ledgerTo, $includeEmptyLedgerAccounts, $ledgerFilters);
        $ledgerOverview = $this->ledgerService->overview($record->id, $ledgerFrom, $ledgerTo, $ledgerFilters);
        $ledgerSummary = $accountId ? $this->ledgerService->accountSummary($record->id, $accountId, $ledgerFrom, $ledgerTo, $ledgerFilters) : null;
        $ledger = $accountId ? $this->ledgerService->movements($record->id, $accountId, $ledgerFrom, $ledgerTo, 25, $ledgerFilters) : null;
        $trialBalance = $this->trialBalanceService->build($record->id, $trialFrom, $trialTo, $trialFilters);
        $canManageAccounting = $this->accounting->canModule($org, $record->id, $request->user(), 'accounts.manage')
            || $this->accounting->canModule($org, $record->id, $request->user(), 'periods.manage');
        $members = $owner ? $org->users()->wherePivot('status', 'active')->wherePivot('role', '!=', 'owner')
            ->whereHas('offices', fn ($q) => $q->where('offices.id', $record->office_id)->where('office_user.status', 'active'))->get(['users.id', 'users.name'])->map(fn ($user) => ['id' => $user->id, 'name' => $user->name, 'permission' => DB::table('company_user')->where('company_id', $record->id)->where('user_id', $user->id)->value('permission') ?? 'none']) : [];
        $journalCreators = DB::table('journal_entries as e')->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')
            ->join('users as creator', 'creator.id', '=', 'e.created_by')
            ->where('p.company_id', $record->id)
            ->distinct()
            ->orderBy('creator.name')
            ->get(['creator.id', 'creator.name']);

        return Inertia::render('accounting/company', [
            'accountHistory' => $owner ? DB::table('audit_logs as log')->leftJoin('users as actor', 'actor.id', '=', 'log.user_id')
                ->where('log.organization_id', $org->id)->where('log.auditable_type', 'ledger_accounts')->where('log.action', 'account.updated')
                ->whereIn('log.auditable_id', $accounts->pluck('id'))->orderByDesc('log.id')
                ->select('log.id', 'log.occurred_at', 'log.old_values', 'log.new_values', 'actor.name as actor')->limit(30)->get()
                ->map(fn ($log) => ['id' => $log->id, 'date' => $log->occurred_at, 'actor' => $log->actor, 'before' => json_decode($log->old_values, true), 'after' => json_decode($log->new_values, true)]) : [],
            'company' => $record, 'canManage' => $canManageAccounting,
            'canWrite' => $this->accounting->canJournal($org, $record->id, $request->user(), 'create'),
            'journalPermissions' => [
                'create' => $this->accounting->canJournal($org, $record->id, $request->user(), 'create'),
                'attachSupport' => $this->accounting->canJournal($org, $record->id, $request->user(), 'attach_support'),
                'submit' => $this->accounting->canJournal($org, $record->id, $request->user(), 'submit'),
                'review' => $this->accounting->canJournal($org, $record->id, $request->user(), 'review'),
                'approve' => $this->accounting->canJournal($org, $record->id, $request->user(), 'approve'),
                'post' => $this->accounting->canJournal($org, $record->id, $request->user(), 'post'),
                'reject' => $this->accounting->canJournal($org, $record->id, $request->user(), 'reject'),
                'reverse' => $this->accounting->canJournal($org, $record->id, $request->user(), 'reverse'),
                'void' => $this->accounting->canJournal($org, $record->id, $request->user(), 'void'),
                'import' => $this->accounting->canJournal($org, $record->id, $request->user(), 'import'),
            ],
            'accounts' => $accounts, 'periods' => $periods, 'journal' => $journal, 'balances' => $balances, 'ledger' => $ledger,
            'ledgerOverview' => $ledgerOverview, 'ledgerSummary' => $ledgerSummary, 'trialBalance' => $trialBalance,
            'accountingDashboard' => $this->accountingDashboard->forCompany($record->id, $periodId ?: null),
            'reopeningRequests' => DB::table('accounting_period_reopening_requests as request')
                ->join('users as requester', 'requester.id', '=', 'request.requested_by')
                ->leftJoin('users as approver', 'approver.id', '=', 'request.approved_by')
                ->where('request.company_id', $record->id)
                ->orderByDesc('request.id')
                ->limit(20)
                ->get(['request.*', 'requester.name as requester_name', 'approver.name as approver_name']),
            'members' => $members, 'journalCreators' => $journalCreators, 'filters' => [
                'period' => $periodId,
                'entry' => $linkedEntry,
                'account' => $accountId,
                'journal_search' => $journalSearch,
                'journal_status' => $journalStatus,
                'journal_kind' => $journalKind,
                'journal_source' => $journalSource,
                'journal_support' => $journalSupport,
                'journal_third_party' => $journalThirdParty,
                'journal_cost_center' => $journalCostCenter,
                'journal_project' => $journalProject,
                'journal_created_by' => $journalCreatedBy,
                'journal_from' => $journalFrom,
                'journal_to' => $journalTo,
                'ledger_search' => $ledgerFilters['search'],
                'ledger_group' => $ledgerFilters['group'],
                'ledger_account_mode' => $ledgerFilters['account_mode'],
                'ledger_nature' => $ledgerFilters['nature'],
                'ledger_third_party' => $ledgerFilters['third_party'],
                'ledger_third_party_type' => $ledgerFilters['third_party_type'],
                'ledger_cost_center' => $ledgerFilters['cost_center'],
                'ledger_project' => $ledgerFilters['project'],
                'ledger_source' => $ledgerFilters['source'],
                'ledger_kind' => $ledgerFilters['kind'],
                'ledger_entry_number' => $ledgerFilters['entry_number'],
                'ledger_document' => $ledgerFilters['document'],
                'ledger_reference' => $ledgerFilters['reference'],
                'ledger_posted_by' => $ledgerFilters['posted_by'],
                'ledger_accounts' => $ledgerFilters['accounts'],
                'ledger_balance' => $ledgerFilters['balance'],
                'ledger_side' => $ledgerFilters['side'],
                'ledger_min_amount' => $request->query('ledger_min_amount', ''),
                'ledger_max_amount' => $request->query('ledger_max_amount', ''),
                'ledger_from' => $ledgerFromQuery,
                'ledger_to' => $ledgerToQuery,
                'ledger_sort' => $ledgerFilters['sort'],
                'ledger_direction' => $ledgerFilters['direction'],
                'trial_search' => $trialFilters['search'],
                'trial_group' => $trialFilters['group'],
                'trial_level' => $trialFilters['level'],
                'trial_nature' => $trialFilters['nature'],
                'trial_account_mode' => $trialFilters['account_mode'],
                'trial_accounts' => $trialFilters['accounts'],
                'trial_balance' => $trialFilters['balance'],
                'trial_third_party' => $trialFilters['third_party'],
                'trial_cost_center' => $trialFilters['cost_center'],
                'trial_project' => $trialFilters['project'],
                'trial_source' => $trialFilters['source'],
                'trial_kind' => $trialFilters['kind'],
                'trial_view' => $trialFilters['view'],
                'trial_from' => $trialFromQuery,
                'trial_to' => $trialToQuery,
            ], 'importPreview' => session('journal_import_preview'),
        ]);
    }

    /** POST company assignments: owner grants viewer/editor or revokes access for an office member. */
    public function assign(Request $request, string $company): RedirectResponse
    {
        $org = $this->context->organization($request->user(), true);
        $record = $this->accounting->company($org, $request->user(), $company);
        $data = $request->validate([
            'user_id' => 'required|integer',
            'permission' => ['required', Rule::in(['none', 'viewer', 'editor'])],
            'assignment_role' => ['nullable', Rule::in(CompanyAssignmentService::ROLES)],
            'primary' => 'nullable|boolean',
        ], $this->messages());
        DB::transaction(function () use ($request, $org, $record, $data): void {
            $this->accounting->lock($record->id);
            $member = $org->users()->wherePivot('status', 'active')->wherePivot('role', '!=', 'owner')->whereKey((int) $data['user_id'])->firstOrFail();
            abort_unless($member->offices()->where('offices.id', $record->office_id)->wherePivot('status', 'active')->exists(), 422);
            if ($data['permission'] === 'none') {
                $this->companyAssignments->unassign($org, $request->user(), $record->id, $member->id, $data['assignment_role'] ?? null);
            } else {
                $role = $data['assignment_role'] ?? ($data['permission'] === 'viewer' ? 'auditor' : 'accountant_in_charge');
                $this->companyAssignments->unassign($org, $request->user(), $record->id, $member->id);
                $this->companyAssignments->assign(
                    $org,
                    $request->user(),
                    (int) $record->office_id,
                    (int) $record->id,
                    (int) $member->id,
                    $role,
                    (bool) ($data['primary'] ?? false),
                    ['legacy_permission' => $data['permission']],
                );

                if ($data['permission'] === 'viewer' && $role !== 'auditor') {
                    DB::table('company_user')
                        ->where('company_id', $record->id)
                        ->where('user_id', $member->id)
                        ->update(['permission' => 'viewer']);
                }
            }
            $this->accounting->audit($org, $request->user(), 'company.assignment.'.$data['permission'], 'companies', $record->id, ['user_id' => $member->id]);
        });

        return back()->with('accounting_notice', 'Permisos actualizados.');
    }

    /** POST accounts: owner defines a posting account in this company. */
    public function account(Request $request, string $company): RedirectResponse
    {
        $org = $this->context->organization($request->user());
        $record = $this->accounting->company($org, $request->user(), $company, true);
        $this->accounting->assertModule($org, $record->id, $request->user(), 'accounts.manage');
        $data = $request->validate(['code' => ['required', 'string', 'max:20', 'regex:/^[0-9A-Za-z.-]+$/'], 'name' => 'required|string|max:150', 'type' => ['required', Rule::in(['asset', 'liability', 'equity', 'income', 'expense'])]], $this->messages());
        DB::transaction(function () use ($request, $org, $record, $data): void {
            $this->accounting->lock($record->id);
            if (DB::table('ledger_accounts')->where('company_id', $record->id)->where('code', $data['code'])->exists()) {
                throw ValidationException::withMessages(['code' => 'Ese código ya existe en esta empresa.']);
            }
            $id = DB::table('ledger_accounts')->insertGetId([...$data, 'company_id' => $record->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->accounting->audit($org, $request->user(), 'account.created', 'ledger_accounts', $id);
        });

        return back()->with('accounting_notice', 'Cuenta contable creada.');
    }

    /** POST account template: add missing starter accounts without altering existing codes. */
    public function accountTemplate(Request $request, string $company): RedirectResponse
    {
        $org = $this->context->organization($request->user());
        $record = $this->accounting->company($org, $request->user(), $company, true);
        $this->accounting->assertModule($org, $record->id, $request->user(), 'accounts.manage');
        $created = DB::transaction(function () use ($request, $org, $record): int {
            $this->accounting->lock($record->id);
            $created = $this->accounting->installDefaultChart($record->id);
            $this->accounting->audit($org, $request->user(), 'account.template.installed', 'companies', $record->id, ['created' => $created]);

            return $created;
        });

        return back()->with('accounting_notice', $created ? "Plantilla instalada: {$created} cuentas agregadas." : 'El catálogo ya contiene toda la plantilla inicial.');
    }

    /**
     * PATCH company/accounts/{account}: owner corrects code, name and type in ledger_accounts.
     * Keeps journal_lines and entries unchanged; audit_logs stores before/after and reason.
     * Closed posted periods freeze account metadata; company lock serializes with posting/closure.
     */
    public function updateAccount(Request $request, string $company, int $account): RedirectResponse
    {
        $org = $this->context->organization($request->user());
        $record = $this->accounting->company($org, $request->user(), $company, true);
        $this->accounting->assertModule($org, $record->id, $request->user(), 'accounts.manage');
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'regex:/^[0-9A-Za-z.-]+$/'],
            'name' => 'required|string|max:150',
            'type' => ['required', Rule::in(['asset', 'liability', 'equity', 'income', 'expense'])],
            'reason' => 'required|string|max:350',
            'acknowledged' => 'accepted',
        ], $this->messages());
        DB::transaction(function () use ($request, $org, $record, $account, $data): void {
            $this->accounting->lock($record->id);
            $row = DB::table('ledger_accounts')->where('company_id', $record->id)->where('id', $account)->firstOrFail();
            $before = ['code' => $row->code, 'name' => $row->name, 'type' => $row->type];
            $after = array_intersect_key($data, $before);
            if ($before === $after) {
                return;
            }
            $closed = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
                ->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')
                ->where('l.ledger_account_id', $account)->whereIn('e.status', ['posted', 'reversed'])->whereNotNull('p.closed_at')->exists();
            if ($closed) {
                throw ValidationException::withMessages(['accounting' => 'Esta cuenta tiene movimientos en períodos cerrados. No se permite modificar sus datos.']);
            }
            if (DB::table('ledger_accounts')->where('company_id', $record->id)->where('code', $data['code'])->where('id', '!=', $account)->exists()) {
                throw ValidationException::withMessages(['code' => 'Ese código ya existe en esta empresa.']);
            }
            DB::table('ledger_accounts')->where('id', $account)->update([...$after, 'updated_at' => now()]);
            DB::table('audit_logs')->insert([
                'organization_id' => $org->id, 'user_id' => $request->user()->id,
                'action' => 'account.updated', 'auditable_type' => 'ledger_accounts', 'auditable_id' => $account,
                'old_values' => json_encode($before, JSON_THROW_ON_ERROR),
                'new_values' => json_encode([...$after, 'reason' => $data['reason']], JSON_THROW_ON_ERROR),
                'occurred_at' => now(), 'created_at' => now(),
            ]);
        });

        return back()->with('accounting_notice', 'Cuenta revisada. Los cambios se conservan en el historial; los importes no se modificaron.');
    }

    /** POST periods: reject overlapping date intervals, including already closed periods. */
    public function period(Request $request, string $company): RedirectResponse
    {
        $org = $this->context->organization($request->user());
        $record = $this->accounting->company($org, $request->user(), $company, true);
        $this->accounting->assertModule($org, $record->id, $request->user(), 'periods.manage');
        $data = $request->validate(['name' => 'required|string|max:80', 'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on'], $this->messages());
        DB::transaction(function () use ($request, $org, $record, $data): void {
            $this->accounting->lock($record->id);
            if (DB::table('accounting_periods')->where('company_id', $record->id)->where('starts_on', '<=', $data['ends_on'])->where('ends_on', '>=', $data['starts_on'])->exists()) {
                throw ValidationException::withMessages(['starts_on' => 'Las fechas se superponen con otro período.']);
            }
            $id = DB::table('accounting_periods')->insertGetId([...$data, 'company_id' => $record->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->accounting->audit($org, $request->user(), 'period.created', 'accounting_periods', $id);
        });

        return back()->with('accounting_notice', 'Período abierto.');
    }

    /** POST close: blocks subsequent posting; no financial closing entries are generated. */
    public function close(Request $request, string $company, int $period, CloseAccountingPeriod $closePeriod): RedirectResponse
    {
        $org = $this->context->organization($request->user());
        $record = $this->accounting->company($org, $request->user(), $company, true);
        $this->accounting->assertModule($org, $record->id, $request->user(), 'periods.manage');
        $result = $closePeriod->execute($org, $record, $period, $request->user());

        return back()->with('accounting_notice', $result->message);
    }

    /** GET: every check that gates the close of a period, with its state and what to do about it. */
    public function periodChecks(Request $request, string $company, int $period, AccountingPeriodCloseChecklistService $checklist): JsonResponse
    {
        $org = $this->context->organization($request->user());
        $record = $this->accounting->company($org, $request->user(), $company);
        $this->accounting->assertModule($org, $record->id, $request->user(), 'journal.view');
        abort_unless(DB::table('accounting_periods')->where('company_id', $record->id)->where('id', $period)->exists(), 404);

        return response()->json(['checks' => $checklist->forPeriod((int) $record->id, $period)]);
    }

    public function requestReopen(Request $request, string $company, int $period, RequestAccountingPeriodReopening $reopening): RedirectResponse
    {
        $org = $this->context->organization($request->user());
        $record = $this->accounting->company($org, $request->user(), $company, true);
        $this->accounting->assertModule($org, $record->id, $request->user(), 'periods.manage');
        $data = $request->validate(['reason' => 'required|string|min:10|max:1000'], $this->messages());
        $result = $reopening->execute($org, $record, $period, $request->user(), $data['reason']);

        return back()->with('accounting_notice', $result->message);
    }

    public function approveReopen(Request $request, string $company, int $reopeningRequest, ApproveAccountingPeriodReopening $approve): RedirectResponse
    {
        $org = $this->context->organization($request->user());
        $record = $this->accounting->company($org, $request->user(), $company, true);
        $this->accounting->assertModule($org, $record->id, $request->user(), 'periods.manage');
        $result = $approve->execute($org, $record, $reopeningRequest, $request->user());

        return back()->with('accounting_notice', $result->message);
    }

    public function executeReopen(Request $request, string $company, int $reopeningRequest, ReopenAccountingPeriod $reopen): RedirectResponse
    {
        $org = $this->context->organization($request->user());
        $record = $this->accounting->company($org, $request->user(), $company, true);
        $this->accounting->assertModule($org, $record->id, $request->user(), 'periods.manage');
        $result = $reopen->execute($org, $record, $reopeningRequest, $request->user());

        return back()->with('accounting_notice', $result->message);
    }

    /** POST entries: creates balanced draft + lines atomically; no client-supplied status accepted. */
    public function entry(Request $request, string $company): RedirectResponse
    {
        $org = $this->context->organization($request->user());
        $record = $this->accounting->company($org, $request->user(), $company, true);
        abort_unless($this->accounting->canJournal($org, $record->id, $request->user(), 'create'), 403);
        $data = $request->validate([
            'request_id' => 'required|uuid',
            'period_id' => 'required|integer',
            'entry_date' => 'required|date_format:Y-m-d',
            'description' => 'required|string|max:500',
            'reference' => 'nullable|string|max:80',
            'kind' => ['required', Rule::in(['standard', 'ordinary', 'opening', 'adjustment', 'reclassification', 'provision', 'closing', 'reversal', 'automatic'])],
            'source_type' => 'nullable|string|max:60',
            'source_id' => 'nullable|integer',
            'accounting_event' => 'nullable|string|max:100',
            'posting_key' => 'nullable|string|max:160',
            'lines' => 'required|array|min:2|max:100',
            'lines.*.account_id' => 'required|integer',
            'lines.*.description' => 'nullable|string|max:255',
            'lines.*.third_party_type' => 'nullable|string|max:60',
            'lines.*.third_party_id' => 'nullable|integer',
            'lines.*.cost_center' => 'nullable|string|max:120',
            'lines.*.project' => 'nullable|string|max:120',
            'lines.*.debit' => 'required',
            'lines.*.credit' => 'required',
        ], $this->messages());
        $data['kind'] = $data['kind'] === 'ordinary' ? 'standard' : $data['kind'];
        DB::transaction(function () use ($request, $org, $record, $data): void {
            $this->accounting->lock($record->id);
            $this->accounting->company($org, $request->user(), $record->public_id, true);
            $lines = $this->accounting->lines($record->id, $data['lines']);
            app(CostCenterService::class)->validateLines($record->id, $lines, true);
            $id = $this->accounting->createDraft($org, $request->user(), $record, $data, $lines);
            $this->accounting->audit($org, $request->user(), 'entry.drafted', 'journal_entries', $id);
        });

        return back()->with('accounting_notice', 'Borrador guardado. El propietario debe aprobarlo para que afecte los libros.');
    }

    /** POST entry action: owner approves, voids a draft, or posts an exact linked reversal. */
    public function process(Request $request, string $company, string $entry): RedirectResponse
    {
        $org = $this->context->organization($request->user());
        $record = $this->accounting->company($org, $request->user(), $company);
        $data = $request->validate(['action' => ['required', Rule::in(['submit', 'review', 'approve', 'post', 'reject', 'void', 'reverse'])], 'period_id' => 'required_if:action,reverse|nullable|integer', 'entry_date' => 'required_if:action,reverse|nullable|date_format:Y-m-d', 'reason' => 'required_if:action,reverse|required_if:action,void|required_if:action,reject|nullable|string|max:500'], $this->messages());
        abort_unless($this->accounting->canJournal($org, $record->id, $request->user(), $data['action']), 403);
        DB::transaction(function () use ($request, $org, $record, $entry, $data): void {
            $this->accounting->lock($record->id);
            $row = $this->row(DB::table('journal_entries')->whereIn('accounting_period_id', DB::table('accounting_periods')->where('company_id', $record->id)->select('id'))->where('public_id', $entry));
            if ($data['action'] === 'submit') {
                $this->accounting->submitForReview($org, $row, $request->user());
            } elseif ($data['action'] === 'review') {
                $this->accounting->review($org, $row, $request->user());
            } elseif ($data['action'] === 'approve') {
                $this->accounting->approve($org, $row, $request->user());
            } elseif ($data['action'] === 'post') {
                $this->accounting->post($record->id, $row, $request->user());
                $this->accounting->audit($org, $request->user(), 'journal_entry.posted', 'journal_entries', $row->id);
            } elseif ($data['action'] === 'reject') {
                $this->accounting->reject($org, $row, $request->user(), $data['reason']);
            } elseif ($data['action'] === 'void') {
                if ($row->status !== 'draft') {
                    throw ValidationException::withMessages(['accounting' => 'Solo puedes descartar borradores. Usa un reverso para los asientos contabilizados.']);
                }
                DB::table('journal_entries')->where('id', $row->id)->update(['status' => 'void', 'updated_at' => now()]);
            } else {
                $this->accounting->reverse($org, $request->user(), $record->id, $row, (int) $data['period_id'], $data['entry_date'], $data['reason']);
            }
            $this->accounting->audit($org, $request->user(), 'entry.'.$data['action'], 'journal_entries', $row->id, ['reason' => $data['reason'] ?? null]);
        });

        return back()->with('accounting_notice', 'Operación registrada en la bitácora contable.');
    }

    public function importTemplate(Request $request, string $company): StreamedResponse
    {
        $org = $this->context->organization($request->user());
        $this->accounting->company($org, $request->user(), $company);

        return response()->streamDownload(function (): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['grupo', 'fecha', 'periodo_id', 'tipo', 'concepto', 'referencia', 'codigo_cuenta', 'descripcion_linea', 'tercero_tipo', 'tercero_id', 'centro_costo', 'proyecto', 'debe', 'haber']);
            fputcsv($out, ['ASIENTO-001', now()->toDateString(), '', 'standard', 'Asiento importado', 'REF-001', '1102', 'Movimiento bancario', 'customer', '', 'Administracion', 'Atlantia', '100.00', '0']);
            fputcsv($out, ['ASIENTO-001', now()->toDateString(), '', 'standard', 'Asiento importado', 'REF-001', '4101', 'Ingreso reconocido', 'customer', '', 'Administracion', 'Atlantia', '0', '100.00']);
            fclose($out);
        }, 'plantilla-libro-diario.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function importPreview(Request $request, string $company): RedirectResponse
    {
        $org = $this->context->organization($request->user());
        $record = $this->accounting->company($org, $request->user(), $company, true);
        abort_unless($this->accounting->canJournal($org, $record->id, $request->user(), 'import'), 403);
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:4096']], $this->messages());
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);
        $preview = $this->parseJournalImport($record->id, $file);

        return back()->with('accounting_notice', $preview['errors'] ? 'La importación tiene observaciones. Corrige el archivo antes de crear borradores.' : 'Archivo validado. Puedes importar los borradores.')
            ->with('journal_import_preview', $preview);
    }

    public function importJournal(Request $request, string $company): RedirectResponse
    {
        $org = $this->context->organization($request->user());
        $record = $this->accounting->company($org, $request->user(), $company, true);
        abort_unless($this->accounting->canJournal($org, $record->id, $request->user(), 'import'), 403);
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:4096']], $this->messages());
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);
        $preview = $this->parseJournalImport($record->id, $file);
        if ($preview['errors']) {
            throw ValidationException::withMessages(['file' => 'El archivo tiene errores. Ejecuta la prevalidación y corrige las filas marcadas.']);
        }
        $created = 0;
        DB::transaction(function () use ($request, $org, $record, $preview, &$created): void {
            $this->accounting->lock($record->id);
            foreach ($preview['entries'] as $group => $entry) {
                $lines = $this->accounting->lines($record->id, $entry['lines']);
                app(CostCenterService::class)->validateLines($record->id, $lines, true);
                $this->accounting->createDraft($org, $request->user(), $record, [
                    'request_id' => (string) Str::uuid(),
                    'period_id' => $entry['period_id'],
                    'entry_date' => $entry['entry_date'],
                    'description' => $entry['description'],
                    'reference' => $entry['reference'],
                    'kind' => $entry['kind'],
                    'source_type' => null,
                    'source_id' => null,
                    'accounting_event' => 'journal.import',
                    'posting_key' => 'journal_import:'.$record->id.':'.sha1($group.'|'.$entry['entry_date'].'|'.$entry['description'].'|'.json_encode($entry['lines'], JSON_THROW_ON_ERROR)),
                ], $lines);
                $created++;
            }
        });

        return back()->with('accounting_notice', "{$created} asiento(s) importado(s) como borrador para revisión.");
    }

    /** @return array{entries: array<string, array<string, mixed>>, errors: list<string>, total_debit: int, total_credit: int} */
    private function parseJournalImport(int $companyId, UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        if (! $handle) {
            throw ValidationException::withMessages(['file' => 'No fue posible leer el archivo.']);
        }
        // Spreadsheets save UTF-8 files with a byte-order mark and, in Spanish locales, separate the columns with semicolons.
        $firstLine = (string) fgets($handle);
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
        rewind($handle);
        if (fread($handle, 3) !== "\xEF\xBB\xBF") {
            rewind($handle);
        }
        $header = fgetcsv($handle, null, $delimiter);
        $expected = ['grupo', 'fecha', 'periodo_id', 'tipo', 'concepto', 'referencia', 'codigo_cuenta', 'descripcion_linea', 'tercero_tipo', 'tercero_id', 'centro_costo', 'proyecto', 'debe', 'haber'];
        if (! $header || array_map(fn ($value) => mb_strtolower(trim((string) $value)), $header) !== $expected) {
            throw ValidationException::withMessages(['file' => 'El archivo no tiene las columnas de la plantilla del Libro Diario ('.implode(', ', $expected).'). Descarga la plantilla y copia tus filas en ella.']);
        }
        $accounts = DB::table('ledger_accounts')->where('company_id', $companyId)->where('is_active', true)->where('accepts_movements', true)->where('is_group', false)->pluck('id', 'code');
        $openPeriods = DB::table('accounting_periods')->where('company_id', $companyId)->whereNull('closed_at')->get();
        $entries = [];
        $errors = [];
        $totalDebit = $totalCredit = 0;
        $rowNumber = 1;
        while (($row = fgetcsv($handle, null, $delimiter)) !== false) {
            $rowNumber++;
            if (count(array_filter($row, fn ($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }
            if (count($row) > count($expected)) {
                $errors[] = "Fila {$rowNumber}: estructura inválida.";

                continue;
            }
            $data = array_combine($expected, array_pad($row, count($expected), ''));
            $group = trim((string) $data['grupo']);
            $date = trim((string) $data['fecha']);
            $code = trim((string) $data['codigo_cuenta']);
            try {
                $debit = $this->accounting->cents(trim((string) $data['debe']) ?: '0');
                $credit = $this->accounting->cents(trim((string) $data['haber']) ?: '0');
            } catch (ValidationException) {
                $errors[] = "Fila {$rowNumber}: importe inválido.";

                continue;
            }
            if ($group === '' || $date === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! $accounts->has($code) || ($debit > 0) === ($credit > 0)) {
                $errors[] = "Fila {$rowNumber}: grupo, fecha, cuenta o importes no son válidos.";

                continue;
            }
            $periodId = (int) ($data['periodo_id'] ?: 0);
            $period = $periodId
                ? $openPeriods->firstWhere('id', $periodId)
                : $openPeriods->first(fn ($period) => $date >= $period->starts_on && $date <= $period->ends_on);
            if (! $period) {
                $errors[] = "Fila {$rowNumber}: no existe período abierto para la fecha.";

                continue;
            }
            $entries[$group] ??= [
                'entry_date' => $date,
                'period_id' => $period->id,
                'kind' => in_array($data['tipo'], ['standard', 'opening', 'adjustment', 'reclassification', 'provision', 'closing'], true) ? $data['tipo'] : 'standard',
                'description' => trim((string) $data['concepto']) ?: 'Asiento importado',
                'reference' => trim((string) $data['referencia']) ?: null,
                'debit' => 0,
                'credit' => 0,
                'lines' => [],
            ];
            if ($entries[$group]['entry_date'] !== $date || (int) $entries[$group]['period_id'] !== (int) $period->id) {
                $errors[] = "Fila {$rowNumber}: todas las filas del grupo deben compartir fecha y período.";

                continue;
            }
            $entries[$group]['debit'] += $debit;
            $entries[$group]['credit'] += $credit;
            $entries[$group]['lines'][] = [
                'account_id' => (int) $accounts[$code],
                'description' => trim((string) $data['descripcion_linea']) ?: null,
                'third_party_type' => trim((string) $data['tercero_tipo']) ?: null,
                'third_party_id' => is_numeric($data['tercero_id']) ? (int) $data['tercero_id'] : null,
                'cost_center' => trim((string) $data['centro_costo']) ?: null,
                'project' => trim((string) $data['proyecto']) ?: null,
                'debit' => number_format($debit / 100, 2, '.', ''),
                'credit' => number_format($credit / 100, 2, '.', ''),
            ];
            $totalDebit += $debit;
            $totalCredit += $credit;
        }
        fclose($handle);
        foreach ($entries as $group => $entry) {
            if (count($entry['lines']) < 2 || $entry['debit'] !== $entry['credit'] || $entry['debit'] <= 0) {
                $errors[] = "Grupo {$group}: el asiento no cumple partida doble.";
            }
        }

        // The same document loaded twice must be booked once: same reference and same amount, in the file or already in the books.
        $seen = [];
        foreach ($entries as $group => $entry) {
            if ($entry['reference'] === null) {
                continue;
            }
            $key = mb_strtolower($entry['reference']).'|'.$entry['debit'];
            if (isset($seen[$key])) {
                $errors[] = "Grupo {$group}: la referencia {$entry['reference']} con el mismo monto ya está en el archivo (grupo {$seen[$key]}). ¿Es un duplicado?";

                continue;
            }
            $seen[$key] = $group;
            $existing = DB::table('journal_entries as e')->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')->join('journal_lines as l', 'l.journal_entry_id', '=', 'e.id')
                ->where('p.company_id', $companyId)->whereRaw('lower(e.reference) = ?', [mb_strtolower($entry['reference'])])->whereNotIn('e.status', ['void', 'rejected', 'reversed'])
                ->groupBy('e.id')->havingRaw('CAST(SUM(l.debit_cents) AS BIGINT) = ?', [$entry['debit']])->first(['e.id', 'e.description']);
            if ($existing !== null) {
                $errors[] = "Grupo {$group}: la referencia {$entry['reference']} con el mismo monto ya existe en el Libro Diario («{$existing->description}»). ¿Es un duplicado?";
            }
        }

        return ['entries' => $entries, 'errors' => $errors, 'total_debit' => $totalDebit, 'total_credit' => $totalCredit];
    }

    private function queryText(Request $request, string $key, int $limit = 120): string
    {
        return mb_substr(trim((string) $request->query($key, '')), 0, $limit);
    }

    /** @param list<string> $allowed */
    private function queryOption(Request $request, string $key, array $allowed, string $default = ''): string
    {
        $value = (string) $request->query($key, $default);

        return in_array($value, $allowed, true) ? $value : $default;
    }

    private function queryDate(Request $request, string $key): string
    {
        $value = trim((string) $request->query($key, ''));

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
    }

    private function queryCents(Request $request, string $key): ?int
    {
        $value = trim((string) $request->query($key, ''));
        if ($value === '' || ! preg_match('/^\d{1,10}(\.\d{1,2})?$/', $value)) {
            return null;
        }

        return (int) round(((float) $value) * 100);
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return ['required' => 'Este campo es obligatorio.', 'required_if' => 'Este campo es obligatorio para esta operación.', 'integer' => 'Selecciona una opción válida.', 'in' => 'Selecciona una opción válida.', 'date_format' => 'Usa una fecha válida.', 'after_or_equal' => 'La fecha final no puede ser anterior a la inicial.', 'max' => 'El valor supera el tamaño permitido.', 'min' => 'Agrega al menos dos líneas.', 'regex' => 'El formato no es válido.', 'email' => 'Escribe un correo válido.', 'uuid' => 'Recarga la página e inténtalo nuevamente.'];
    }

    private function row(Builder $query): stdClass
    {
        return $query->firstOrFail();
    }
}
