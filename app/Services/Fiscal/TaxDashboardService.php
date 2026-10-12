<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Models\Organization;
use App\Models\User;
use App\Services\Operations\OperationsService;
use App\Services\Operations\ReviewObservationService;
use App\Services\Operations\SupervisorService;
use App\Services\WorkManagement\WorkAuthorizationService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use stdClass;
use Throwable;

/**
 * Fiscal work desk of the tax specialist: only the companies where the user is the active tax specialist. Every
 * figure is read from the fiscal obligations, VAT books, FEL documents, incidents, observations and tasks.
 *
 * @phpstan-import-type PeriodOption from OperationsService
 */
class TaxDashboardService
{
    private const OPEN = ['pending', 'in_progress', 'ready_for_review', 'changes_requested', 'approved'];

    public function __construct(
        private WorkAuthorizationService $authorization,
        private CompanyFiscalStatusService $status,
        private FiscalObligationReadinessService $readiness,
        private VatBookService $vatBooks,
        private ReviewObservationService $observations,
        private OperationsService $operations,
        private SupervisorService $supervisor,
    ) {}

    /** @return list<int> */
    public function companyIds(Organization $organization, User $user): array
    {
        abort_unless($this->authorization->can($organization, $user, 'fiscal.dashboard.view'), 403);
        $isOwner = DB::table('organization_user')->where('organization_user.organization_id', $organization->id)->where('user_id', $user->id)->where('role', 'owner')->where('status', 'active')->exists();

        $query = $isOwner
            ? DB::table('companies')->where('companies.organization_id', $organization->id)->pluck('id')
            : DB::table('company_assignments')->where('company_assignments.organization_id', $organization->id)->where('user_id', $user->id)
                ->where('assignment_role', 'tax_specialist')->where('status', 'active')->whereNull('deleted_at')->pluck('company_id');

        return array_values(array_unique(array_map('intval', $query->all())));
    }

    /**
     * @param  PeriodOption|null  $period
     * @return array{0: int, 1: int}
     */
    public function reference(?array $period): array
    {
        $day = $period === null ? now() : Carbon::parse($period['ends_on']);

        return [(int) $day->year, (int) $day->month];
    }

    /**
     * @param  list<int>  $companyIds
     * @param  PeriodOption|null  $period
     * @return list<array<string, mixed>>
     */
    public function companies(Organization $organization, array $companyIds, ?array $period): array
    {
        [$year, $month] = $this->reference($period);
        $incidents = DB::table('operational_incidents')->where('operational_incidents.organization_id', $organization->id)->whereIn('company_id', $companyIds === [] ? [-1] : $companyIds)
            ->whereIn('type', ['fiscal', 'fel', 'document'])->whereIn('status', ['open', 'in_progress', 'blocked'])->groupBy('company_id')->selectRaw('company_id, CAST(COUNT(*) AS BIGINT) as total')->pluck('total', 'company_id');

        return array_values(DB::table('companies')->whereIn('id', $companyIds === [] ? [-1] : $companyIds)->orderBy('name')->get(['id', 'public_id', 'name', 'tax_id'])
            ->map(function (stdClass $company) use ($year, $month, $incidents): array {
                try {
                    $state = $this->status->forCompany((int) $company->id, $year, $month);
                    $error = null;
                } catch (Throwable $exception) {
                    Log::warning('Fiscal company status failed', ['company_id' => $company->id, 'error' => $exception->getMessage()]);
                    $state = null;
                    $error = 'No fue posible calcular el estado fiscal.';
                }
                $stages = is_array($state['stages'] ?? null) ? $state['stages'] : [];
                $stage = function (string $key) use ($stages): ?array {
                    foreach ($stages as $item) {
                        if (is_array($item) && ($item['key'] ?? null) === $key) {
                            return $item;
                        }
                    }

                    return null;
                };

                return [
                    'id' => (int) $company->id,
                    'public_id' => (string) $company->public_id,
                    'name' => (string) $company->name,
                    'tax_id' => (string) $company->tax_id,
                    'period' => ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'][$month - 1].' '.$year,
                    'error' => $error,
                    'stages' => $state['stages'] ?? [],
                    'progress' => $state['progress'] ?? null,
                    'status' => $state['status'] ?? 'error',
                    'status_label' => $state['status_label'] ?? 'No disponible',
                    'obligations_total' => $state['obligations_total'] ?? 0,
                    'obligations_completed' => $state['obligations_completed'] ?? 0,
                    'vat_book' => $stage('vat_book'),
                    'fel' => $stage('fel'),
                    'documents' => $stage('documents'),
                    'incidents' => (int) ($incidents[$company->id] ?? 0),
                    'overdue' => $state['overdue'] ?? false,
                    'blocked' => $state['blocked'] ?? false,
                    'due_on' => $state['due_on'] ?? null,
                    'destination' => '/tax/companies/'.$company->id,
                    'company_dashboard' => '/accounting/companies/'.$company->public_id.'/dashboard?return_to=tax-dashboard',
                ];
            })->all());
    }

