<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Actions\Accounting\CloseAccountingPeriod;
use App\Actions\Accounting\RequestAccountingPeriodReopening;
use App\Models\Organization;
use App\Models\User;
use App\Services\Accounting\AccountingDashboardService;
use App\Services\Accounting\CompanyAccountingReviewChecklistService;
use App\Services\AccountingService;
use App\Services\Fiscal\TaxDashboardService;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkManagement\WorkNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;
use Throwable;

/**
 * Workspace of the accountant in charge: only the companies where the user is the active accountant in charge, their
 * review queue, observations, pending entries, reconciliations, adjustments and closings.
 *
 * @phpstan-import-type PeriodOption from OperationsService
 */
class AccountantService
{
    public const ENTRY_STATUS_LABELS = ['draft' => 'Borrador', 'pending_review' => 'En revisión', 'reviewed' => 'Revisada', 'approved' => 'Aprobada', 'posted' => 'Contabilizada', 'rejected' => 'Rechazada'];

    public const KIND_LABELS = ['standard' => 'Estándar', 'opening' => 'Apertura', 'adjustment' => 'Ajuste', 'reclassification' => 'Reclasificación', 'provision' => 'Provisión', 'closing' => 'Cierre', 'reversal' => 'Reverso', 'automatic' => 'Automática'];

    public function __construct(
        private WorkAuthorizationService $authorization,
        private SupervisorService $supervisor,
        private OperationsService $operations,
        private ReviewObservationService $observations,
        private CompanyAccountingReviewChecklistService $checklist,
        private AccountingDashboardService $dashboard,
        private AccountingService $accounting,
        private WorkNotificationService $notifications,
        private CloseAccountingPeriod $closeAccountingPeriod,
        private RequestAccountingPeriodReopening $requestReopening,
        private TaxDashboardService $fiscal,
    ) {}

    /** @return list<int> */
    public function companyIds(Organization $organization, User $user): array
    {
        abort_unless($this->authorization->can($organization, $user, 'accountant.dashboard.view'), 403);
        $isOwner = DB::table('organization_user')->where('organization_user.organization_id', $organization->id)->where('user_id', $user->id)->where('role', 'owner')->where('status', 'active')->exists();

        $query = $isOwner
            ? DB::table('companies')->where('companies.organization_id', $organization->id)->pluck('id')
            : DB::table('company_assignments')->where('company_assignments.organization_id', $organization->id)->where('user_id', $user->id)
                ->where('assignment_role', 'accountant_in_charge')->where('status', 'active')->whereNull('deleted_at')->pluck('company_id');

        return array_values(array_unique(array_map('intval', $query->all())));
    }

    /** @param list<int> $companyIds */
    public function assertCompany(array $companyIds, int $companyId): void
    {
        abort_unless(in_array($companyId, $companyIds, true), 404);
    }

    /**
     * Companies with period status plus review and observation data.
     *
     * @param  list<int>  $companyIds
     * @param  PeriodOption|null  $period
     * @return list<array<string, mixed>>
     */
    public function companies(Organization $organization, array $companyIds, ?array $period): array
    {
        $rows = $this->supervisor->companies($organization, $companyIds, $period);
        $observations = $this->observations->activeCounts($organization->id, $companyIds);
        $reviews = DB::table('company_period_reviews')->where('company_period_reviews.organization_id', $organization->id)->whereIn('company_id', $companyIds === [] ? [-1] : $companyIds)->whereNull('deleted_at')
            ->get(['id', 'company_id', 'accounting_period_id', 'status', 'reviewer_id'])->keyBy(fn (object $row): string => $row->company_id.'-'.$row->accounting_period_id);

        return array_map(function (array $company) use ($observations, $reviews): array {
            $review = $reviews->get($company['id'].'-'.$company['period_id']);

            return [
                ...$company,
                'observations' => $observations[$company['id']] ?? 0,
                'review_id' => $review === null ? null : (int) $review->id,
                'review_status' => $review?->status,
                'review_href' => '/accountant/companies/'.$company['id'].'/review',
            ];
        }, $rows);
    }

    /**
     * @param  list<int>  $companyIds
     * @param  PeriodOption|null  $period
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function dashboard(Organization $organization, User $user, array $companyIds, ?array $period, array $filters = []): array
    {
        $companies = $this->companies($organization, $companyIds, $period);
        $rows = collect($companies);
        $reviewQueue = $this->reviewQueue($organization, $user, $companyIds);

        return [
            'metrics' => [
                'assigned' => $rows->count(),
                'in_progress' => $rows->whereIn('status', ['not_started', 'in_progress'])->count(),
                'ready_for_my_review' => collect($reviewQueue)->whereIn('status', ['ready_for_review', 'in_review'])->count(),
                'with_observations' => $rows->filter(fn (array $company): bool => $company['observations'] > 0 || $company['status'] === 'changes_requested')->count(),
                'ready_for_upper_review' => $rows->where('status', 'first_level_approved')->count(),
                'pending_closings' => $rows->whereIn('status', ['approved', 'fiscal_pending', 'ready_to_close'])->count(),
            ],
            'companies' => $this->filter($companies, $filters),
            'review_queue' => array_slice($reviewQueue, 0, 8),
            'observations' => array_slice($this->observations->list($organization->id, $companyIds, ['status' => 'active']), 0, 6),
            'pending_entries' => $this->safeCount(fn (): int => count($this->pendingEntries($companyIds, $period))),
            'obligations' => array_slice(array_values(array_filter($this->supervisor->obligationRows($companyIds, now()->startOfDay(), now()->addDays(15)), fn (array $row): bool => $row['status'] !== 'completed')), 0, 5),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $companies
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function filter(array $companies, array $filters): array
    {
        return array_values(array_filter($companies, function (array $company) use ($filters): bool {
            if (! empty($filters['status']) && $company['status'] !== $filters['status']) {
                return false;
            }
            if (! empty($filters['assistant_id']) && ($company['assistant']['id'] ?? null) !== (int) $filters['assistant_id']) {
                return false;
            }
            if (! empty($filters['with_observations']) && $company['observations'] === 0) {
                return false;
            }

            return empty($filters['search']) || str_contains(mb_strtolower($company['name'].' '.$company['tax_id']), mb_strtolower((string) $filters['search']));
        }));
    }

    /**
     * Period reviews of the accountant's companies waiting for the first level, most urgent first.
     *
     * @param  list<int>  $companyIds
     * @return list<array<string, mixed>>
     */
    public function reviewQueue(Organization $organization, User $user, array $companyIds): array
    {
        $reviews = array_values(array_filter(
            $this->operations->reviews($organization->id, $this->supervisor->officeIds($companyIds)),
            fn (array $review): bool => in_array($review['company_id'], $companyIds, true) && in_array($review['status'], ['ready_for_review', 'in_review', 'changes_requested', 'first_level_approved'], true),
        ));

        return array_map(fn (array $review): array => [...$review, 'is_mine' => $review['reviewer_name'] === null || $review['reviewer_name'] === $user->name, 'review_href' => '/accountant/companies/'.$review['company_id'].'/review'], $reviews);
    }

    /**
     * Review screen of one company period: status, checklist, observations and pending entries.
     *
     * @param  PeriodOption|null  $period
     * @return array<string, mixed>
     */
    public function review(Organization $organization, int $companyId, ?array $period): array
    {
        $company = collect($this->companies($organization, [$companyId], $period))->first();
        abort_if($company === null, 404);
        $periodId = $company['period_id'];
        $review = $periodId === null ? null : DB::table('company_period_reviews')->leftJoin('users as submitter', 'submitter.id', '=', 'company_period_reviews.submitted_by')
            ->leftJoin('users as first', 'first.id', '=', 'company_period_reviews.first_level_approved_by')->leftJoin('users as upper', 'upper.id', '=', 'company_period_reviews.upper_reviewer_id')
            ->where('company_period_reviews.company_id', $companyId)->where('accounting_period_id', $periodId)->whereNull('company_period_reviews.deleted_at')
            ->first(['company_period_reviews.*', 'submitter.name as submitter_name', 'first.name as first_level_name', 'upper.name as upper_reviewer_name']);
        $workflow = (string) DB::table('offices')->join('companies', 'companies.office_id', '=', 'offices.id')->where('companies.id', $companyId)->value('offices.review_workflow');

        try {
            $checklist = $periodId === null ? null : $this->checklist->forPeriod($organization->id, $companyId, $periodId);
            $checklistError = null;
        } catch (Throwable $exception) {
            Log::warning('Accountant review checklist failed', ['company_id' => $companyId, 'error' => $exception->getMessage()]);
            $checklist = null;
            $checklistError = 'No fue posible calcular el checklist de revisión.';
        }

        return [
            'company' => $company,
            'workflow' => $workflow === 'double_level' ? 'double_level' : 'single_level',
            'review' => $review === null ? null : [
                'id' => (int) $review->id,
                'status' => (string) $review->status,
                'status_label' => OperationsService::STATUS_LABELS[$review->status] ?? (string) $review->status,
                'fiscal_status' => $review->fiscal_status,
                'submitted_by' => $review->submitter_name,
                'submitted_at' => $review->submitted_at,
                'first_level_by' => $review->first_level_name,
                'first_level_at' => $review->first_level_approved_at,
                'upper_reviewer' => $review->upper_reviewer_name,
                'approval_comment' => $review->approval_comment,
            ],
            'checklist' => $checklist,
            'checklist_error' => $checklistError,
            'observations' => $this->observations->list($organization->id, [$companyId], ['period_id' => $periodId]),
            'entries' => $this->pendingEntries([$companyId], $period),
            'fiscal_obligations' => $company['period_id'] === null ? [] : DB::table('fiscal_obligations')->leftJoin('users as assignee', 'assignee.id', '=', 'fiscal_obligations.assigned_to')->leftJoin('users as reviewer', 'reviewer.id', '=', 'fiscal_obligations.reviewer_id')
                ->where('fiscal_obligations.company_id', $companyId)->where('fiscal_obligations.accounting_period_id', $company['period_id'])->orderBy('fiscal_obligations.due_on')
                ->get(['fiscal_obligations.*', 'assignee.name as assignee_name', 'reviewer.name as reviewer_name'])->map(fn (stdClass $row): array => $this->fiscal->obligationRow($row))->values()->all(),
            'assignees' => DB::table('company_assignments')->join('users', 'users.id', '=', 'company_assignments.user_id')->where('company_id', $companyId)->where('company_assignments.status', 'active')->whereNull('company_assignments.deleted_at')
                ->orderBy('users.name')->get(['users.id', 'users.name', 'company_assignments.assignment_role'])->unique('id')->map(fn (object $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name])->values()->all(),
        ];
    }