    /**
     * @param  list<int>  $companyIds
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function obligations(int $organizationId, array $companyIds, array $filters = [], int $limit = 300): array
    {
        return array_values(DB::table('fiscal_obligations as obligation')
            ->join('companies', 'companies.id', '=', 'obligation.company_id')
            ->leftJoin('users as assignee', 'assignee.id', '=', 'obligation.assigned_to')
            ->leftJoin('users as reviewer', 'reviewer.id', '=', 'obligation.reviewer_id')
            ->where('obligation.organization_id', $organizationId)
            ->whereIn('obligation.company_id', $companyIds === [] ? [-1] : $companyIds)
            ->when(! empty($filters['company_id']), fn ($query) => $query->where('obligation.company_id', (int) $filters['company_id']))
            ->when(($filters['status'] ?? 'open') === 'open', fn ($query) => $query->whereIn('obligation.status', self::OPEN))
            ->when(! in_array($filters['status'] ?? 'open', ['open', 'all'], true), fn ($query) => $query->where('obligation.status', $filters['status']))
            ->when(! empty($filters['overdue']), fn ($query) => $query->where('obligation.due_on', '<', now()->toDateString())->whereIn('obligation.status', self::OPEN))
            ->when(! empty($filters['assigned_to']), fn ($query) => $query->where('obligation.assigned_to', (int) $filters['assigned_to']))
            ->orderBy('obligation.due_on')->limit($limit)
            ->get(['obligation.*', 'companies.name as company_name', 'companies.public_id as company_public_id', 'assignee.name as assignee_name', 'reviewer.name as reviewer_name'])
            ->map(fn (stdClass $row): array => $this->obligationRow($row))->all());
    }

    /** @return array<string, mixed> */
    public function obligationRow(stdClass $row): array
    {
        $open = in_array($row->status, self::OPEN, true);
        $days = (int) now()->startOfDay()->diffInDays(Carbon::parse($row->due_on)->startOfDay(), false);

        return [
            'id' => (int) $row->id,
            'company_id' => (int) $row->company_id,
            'company_name' => $row->company_name ?? null,
            'type' => (string) $row->obligation_type,
            'name' => (string) $row->name,
            'reference_period' => (string) $row->reference_period,
            'reference_year' => (int) $row->reference_year,
            'reference_month' => (int) $row->reference_month,
            'due_on' => (string) $row->due_on,
            'internal_due_on' => (string) $row->internal_due_on,
            'review_due_on' => (string) $row->review_due_on,
            'days_left' => $days,
            'overdue' => $open && $days < 0,
            'internal_overdue' => in_array($row->status, ['pending', 'in_progress', 'changes_requested'], true) && Carbon::parse($row->internal_due_on)->endOfDay()->isPast(),
            'status' => (string) $row->status,
            'status_label' => FiscalObligationWorkflowService::STATUS_LABELS[$row->status] ?? (string) $row->status,
            'assigned_to' => $row->assigned_to === null ? null : (int) $row->assigned_to,
            'assignee_name' => $row->assignee_name ?? null,
            'reviewer_name' => $row->reviewer_name ?? null,
            'stale' => $this->readiness->isStale($row),
            'filing_reference' => $row->filing_reference,
            'filed_on' => $row->filed_on,
            'last_observation' => $row->last_observation,
            'destination' => '/tax/obligations/'.$row->id,
        ];
    }

    /**
     * FEL documents that need attention (failed or still certifying), read from the FEL module.
     *
     * @param  list<int>  $companyIds
     * @return list<array<string, mixed>>
     */
    public function felIncidents(array $companyIds, bool $includeSettled = false): array
    {
        return array_values(DB::table('fel_documents')->join('companies', 'companies.id', '=', 'fel_documents.company_id')->whereIn('fel_documents.company_id', $companyIds === [] ? [-1] : $companyIds)
            ->when(! $includeSettled, fn ($query) => $query->whereIn('fel_documents.status', ['failed', 'pending', 'processing']))
            ->orderByRaw("CASE fel_documents.status WHEN 'failed' THEN 0 WHEN 'processing' THEN 1 WHEN 'pending' THEN 2 ELSE 3 END")->orderByDesc('fel_documents.issued_on')->limit(200)
            ->get(['fel_documents.id', 'fel_documents.document_type', 'fel_documents.series', 'fel_documents.number', 'fel_documents.issued_on', 'fel_documents.status', 'fel_documents.environment', 'fel_documents.total_cents', 'fel_documents.receiver_snapshot', 'fel_documents.error_code', 'fel_documents.authorization_number', 'companies.name as company_name', 'companies.public_id'])
            ->map(function (stdClass $row): array {
                $receiver = json_decode((string) $row->receiver_snapshot, true);

                return [
                    'id' => (int) $row->id,
                    'company' => (string) $row->company_name,
                    'document' => $row->series.'-'.$row->number,
                    'type' => (string) $row->document_type,
                    'issued_on' => (string) $row->issued_on,
                    'receiver' => is_array($receiver) ? (string) ($receiver['name'] ?? '—') : '—',
                    'total_cents' => (int) $row->total_cents,
                    'status' => (string) $row->status,
                    'status_label' => ['failed' => 'Error de certificación', 'pending' => 'Pendiente', 'processing' => 'En certificación', 'certified' => 'Certificado', 'cancelled' => 'Anulado', 'draft' => 'Borrador'][$row->status] ?? (string) $row->status,
                    'environment' => (string) $row->environment,
                    'error_code' => $row->error_code,
                    'authorization' => $row->authorization_number,
                    'destination' => '/accounting/companies/'.$row->public_id.'/fel/'.$row->id,
                ];
            })->all());
    }

    /**
     * VAT books of the recent months of each company where IVA applies.
     *
     * @param  list<int>  $companyIds
     * @return list<array<string, mixed>>
     */
    public function vatBooks(array $companyIds, int $months = 3): array
    {
        $rows = [];
        $companies = DB::table('companies')->leftJoin('company_fiscal_profiles as profile', 'profile.company_id', '=', 'companies.id')->whereIn('companies.id', $companyIds === [] ? [-1] : $companyIds)
            ->orderBy('companies.name')->get(['companies.id', 'companies.name', 'companies.public_id', 'profile.vat_regime']);
        foreach ($companies as $company) {
            if (($company->vat_regime ?? 'general') === 'exempt') {
                continue;
            }
            for ($back = 1; $back <= $months; $back++) {
                $day = now()->startOfMonth()->subMonths($back - 1);
                try {
                    $book = $this->vatBooks->book((int) $company->id, (int) $day->year, (int) $day->month);
                    $rows[] = [
                        'key' => $company->id.'-'.$day->format('Y-m'),
                        'company' => (string) $company->name,
                        'month' => $book['label'],
                        'status' => $book['status'],
                        'sales' => count($book['sales']),
                        'purchases' => count($book['purchases']),
                        'sales_vat_cents' => $book['settlement']['sales_vat_cents'],
                        'purchase_vat_cents' => $book['settlement']['purchase_vat_cents'],
                        'payable_cents' => $book['settlement']['payable_cents'],
                        'failing' => collect($book['checks'])->filter(fn (array $check): bool => ! $check['ok'] && ($check['blocking'] ?? true))->pluck('label')->values()->all(),
                        'can_close' => $book['can_close'],
                        'error' => null,
                        'destination' => '/accounting/companies/'.$company->public_id.'/vat-books?year='.$day->year.'&month='.$day->month,
                    ];
                } catch (Throwable $exception) {
                    Log::warning('Tax VAT book failed', ['company_id' => $company->id, 'error' => $exception->getMessage()]);
                    $rows[] = ['key' => $company->id.'-'.$day->format('Y-m'), 'company' => (string) $company->name, 'month' => $day->format('m/Y'), 'status' => 'error', 'sales' => null, 'purchases' => null, 'sales_vat_cents' => null, 'purchase_vat_cents' => null, 'payable_cents' => null, 'failing' => [], 'can_close' => false, 'error' => 'No fue posible calcular el libro.', 'destination' => '/accounting/companies/'.$company->public_id.'/vat-books'];
                }
            }
        }

        return $rows;
    }