    /**
     * Journal entries of the period that still need review, approval or posting.
     *
     * @param  list<int>  $companyIds
     * @param  PeriodOption|null  $period
     * @param  list<string>|null  $kinds
     * @return list<array<string, mixed>>
     */
    public function pendingEntries(array $companyIds, ?array $period, ?array $kinds = null, bool $includePosted = false): array
    {
        [$from, $to] = $this->supervisor->range($period);

        return array_values(DB::table('journal_entries as entry')
            ->join('accounting_periods as period', 'period.id', '=', 'entry.accounting_period_id')
            ->join('companies', 'companies.id', '=', 'period.company_id')
            ->leftJoin('users as creator', 'creator.id', '=', 'entry.created_by')
            ->leftJoin('users as approver', 'approver.id', '=', 'entry.approved_by')
            ->whereIn('period.company_id', $companyIds === [] ? [-1] : $companyIds)
            ->whereBetween('entry.entry_date', [$from, $to])
            ->whereIn('entry.status', $includePosted ? ['draft', 'pending_review', 'reviewed', 'approved', 'posted', 'rejected'] : ['draft', 'pending_review', 'reviewed', 'approved'])
            ->when($kinds !== null, fn ($query) => $query->whereIn('entry.kind', $kinds))
            ->orderBy('entry.entry_date')->limit(400)
            ->select(['entry.id', 'entry.public_id', 'entry.entry_date', 'entry.description', 'entry.reference', 'entry.kind', 'entry.status', 'entry.created_by', 'entry.approved_by', 'entry.approved_at', 'companies.id as company_id', 'companies.name as company_name', 'companies.public_id as company_public_id', 'creator.name as creator_name', 'approver.name as approver_name'])
            ->selectSub(DB::table('journal_lines')->whereColumn('journal_entry_id', 'entry.id')->selectRaw('CAST(COALESCE(SUM(debit_cents), 0) AS BIGINT)'), 'debits_cents')
            ->selectSub(DB::table('journal_lines')->whereColumn('journal_entry_id', 'entry.id')->selectRaw('CAST(COALESCE(SUM(credit_cents), 0) AS BIGINT)'), 'credits_cents')
            ->selectSub(DB::table('journal_entry_documents')->whereColumn('journal_entry_id', 'entry.id')->selectRaw('COUNT(*)'), 'documents')
            ->get()
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'public_id' => (string) $row->public_id,
                'company_id' => (int) $row->company_id,
                'company_name' => (string) $row->company_name,
                'company_public_id' => (string) $row->company_public_id,
                'date' => (string) $row->entry_date,
                'description' => (string) $row->description,
                'reference' => $row->reference,
                'kind' => (string) $row->kind,
                'kind_label' => self::KIND_LABELS[$row->kind] ?? (string) $row->kind,
                'status' => (string) $row->status,
                'status_label' => self::ENTRY_STATUS_LABELS[$row->status] ?? (string) $row->status,
                'created_by' => (int) $row->created_by,
                'creator_name' => $row->creator_name,
                'approver_name' => $row->approver_name,
                'amount_cents' => max((int) $row->debits_cents, (int) $row->credits_cents),
                'balanced' => (int) $row->debits_cents === (int) $row->credits_cents && (int) $row->debits_cents > 0,
                'documents' => (int) $row->documents,
                'destination' => '/accounting/companies/'.$row->company_public_id.'?entry='.$row->public_id,
            ])->all());
    }

    /**
     * CxC, CxP and banks auxiliary balances against the ledger per company. Differences are shown, never balanced.
     *
     * @param  list<int>  $companyIds
     * @param  PeriodOption|null  $period
     * @return list<array<string, mixed>>
     */
    public function reconciliations(Organization $organization, array $companyIds, ?array $period): array
    {
        $rows = [];
        foreach ($this->companies($organization, $companyIds, $period) as $company) {
            try {
                $data = $company['period_id'] === null ? ['reconciliations' => []] : $this->dashboard->forCompany($company['id'], $company['period_id']);
                $items = array_values(array_filter($data['reconciliations'], fn (array $row): bool => in_array($row['label'], ['Bancos', 'CxC', 'CxP'], true)));
                $error = null;
            } catch (Throwable $exception) {
                Log::warning('Accountant reconciliation failed', ['company_id' => $company['id'], 'error' => $exception->getMessage()]);
                $items = [];
                $error = 'No fue posible calcular las conciliaciones de esta empresa.';
            }

            $rows[] = [
                'company_id' => $company['id'],
                'company_name' => $company['name'],
                'period_name' => $company['period_name'],
                'error' => $error,
                'items' => array_map(fn (array $item): array => [
                    'label' => ['Bancos' => 'Bancos', 'CxC' => 'Cuentas por cobrar', 'CxP' => 'Cuentas por pagar'][$item['label']],
                    'auxiliary_cents' => $item['auxiliary_cents'],
                    'ledger_cents' => $item['ledger_cents'],
                    'difference_cents' => $item['difference_cents'],
                    'status' => $item['difference_cents'] === 0 ? 'reconciled' : 'difference',
                ], $items),
                'expediente' => '/accounting/companies/'.$company['public_id'].'/dashboard',
            ];
        }

        return $rows;
    }

    /**
     * Companies with their close readiness for the selected period.
     *
     * @param  list<int>  $companyIds
     * @param  PeriodOption|null  $period
     * @return list<array<string, mixed>>
     */
    public function closings(Organization $organization, array $companyIds, ?array $period): array
    {
        $requests = DB::table('accounting_period_reopening_requests')->whereIn('company_id', $companyIds === [] ? [-1] : $companyIds)->where('status', 'pending')->pluck('accounting_period_id')->map(fn ($id): int => (int) $id)->all();
        $closeRequests = DB::table('work_tasks')->whereIn('company_id', $companyIds === [] ? [-1] : $companyIds)->where('automation_key', 'like', 'close_request:%')->whereNull('deleted_at')->whereNotIn('status', ['completed', 'cancelled'])->pluck('automation_key')
            ->map(fn ($key): string => implode(':', array_slice(explode(':', (string) $key), 0, 3)))->all();

        return array_map(function (array $company) use ($organization, $requests, $closeRequests): array {
            $close = null;
            $error = null;
            if ($company['period_id'] !== null) {
                try {
                    $close = $this->supervisor->closeChecks($organization->id, $company['id'], $company['period_id']);
                } catch (Throwable $exception) {
                    Log::warning('Accountant close checks failed', ['company_id' => $company['id'], 'error' => $exception->getMessage()]);
                    $error = 'No fue posible calcular el checklist de cierre.';
                }
            }

            return [
                ...$company,
                'close' => $close,
                'close_error' => $error,
                'close_requested' => in_array('close_request:'.$company['id'].':'.$company['period_id'], $closeRequests, true),
                'reopen_requested' => in_array($company['period_id'], $requests, true),
            ];
        }, $this->companies($organization, $companyIds, $period));
    }

    /** Asks the closers of the company (its supervisor, otherwise the office managers) to close an approved period. */
    public function requestClose(Organization $organization, User $actor, int $companyId, int $periodId, ?string $note): void
    {
        abort_unless($this->authorization->can($organization, $actor, 'periods.request_close'), 403);
        $review = DB::table('company_period_reviews')->where('company_id', $companyId)->where('accounting_period_id', $periodId)->whereNull('deleted_at')->first(['status', 'fiscal_status']);
        if ($review === null || $review->status !== 'approved') {
            throw ValidationException::withMessages(['period' => 'Solo se solicita el cierre de un período con la revisión contable aprobada.']);
        }
        $checks = $this->checklist->forPeriod($organization->id, $companyId, $periodId);
        if (! $checks['ready_for_approval']) {
            throw ValidationException::withMessages(['period' => 'El checklist tiene bloqueantes o errores; resuélvelos antes de solicitar el cierre.']);
        }

        $company = DB::table('companies')->where('id', $companyId)->first(['id', 'name', 'office_id']);
        abort_if($company === null, 404);
        $prefix = 'close_request:'.$companyId.':'.$periodId.':';
        $key = $prefix.now()->format('YmdHis');
        if (DB::table('work_tasks')->where('automation_key', 'like', $prefix.'%')->whereNull('deleted_at')->whereNotIn('status', ['completed', 'cancelled'])->exists()) {
            throw ValidationException::withMessages(['period' => 'Ya existe una solicitud de cierre pendiente para este período.']);
        }
        $closer = DB::table('company_assignments')->where('company_id', $companyId)->where('assignment_role', 'accounting_supervisor')->where('status', 'active')->whereNull('deleted_at')->value('user_id');
        $periodName = (string) DB::table('accounting_periods')->where('id', $periodId)->value('name');

        $taskId = DB::table('work_tasks')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'office_id' => $company->office_id,
            'company_id' => $companyId,
            'accounting_period_id' => $periodId,
            'module' => 'closing',
            'task_type' => 'close_request',
            'title' => 'Cerrar período '.$periodName.': '.$company->name,
            'description' => trim('Solicitado por '.$actor->name.'. '.($note ?? '')),
            'status' => 'pending',
            'priority' => 'high',
            'assigned_to' => $closer,
            'created_by' => $actor->id,
            'due_at' => now()->addDays(2),
            'automation_key' => $key,
            'source_type' => 'accounting_periods',
            'source_id' => $periodId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->accounting->audit($organization, $actor, 'accounting_period.close_requested', 'accounting_periods', $periodId, ['company_id' => $companyId, 'task_id' => $taskId, 'note' => $note], (int) $company->office_id);
        $this->notifications->notify($organization->id, $closer === null ? null : (int) $closer, $actor->id, 'period.close_requested', 'Solicitud de cierre: '.$company->name, $periodName, 'work_tasks', $taskId);
    }

    /** Closes the period only for users with periods.manage and once accounting and fiscal reviews are approved. */
    public function closePeriod(Organization $organization, User $actor, int $companyId, int $periodId): void
    {
        abort_unless($this->authorization->can($organization, $actor, 'periods.manage'), 403);
        $review = DB::table('company_period_reviews')->where('company_id', $companyId)->where('accounting_period_id', $periodId)->whereNull('deleted_at')->first(['status', 'fiscal_status']);
        if ($review === null || $review->status !== 'approved' || $review->fiscal_status !== 'approved') {
            throw ValidationException::withMessages(['period' => 'El cierre requiere la revisión contable y la revisión fiscal aprobadas.']);
        }
        $company = DB::table('companies')->where('id', $companyId)->first();
        abort_if($company === null, 404);
        $this->closeAccountingPeriod->execute($organization, $company, $periodId, $actor, true);
    }

    /** Requests the reopening of a closed period with a mandatory reason. */
    public function requestReopen(Organization $organization, User $actor, int $companyId, int $periodId, string $reason): void
    {
        abort_unless($this->authorization->can($organization, $actor, 'periods.request_reopen'), 403);
        $company = DB::table('companies')->where('id', $companyId)->first();
        abort_if($company === null, 404);
        $this->requestReopening->execute($organization, $company, $periodId, $actor, $reason, true);
    }

    /**
     * Recent activity on the accountant's companies.
     *
     * @param  list<int>  $companyIds
     * @return list<array<string, mixed>>
     */
    public function activity(int $organizationId, array $companyIds): array
    {
        return array_slice($this->supervisor->audit($organizationId, $companyIds, [], 150), 0, 150);
    }

    /** @param callable(): int $callback */
    private function safeCount(callable $callback): ?int
    {
        try {
            return $callback();
        } catch (Throwable $exception) {
            Log::warning('Accountant counter failed', ['error' => $exception->getMessage()]);

            return null;
        }
    }
}