    /**
     * Withholdings only where the company configured a withholding tax: the configured rules, the month's records and
     * the obligation.
     *
     * @param  list<int>  $companyIds
     * @return list<array<string, mixed>>
     */
    public function withholdings(int $organizationId, array $companyIds, int $year, int $month): array
    {
        $from = Carbon::create($year, $month, 1)->startOfMonth()->toDateString();
        $to = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();

        return array_values(DB::table('fiscal_taxes')->join('companies', 'companies.id', '=', 'fiscal_taxes.company_id')->whereIn('fiscal_taxes.company_id', $companyIds === [] ? [-1] : $companyIds)
            ->where('fiscal_taxes.tax_type', 'withholding')->where('fiscal_taxes.status', 'active')->orderBy('companies.name')
            ->get(['fiscal_taxes.id', 'fiscal_taxes.company_id', 'fiscal_taxes.code', 'fiscal_taxes.name', 'fiscal_taxes.legal_basis', 'companies.name as company_name', 'companies.public_id'])
            ->map(function (stdClass $tax) use ($organizationId, $from, $to, $year, $month): array {
                $rule = DB::table('fiscal_tax_rules')->where('fiscal_tax_id', $tax->id)->where('is_active', true)->where('valid_from', '<=', $to)->where(fn ($query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', $from))->orderByDesc('valid_from')->first(['rate_basis_points', 'valid_from', 'valid_to']);
                $records = DB::table('operational_records')->where('company_id', $tax->company_id)->where('module', 'impuestos')->whereBetween('effective_on', [$from, $to])
                    ->where(fn ($query) => $query->where('record_type', 'ilike', '%reten%')->orWhere('title', 'ilike', '%reten%'));
                $obligation = DB::table('fiscal_obligations')->where('fiscal_obligations.organization_id', $organizationId)->where('company_id', $tax->company_id)->where('obligation_type', 'withholding')->where('reference_year', $year)->where('reference_month', $month)->first(['id', 'status']);

                return [
                    'key' => (string) $tax->id,
                    'company' => (string) $tax->company_name,
                    'tax' => $tax->code.' · '.$tax->name,
                    'rate' => $rule === null ? null : $rule->rate_basis_points / 100,
                    'in_force' => $rule !== null,
                    'records' => (clone $records)->count(),
                    'amount_cents' => (int) (clone $records)->sum('amount_cents'),
                    'obligation_status' => $obligation === null ? null : (FiscalObligationWorkflowService::STATUS_LABELS[$obligation->status] ?? $obligation->status),
                    'obligation_destination' => $obligation === null ? null : '/tax/obligations/'.$obligation->id,
                    'destination' => '/accounting/companies/'.$tax->public_id.'/operations/impuestos',
                ];
            })->all());
    }

    /**
     * Fiscal documents that are missing: open document requests and approved purchases of the month without support.
     *
     * @param  list<int>  $companyIds
     * @return list<array<string, mixed>>
     */
    public function documents(int $organizationId, array $companyIds, int $year, int $month): array
    {
        $ids = $companyIds === [] ? [-1] : $companyIds;
        $from = Carbon::create($year, $month, 1)->startOfMonth()->toDateString();
        $to = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();
        $requests = DB::table('operational_incidents as incident')->join('companies', 'companies.id', '=', 'incident.company_id')->where('incident.organization_id', $organizationId)
            ->whereIn('incident.company_id', $ids)->where('incident.type', 'document')->whereIn('incident.status', ['open', 'in_progress', 'blocked'])
            ->get(['incident.id', 'incident.title', 'incident.description', 'incident.status', 'incident.created_at', 'companies.name as company_name'])
            ->map(fn (stdClass $row): array => ['key' => 'request:'.$row->id, 'company' => (string) $row->company_name, 'type' => 'Solicitud', 'description' => (string) ($row->description ?: $row->title), 'source' => 'Solicitud de documento', 'date' => (string) $row->created_at, 'status_label' => $row->status === 'in_progress' ? 'Recibido, por revisar' : 'Solicitado, sin recibir', 'destination' => '/tax/incidents?incident='.$row->id]);
        $unsupported = DB::table('purchase_documents as document')->join('companies', 'companies.id', '=', 'document.company_id')->whereIn('document.company_id', $ids)->where('document.status', 'approved')
            ->whereBetween('document.document_date', [$from, $to])->whereNotExists(fn ($query) => $query->selectRaw('1')->from('purchase_document_files')->whereColumn('purchase_document_files.purchase_document_id', 'document.id'))
            ->get(['document.id', 'document.number', 'document.supplier_name', 'document.document_date', 'companies.name as company_name', 'companies.public_id'])
            ->map(fn (stdClass $row): array => ['key' => 'purchase:'.$row->id, 'company' => (string) $row->company_name, 'type' => 'Factura de compra', 'description' => ($row->supplier_name ?? 'Proveedor').' · '.$row->number, 'source' => 'Compra sin soporte adjunto', 'date' => (string) $row->document_date, 'status_label' => 'Falta el documento', 'destination' => '/accounting/companies/'.$row->public_id.'/purchases/documents/'.$row->id]);

        return [...$requests->all(), ...$unsupported->all()];
    }

    /**
     * VAT per the books against the VAT ledger accounts configured for the company, with the documents that explain
     * the difference (not yet accounted). Nothing is balanced automatically.
     *
     * @param  list<int>  $companyIds
     * @return list<array<string, mixed>>
     */
    public function reconciliation(array $companyIds, int $year, int $month): array
    {
        $rows = [];
        foreach (DB::table('companies')->leftJoin('company_fiscal_profiles as profile', 'profile.company_id', '=', 'companies.id')->whereIn('companies.id', $companyIds === [] ? [-1] : $companyIds)
            ->orderBy('companies.name')->get(['companies.id', 'companies.name', 'companies.public_id', 'profile.vat_regime']) as $company) {
            if (($company->vat_regime ?? 'general') === 'exempt') {
                $rows[] = ['company' => (string) $company->name, 'status' => 'NOT_APPLICABLE', 'message' => 'Empresa exenta de IVA.', 'lines' => [], 'items' => [], 'destination' => null];

                continue;
            }
            try {
                $book = $this->vatBooks->book((int) $company->id, $year, $month);
                $ledger = $book['ledger'];
                $lines = [
                    ['concept' => 'IVA débito (ventas)', 'source_cents' => $book['settlement']['sales_vat_cents'], 'ledger_cents' => (int) $ledger['sales_vat_cents'], 'difference_cents' => (int) $ledger['sales_difference_cents'], 'account' => $ledger['payable_account'] ?? null],
                    ['concept' => 'IVA crédito (compras)', 'source_cents' => $book['settlement']['purchase_vat_cents'], 'ledger_cents' => (int) $ledger['purchase_vat_cents'], 'difference_cents' => (int) $ledger['purchase_difference_cents'], 'account' => $ledger['recoverable_account'] ?? null],
                ];
                $items = collect([...$book['sales'], ...$book['purchases']])->filter(fn (array $row): bool => collect($row['issues'])->contains('kind', 'accounting'))
                    ->map(fn (array $row): array => ['document' => trim(($row['series'] ?? '').' '.($row['number'] ?? '')), 'name' => $row['name'], 'source' => $row['source'], 'vat_cents' => $row['vat_cents'], 'issue' => collect($row['issues'])->firstWhere('kind', 'accounting')['message'] ?? 'Sin asiento']);
                $status = match (true) {
                    ! $ledger['configured'] => 'PENDING',
                    collect($lines)->every(fn (array $line): bool => $line['difference_cents'] === 0) => 'MATCHED',
                    default => 'DIFFERENCE',
                };
                $rows[] = ['company' => (string) $company->name, 'status' => $status, 'message' => ! $ledger['configured'] ? 'Asigna las cuentas de IVA en Parámetros fiscales.' : null, 'lines' => $lines, 'items' => $items->values()->all(), 'destination' => '/accounting/companies/'.$company->public_id.'/vat-books?year='.$year.'&month='.$month];
            } catch (Throwable $exception) {
                Log::warning('Tax reconciliation failed', ['company_id' => $company->id, 'error' => $exception->getMessage()]);
                $rows[] = ['company' => (string) $company->name, 'status' => 'ERROR', 'message' => 'No fue posible calcular la conciliación.', 'lines' => [], 'items' => [], 'destination' => null];
            }
        }

        return $rows;
    }

    /**
     * @param  list<int>  $companyIds
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function tasks(Organization $organization, User $user, array $companyIds, array $filters = []): array
    {
        $rows = $this->operations->tasks($organization->id, $this->supervisor->officeIds($companyIds), [...$filters, 'assigned_to' => $user->id], 500);
        $sources = DB::table('work_tasks')->whereIn('id', array_column($rows, 'id') ?: [-1])->get(['id', 'source_type', 'source_id'])->keyBy('id');

        return array_values(array_map(function (array $task) use ($sources): array {
            $source = $sources->get($task['id']);
            $continue = match ($source?->source_type) {
                'fiscal_obligations' => '/tax/obligations/'.$source->source_id,
                'fel_documents' => DB::table('fel_documents')->join('companies', 'companies.id', '=', 'fel_documents.company_id')->where('fel_documents.id', $source->source_id)->selectRaw("'/accounting/companies/' || companies.public_id || '/fel/' || fel_documents.id as path")->value('path'),
                default => $task['module_destination'] ?? $task['destination'],
            };

            return [...$task, 'continue_to' => $continue ?? $task['destination'], 'can_start' => in_array($task['status'], ['pending', 'changes_requested', 'blocked'], true) && $source?->source_type !== 'fiscal_obligations'];
        }, array_filter($rows, fn (array $task): bool => $task['company_id'] !== null && in_array($task['company_id'], $companyIds, true) && $task['module'] === 'tax')));
    }

    /**
     * Statutory, internal and review deadlines of the obligations, fiscal tasks and observations of the user.
     *
     * @param  list<int>  $companyIds
     * @return list<array<string, mixed>>
     */
    public function calendar(Organization $organization, User $user, array $companyIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $events = [];
        foreach ($this->obligations($organization->id, $companyIds, ['status' => 'all'], 500) as $obligation) {
            foreach ([['due_on', 'Vencimiento oficial', 'statutory'], ['internal_due_on', 'Límite interno de preparación', 'internal'], ['review_due_on', 'Límite de revisión', 'review']] as [$field, $label, $kind]) {
                $date = Carbon::parse($obligation[$field]);
                if ($date->between($from, $to) && ($kind === 'statutory' || ! in_array($obligation['status'], ['approved', 'completed'], true))) {
                    $events[] = ['date' => $obligation[$field], 'type' => $kind, 'title' => $obligation['name'].' · '.$obligation['company_name'], 'subtitle' => $label.' · '.$obligation['reference_period'].' · '.$obligation['status_label'], 'priority' => $obligation['overdue'] ? 'critical' : ($kind === 'statutory' ? 'high' : 'normal'), 'destination' => $obligation['destination']];
                }
            }
        }
        foreach ($this->tasks($organization, $user, $companyIds, ['status' => 'open']) as $task) {
            if ($task['due_at'] !== null && Carbon::parse($task['due_at'])->between($from, $to) && ! str_starts_with((string) $task['continue_to'], '/tax/obligations/')) {
                $events[] = ['date' => (string) $task['due_at'], 'type' => 'task', 'title' => $task['title'], 'subtitle' => $task['company_name'], 'priority' => $task['priority'], 'destination' => $task['continue_to']];
            }
        }
        usort($events, fn (array $a, array $b): int => strcmp((string) $a['date'], (string) $b['date']));

        return $events;
    }

    /**
     * Regulations published in the companies' normative library (the library is the source, not the AI).
     *
     * @param  list<int>  $companyIds
     * @return list<array<string, mixed>>
     */
    public function library(array $companyIds, ?string $search): array
    {
        return array_values(DB::table('operational_records')->join('companies', 'companies.id', '=', 'operational_records.company_id')->whereIn('operational_records.company_id', $companyIds === [] ? [-1] : $companyIds)
            ->where('operational_records.module', 'normativa')->whereIn('operational_records.status', ['approved', 'published', 'superseded'])
            ->when($search !== null && $search !== '', fn ($query) => $query->where(fn ($inner) => $inner->where('operational_records.title', 'ilike', '%'.$search.'%')->orWhere('operational_records.reference', 'ilike', '%'.$search.'%')))
            ->orderByDesc('operational_records.effective_on')->limit(200)
            ->get(['operational_records.id', 'operational_records.title', 'operational_records.reference', 'operational_records.record_type', 'operational_records.status', 'operational_records.effective_on', 'companies.name as company_name', 'companies.public_id'])
            ->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'title' => (string) $row->title, 'reference' => $row->reference, 'type' => (string) $row->record_type, 'status' => ['superseded' => 'Sustituida', 'approved' => 'Aprobada, sin publicar'][$row->status] ?? 'Vigente', 'effective_on' => (string) $row->effective_on, 'company' => (string) $row->company_name, 'destination' => '/accounting/companies/'.$row->public_id.'/dashboard?module=normativa'])->all());
    }

    /**
     * @param  list<int>  $companyIds
     * @param  PeriodOption|null  $period
     * @return array<string, mixed>
     */
    public function dashboard(Organization $organization, User $user, array $companyIds, ?array $period): array
    {
        [$year, $month] = $this->reference($period);
        $companies = $this->companies($organization, $companyIds, $period);
        $open = $this->obligations($organization->id, $companyIds, ['status' => 'open'], 500);
        $fel = $this->felIncidents($companyIds);
        $documents = $this->documents($organization->id, $companyIds, $year, $month);
        $observations = $this->observations->list($organization->id, $companyIds, ['status' => 'correctable', 'assigned_to' => $user->id]);
        $closedBooks = DB::table('vat_book_periods')->whereIn('company_id', $companyIds === [] ? [-1] : $companyIds)->where('status', 'closed')->get(['company_id', 'year', 'month'])->map(fn (stdClass $book): string => $book->company_id.'-'.$book->year.'-'.$book->month)->all();
        $vatPending = collect($open)->where('type', 'iva')->filter(fn (array $row): bool => ! in_array($row['company_id'].'-'.$row['reference_year'].'-'.$row['reference_month'], $closedBooks, true))->count();

        return [
            'metrics' => [
                'assigned_companies' => count($companyIds),
                'pending_obligations' => count($open),
                'upcoming_obligations' => collect($open)->filter(fn (array $row): bool => ! $row['overdue'] && $row['days_left'] <= 15)->count(),
                'pending_vat_books' => $vatPending,
                'fel_incidents' => count($fel),
                'missing_documents' => count($documents),
                'open_observations' => count($observations),
                'overdue_obligations' => collect($open)->where('overdue', true)->count(),
            ],
            'status_counts' => [
                'all' => count($companies),
                'in_progress' => collect($companies)->whereIn('status', ['in_progress', 'not_started'])->count(),
                'with_pending' => collect($companies)->where('status', 'with_pending_items')->count(),
                'upcoming' => collect($companies)->filter(fn (array $c): bool => $c['due_on'] !== null && ! $c['overdue'] && Carbon::parse($c['due_on'])->diffInDays(now()) <= 15)->count(),
                'overdue' => collect($companies)->where('overdue', true)->count(),
                'completed' => collect($companies)->where('status', 'completed')->count(),
            ],
            'companies' => $companies,
            'tasks' => array_slice($this->tasks($organization, $user, $companyIds, ['status' => 'open']), 0, 6),
            'obligations' => array_slice($open, 0, 6),
            'fel' => array_slice($fel, 0, 5),
            'documents' => array_slice($documents, 0, 5),
            'calendar' => array_slice($this->calendar($organization, $user, $companyIds, now()->startOfDay(), now()->addDays(15)->endOfDay()), 0, 6),
        ];
    }
}
