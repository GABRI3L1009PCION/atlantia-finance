<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Models\Organization;
use App\Models\User;
use App\Services\Accounting\AccountingDashboardService;
use App\Services\AccountingService;
use App\Services\WorkManagement\CompanyPeriodReviewService;
use App\Services\WorkManagement\CompanyPeriodStatusService;
use App\Services\WorkManagement\WorkAuthorizationService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Daily work desk of the accounting assistant: only the companies where the user is the active assistant, their own
 * tasks and observations, pending documents and reconciliations, stage progress and the submission to review.
 * Everything is read from the source modules; nothing is copied.
 *
 * @phpstan-import-type PeriodOption from OperationsService
 */
class AssistantService
{
    /** Module keys of the company workspace the assistant can jump to, with the permission that shows them. */
    public const MODULES = [
        'documentos' => ['Documentos', 'documents.view'],
        'ventas' => ['Ventas', 'sales.view'],
        'compras' => ['Compras', 'purchases.view'],
        'clientes' => ['Clientes', 'sales.view'],
        'proveedores' => ['Proveedores', 'purchases.view'],
        'cxc' => ['Cuentas por Cobrar', 'sales.view'],
        'cxp' => ['Cuentas por Pagar', 'purchases.view'],
        'bancos' => ['Bancos', 'banks.view'],
        'conciliacion' => ['Conciliación Bancaria', 'banks.view'],
        'caja' => ['Caja', 'operations.view'],
        'contabilidad' => ['Contabilidad General', 'journal.view'],
        'diario' => ['Libro Diario', 'journal.view'],
        'mayor' => ['Libro Mayor', 'ledger.view'],
        'balance' => ['Balance de Comprobación', 'trial_balance.view'],
        'inventario' => ['Inventario', 'operations.view'],
        'kardex' => ['Kardex', 'operations.view'],
        'activos-fijos' => ['Activos Fijos', 'operations.view'],
        'nomina' => ['Nómina', 'operations.view'],
        'proyectos' => ['Proyectos', 'operations.view'],
        'fel' => ['Facturación Electrónica', 'fel.view'],
        'libros-iva' => ['Libros IVA', 'vat_books.view'],
        'normativa' => ['Biblioteca Normativa', 'fiscal.view'],
    ];

    /** Quick actions and the permission each one needs. */
    public const QUICK_ACTIONS = [
        'upload_document' => ['Subir documento', 'documentos', 'documents.manage'],
        'new_purchase' => ['Nueva compra', 'compras', 'purchases.manage'],
        'new_sale' => ['Nueva venta', 'ventas', 'sales.manage'],
        'receipt' => ['Registrar cobro', 'cxc', 'sales.manage'],
        'payment' => ['Registrar pago', 'cxp', 'purchases.manage'],
        'reconcile' => ['Conciliar banco', 'conciliacion', 'banks.manage'],
        'new_entry' => ['Nuevo asiento', 'diario', 'journal.create'],
    ];

    private const PRIORITY_RANK = ['critical' => 0, 'high' => 1, 'normal' => 2, 'low' => 3];

    public function __construct(
        private WorkAuthorizationService $authorization,
        private SupervisorService $supervisor,
        private OperationsService $operations,
        private ReviewObservationService $observations,
        private CompanyPeriodProgressService $progress,
        private CompanyPeriodReadinessService $readiness,
        private CompanyPeriodReviewService $reviews,
        private AccountingDashboardService $dashboard,
        private AccountingService $accounting,
    ) {}

    /** @return list<int> */
    public function companyIds(Organization $organization, User $user): array
    {
        abort_unless($this->authorization->can($organization, $user, 'assistant.dashboard.view'), 403);
        $isOwner = DB::table('organization_user')->where('organization_user.organization_id', $organization->id)->where('user_id', $user->id)->where('role', 'owner')->where('status', 'active')->exists();

        $query = $isOwner
            ? DB::table('companies')->where('companies.organization_id', $organization->id)->pluck('id')
            : DB::table('company_assignments')->where('company_assignments.organization_id', $organization->id)->where('user_id', $user->id)
                ->where('assignment_role', 'accounting_assistant')->where('status', 'active')->whereNull('deleted_at')->pluck('company_id');

        return array_values(array_unique(array_map('intval', $query->all())));
    }

    /** @param list<int> $companyIds */
    public function assertCompany(array $companyIds, int $companyId): void
    {
        abort_unless(in_array($companyId, $companyIds, true), 403);
    }

    /**
     * Assigned companies with stage progress, the user's pending items and the next action.
     *
     * @param  list<int>  $companyIds
     * @param  PeriodOption|null  $period
     * @return list<array<string, mixed>>
     */
    public function companies(Organization $organization, User $user, array $companyIds, ?array $period): array
    {
        $myTasks = DB::table('work_tasks')->where('work_tasks.organization_id', $organization->id)->whereIn('company_id', $companyIds === [] ? [-1] : $companyIds)->where('assigned_to', $user->id)
            ->whereNull('deleted_at')->whereNotIn('status', ['completed', 'approved', 'cancelled'])->groupBy('company_id')->selectRaw('company_id, CAST(COUNT(*) AS BIGINT) as total')->pluck('total', 'company_id');
        $myObservations = DB::table('review_observations')->where('review_observations.organization_id', $organization->id)->whereIn('company_id', $companyIds === [] ? [-1] : $companyIds)->where('assigned_to', $user->id)
            ->whereIn('status', ReviewObservationService::CORRECTABLE)->groupBy('company_id')->selectRaw('company_id, CAST(COUNT(*) AS BIGINT) as total')->pluck('total', 'company_id');

        return array_map(function (array $company) use ($organization, $myTasks, $myObservations): array {
            $progress = $this->progress->forPeriod($organization->id, $company['id'], $company['period_id']);
            $observations = (int) ($myObservations[$company['id']] ?? 0);
            $pending = (int) ($myTasks[$company['id']] ?? 0) + $observations;

            return [
                'id' => $company['id'],
                'public_id' => $company['public_id'],
                'name' => $company['name'],
                'tax_id' => $company['tax_id'],
                'accountant' => $company['accountant'],
                'period_id' => $company['period_id'],
                'period_name' => $company['period_name'],
                'status' => $company['status'],
                'status_label' => $company['status_label'],
                'overdue' => $company['overdue'],
                'blocked' => $company['blocked'],
                'due_at' => $company['due_at'],
                'progress' => $progress['progress'],
                'stages' => $progress['stages'],
                'pending_items' => $pending,
                'observations' => $observations,
                'next_action' => match (true) {
                    $observations > 0 => 'Corregir observaciones',
                    in_array($company['status'], ['ready_for_review', 'in_review', 'first_level_approved'], true) => 'En revisión del contador',
                    in_array($company['status'], ['approved', 'fiscal_pending', 'ready_to_close', 'closed'], true) => 'Revisión aprobada',
                    $pending > 0 => 'Completar tareas pendientes',
                    default => 'Enviar a revisión',
                },
                'can_submit' => $company['period_id'] !== null && in_array($company['status'], ['not_started', 'in_progress', 'changes_requested'], true),
                'destination' => '/accounting/companies/'.$company['public_id'].'/dashboard?return_to=assistant-dashboard',
            ];
        }, $this->supervisor->companies($organization, $companyIds, $period));
    }

    /**
     * @param  list<int>  $companyIds
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function tasks(Organization $organization, User $user, array $companyIds, array $filters = []): array
    {
        $rows = $this->operations->tasks($organization->id, $this->supervisor->officeIds($companyIds), [...$filters, 'assigned_to' => $user->id], 500);

        return array_values(array_map(fn (array $task): array => [
            ...$task,
            'priority_rank' => self::PRIORITY_RANK[$task['priority'] === 'urgent' ? 'critical' : $task['priority']] ?? 2,
            'can_start' => in_array($task['status'], ['pending', 'changes_requested', 'blocked'], true),
            'can_submit' => $task['status'] === 'in_progress',
            'continue_to' => $task['module_destination'] ?? $task['destination'],
        ], array_filter($rows, fn (array $task): bool => $task['company_id'] !== null && in_array($task['company_id'], $companyIds, true))));
    }

    /**
     * @param  list<int>  $companyIds
     * @param  PeriodOption|null  $period
     * @return array<string, mixed>
     */
    public function dashboard(Organization $organization, User $user, array $companyIds, ?array $period): array
    {
        $companies = $this->companies($organization, $user, $companyIds, $period);
        $open = $this->tasks($organization, $user, $companyIds, ['status' => 'open']);
        $documents = $this->safe(fn (): array => $this->pendingDocuments($organization->id, $companyIds));
        $reconciliations = $this->safe(fn (): array => $this->reconciliations($companyIds, $period));
        $observations = $this->observations->list($organization->id, $companyIds, ['status' => 'correctable', 'assigned_to' => $user->id]);
        $taxItems = $this->safe(fn (): array => array_values(array_filter($this->supervisor->obligationRows($companyIds, now()->startOfDay(), now()->addDays(15)), fn (array $row): bool => $row['status'] !== 'completed')));
        $today = now()->endOfDay();

        return [
            'metrics' => [
                'assigned_companies' => count($companies),
                'pending_tasks' => count($open),
                'pending_documents' => $documents === null ? null : count($documents),
                'pending_reconciliations' => $reconciliations === null ? null : count($reconciliations),
                'received_observations' => count($observations),
                'overdue_tasks' => count(array_filter($open, fn (array $task): bool => $task['overdue_days'] > 0)),
                'pending_tax_items' => $taxItems === null ? null : count($taxItems),
            ],
            'status_counts' => [
                'all' => count($companies),
                'in_progress' => count(array_filter($companies, fn (array $c): bool => in_array($c['status'], ['not_started', 'in_progress'], true))),
                'with_observations' => count(array_filter($companies, fn (array $c): bool => $c['observations'] > 0 || $c['status'] === 'changes_requested')),
                'in_review' => count(array_filter($companies, fn (array $c): bool => in_array($c['status'], ['ready_for_review', 'in_review', 'first_level_approved'], true))),
                'closed' => count(array_filter($companies, fn (array $c): bool => $c['status'] === 'closed')),
            ],
            'companies' => $companies,
            'today_tasks' => array_slice(array_values(array_filter($open, fn (array $task): bool => $task['due_at'] === null || Carbon::parse($task['due_at'])->lte($today->copy()->addDays(3)))), 0, 8),
            'observations' => array_slice($observations, 0, 6),
            'documents' => $documents === null ? null : array_slice($documents, 0, 6),
            'reconciliations' => $reconciliations === null ? null : array_slice($reconciliations, 0, 6),
            'calendar' => array_slice($this->calendar($organization, $user, $companyIds, now()->startOfDay(), now()->addDays(10)->endOfDay()), 0, 6),
            'quick_actions' => $this->quickActions($organization, $user),
        ];
    }

    /**
     * Documents still to be received or processed: open document requests and uploaded files not processed.
     *
     * @param  list<int>  $companyIds
     * @return list<array<string, mixed>>
     */
    public function pendingDocuments(int $organizationId, array $companyIds): array
    {
        $ids = $companyIds === [] ? [-1] : $companyIds;
        $requests = DB::table('operational_incidents as incident')->join('companies', 'companies.id', '=', 'incident.company_id')
            ->where('incident.organization_id', $organizationId)->whereIn('incident.company_id', $ids)->where('incident.type', 'document')->whereIn('incident.status', ['open', 'in_progress', 'blocked'])
            ->orderBy('incident.created_at')->get(['incident.id', 'incident.title', 'incident.description', 'incident.created_at', 'incident.status', 'companies.id as company_id', 'companies.name as company_name', 'companies.public_id'])
            ->map(fn (object $row): array => ['key' => 'request:'.$row->id, 'company_id' => (int) $row->company_id, 'company' => (string) $row->company_name, 'type' => 'Solicitud', 'description' => (string) ($row->description ?: $row->title), 'requested_at' => (string) $row->created_at, 'status' => 'requested', 'status_label' => 'Solicitado, sin recibir', 'source' => 'Solicitud al cliente', 'destination' => '/assistant/incidents?incident='.$row->id]);
        $files = collect($this->supervisor->documents($companyIds))->where('status', '!=', 'processed')
            ->map(fn (array $file): array => ['key' => 'file:'.$file['category'].':'.$file['id'], 'company_id' => null, 'company' => $file['company'], 'type' => $file['category'], 'description' => $file['name'], 'requested_at' => $file['received_at'], 'status' => $file['status'], 'status_label' => $file['status'] === 'pending' ? 'Recibido, sin procesar' : $file['status_label'], 'source' => 'Documento cargado', 'destination' => null]);

        return [...$requests->all(), ...$files->all()];
    }

    /**
     * Banks with movements to reconcile and receivables/payables that differ from the ledger. Differences are shown,
     * never adjusted.
     *
     * @param  list<int>  $companyIds
     * @param  PeriodOption|null  $period
     * @return list<array<string, mixed>>
     */
    public function reconciliations(array $companyIds, ?array $period): array
    {
        $rows = [];
        foreach ($this->supervisor->banks($companyIds) as $bank) {
            if ($bank['status'] !== 'reconciled') {
                $rows[] = ['key' => 'bank:'.$bank['id'], 'company' => $bank['company'], 'account' => $bank['bank'], 'type' => 'Banco', 'auxiliary_cents' => $bank['bank_balance_cents'], 'ledger_cents' => $bank['ledger_balance_cents'], 'difference_cents' => $bank['difference_cents'], 'status' => $bank['status'], 'status_label' => $bank['status_label'], 'destination' => $bank['destination']];
            }
        }
        $periods = DB::table('accounting_periods')->whereIn('company_id', $companyIds === [] ? [-1] : $companyIds)
            ->when($period !== null, fn ($query) => $query->where('name', $period['name'] ?? null), fn ($query) => $query->whereNull('closed_at'))
            ->orderByDesc('starts_on')->get(['id', 'company_id'])->unique('company_id');
        foreach ($periods as $row) {
            $company = DB::table('companies')->where('id', $row->company_id)->first(['name', 'public_id']);
            try {
                $data = $this->dashboard->forCompany((int) $row->company_id, (int) $row->id);
                foreach ($data['reconciliations'] as $item) {
                    if (in_array($item['label'], ['CxC', 'CxP'], true) && (int) $item['difference_cents'] !== 0) {
                        $rows[] = ['key' => $item['label'].':'.$row->company_id, 'company' => (string) $company?->name, 'account' => $item['label'] === 'CxC' ? 'Clientes (1103)' : 'Proveedores (2101/2102)', 'type' => $item['label'], 'auxiliary_cents' => $item['auxiliary_cents'], 'ledger_cents' => $item['ledger_cents'], 'difference_cents' => $item['difference_cents'], 'status' => 'difference', 'status_label' => 'Con diferencia', 'destination' => '/accounting/companies/'.$company?->public_id.($item['label'] === 'CxC' ? '/receivables' : '/payables')];
                    }
                }
            } catch (Throwable $exception) {
                Log::warning('Assistant reconciliation failed', ['company_id' => $row->company_id, 'error' => $exception->getMessage()]);
                $rows[] = ['key' => 'error:'.$row->company_id, 'company' => (string) $company?->name, 'account' => 'CxC / CxP', 'type' => 'Auxiliares', 'auxiliary_cents' => null, 'ledger_cents' => null, 'difference_cents' => null, 'status' => 'error', 'status_label' => 'No fue posible verificar', 'destination' => null];
            }
        }

        return $rows;
    }

    /**
     * Everything the assistant has pending, ordered by priority and due date.
     *
     * @param  list<int>  $companyIds
     * @param  PeriodOption|null  $period
     * @return list<array<string, mixed>>
     */
    public function pending(Organization $organization, User $user, array $companyIds, ?array $period): array
    {
        $items = [];
        foreach ($this->tasks($organization, $user, $companyIds, ['status' => 'open']) as $task) {
            $items[] = ['kind' => 'Tarea', 'title' => $task['title'], 'company' => $task['company_name'], 'priority' => $task['priority'] === 'urgent' ? 'critical' : $task['priority'], 'due_at' => $task['due_at'], 'status_label' => $task['status_label'], 'destination' => $task['continue_to']];
        }
        foreach ($this->observations->list($organization->id, $companyIds, ['status' => 'correctable', 'assigned_to' => $user->id]) as $observation) {
            $items[] = ['kind' => 'Observación', 'title' => $observation['message'], 'company' => $observation['company_name'], 'priority' => $observation['severity'] === 'medium' ? 'normal' : $observation['severity'], 'due_at' => $observation['due_at'], 'status_label' => $observation['status_label'], 'destination' => '/assistant/observations?observation='.$observation['id']];
        }
        foreach ($this->pendingDocuments($organization->id, $companyIds) as $document) {
            $items[] = ['kind' => 'Documento', 'title' => $document['description'], 'company' => $document['company'], 'priority' => $document['status'] === 'requested' ? 'high' : 'normal', 'due_at' => null, 'status_label' => $document['status_label'], 'destination' => $document['destination'] ?? '/assistant/documents'];
        }
        foreach ($this->reconciliations($companyIds, $period) as $row) {
            $items[] = ['kind' => 'Conciliación', 'title' => $row['type'].' · '.$row['account'], 'company' => $row['company'], 'priority' => 'high', 'due_at' => null, 'status_label' => $row['status_label'], 'destination' => $row['destination'] ?? '/assistant/reconciliations'];
        }
        foreach (DB::table('journal_entries')->join('accounting_periods', 'accounting_periods.id', '=', 'journal_entries.accounting_period_id')->join('companies', 'companies.id', '=', 'accounting_periods.company_id')
            ->whereIn('accounting_periods.company_id', $companyIds === [] ? [-1] : $companyIds)->where('journal_entries.created_by', $user->id)->whereIn('journal_entries.status', ['draft', 'rejected'])
            ->get(['journal_entries.public_id', 'journal_entries.description', 'journal_entries.status', 'journal_entries.rejection_reason', 'companies.name', 'companies.public_id as company_public_id']) as $entry) {
            $items[] = ['kind' => 'Asiento', 'title' => $entry->description, 'company' => $entry->name, 'priority' => $entry->status === 'rejected' ? 'high' : 'normal', 'due_at' => null, 'status_label' => $entry->status === 'rejected' ? 'Rechazado: '.$entry->rejection_reason : 'Borrador sin enviar', 'destination' => '/accounting/companies/'.$entry->company_public_id.'?entry='.$entry->public_id];
        }
        foreach ($this->supervisor->obligationRows($companyIds, now()->startOfDay(), now()->addDays(15)) as $row) {
            if ($row['status'] !== 'completed') {
                $items[] = ['kind' => 'Fiscal', 'title' => $row['name'].' · '.$row['period'], 'company' => $row['company_name'], 'priority' => $row['status'] === 'overdue' ? 'critical' : 'high', 'due_at' => $row['due_on'], 'status_label' => $row['status_label'], 'destination' => '/assistant/calendar'];
            }
        }

        usort($items, fn (array $a, array $b): int => [self::PRIORITY_RANK[$a['priority']] ?? 2, $a['due_at'] ?? '9999'] <=> [self::PRIORITY_RANK[$b['priority']] ?? 2, $b['due_at'] ?? '9999']);

        return $items;
    }

    /**
     * Tasks, observations, fiscal due dates and review targets of the assistant in a date range.
     *
     * @param  list<int>  $companyIds
     * @return list<array<string, mixed>>
     */
    public function calendar(Organization $organization, User $user, array $companyIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $events = [];
        foreach ($this->tasks($organization, $user, $companyIds, ['status' => 'open']) as $task) {
            if ($task['due_at'] !== null && Carbon::parse($task['due_at'])->between($from, $to)) {
                $events[] = ['date' => (string) $task['due_at'], 'type' => 'task', 'title' => $task['title'], 'subtitle' => $task['company_name'], 'priority' => $task['priority'], 'destination' => $task['continue_to']];
            }
        }
        foreach ($this->observations->list($organization->id, $companyIds, ['status' => 'correctable', 'assigned_to' => $user->id]) as $observation) {
            if ($observation['due_at'] !== null && Carbon::parse($observation['due_at'])->between($from, $to)) {
                $events[] = ['date' => (string) $observation['due_at'], 'type' => 'observation', 'title' => 'Corregir: '.$observation['message'], 'subtitle' => $observation['company_name'], 'priority' => 'high', 'destination' => '/assistant/observations?observation='.$observation['id']];
            }
        }
        foreach ($this->supervisor->obligationRows($companyIds, $from, $to) as $row) {
            $events[] = ['date' => $row['due_on'], 'type' => 'fiscal', 'title' => $row['name'], 'subtitle' => $row['company_name'].' · '.$row['period'].' · '.$row['status_label'], 'priority' => $row['status'] === 'overdue' ? 'high' : 'normal', 'destination' => null];
        }
        foreach (DB::table('accounting_periods')->join('companies', 'companies.id', '=', 'accounting_periods.company_id')->whereIn('accounting_periods.company_id', $companyIds === [] ? [-1] : $companyIds)->whereNull('accounting_periods.closed_at')->get(['accounting_periods.name', 'accounting_periods.ends_on', 'companies.name as company_name']) as $period) {
            $deadline = CompanyPeriodStatusService::deadlineFor((string) $period->ends_on);
            if ($deadline->between($from, $to)) {
                $events[] = ['date' => $deadline->toDateString(), 'type' => 'closing', 'title' => 'Límite para enviar '.$period->name, 'subtitle' => $period->company_name, 'priority' => 'high', 'destination' => null];
            }
        }
        usort($events, fn (array $a, array $b): int => strcmp((string) $a['date'], (string) $b['date']));

        return $events;
    }

    /**
     * Incidents of the assistant's companies where they are the responsible.
     *
     * @param  list<int>  $companyIds
     * @return list<array<string, mixed>>
     */
    public function incidents(Organization $organization, User $user, array $companyIds): array
    {
        return array_values(array_filter(
            $this->operations->incidents($organization->id, $this->supervisor->officeIds($companyIds)),
            fn (array $incident): bool => $incident['company_id'] !== null && in_array($incident['company_id'], $companyIds, true) && $incident['responsible_id'] === $user->id,
        ));
    }

    /**
     * What the assistant did recently (read from the audit log, no copy).
     *
     * @return list<array<string, mixed>>
     */
    public function activity(int $organizationId, User $user): array
    {
        $labels = SupervisorService::auditModules();

        return array_values(DB::table('audit_logs')->where('audit_logs.organization_id', $organizationId)->where('user_id', $user->id)->orderByDesc('id')->limit(150)
            ->get(['id', 'action', 'auditable_type', 'auditable_id', 'occurred_at'])
            ->map(fn (object $row): array => ['id' => (int) $row->id, 'action' => (string) $row->action, 'module' => $labels[$row->auditable_type] ?? (string) $row->auditable_type, 'record' => $row->auditable_type.' #'.$row->auditable_id, 'occurred_at' => Carbon::parse($row->occurred_at)->toIso8601String()])->all());
    }

    /**
     * Sends the company period to the accountant in charge after the readiness check. With blockers it is refused,
     * unless the user holds the override permission and gives a reason (audited).
     *
     * @return array{review_id: int, overridden: bool}
     */
    public function submit(Organization $organization, User $user, int $companyId, int $periodId, ?string $overrideReason = null): array
    {
        abort_unless($this->authorization->can($organization, $user, 'reviews.submit'), 403);
        $check = $this->readiness->check($organization->id, $companyId, $periodId);
        $overridden = false;
        if (! $check['ready']) {
            $canOverride = $this->authorization->can($organization, $user, 'reviews.submit_override') && trim((string) $overrideReason) !== '';
            if (! $canOverride) {
                throw ValidationException::withMessages(['submit' => 'No se puede enviar: '.count($check['blockers']).' bloqueo(s). '.implode(' · ', array_map(fn (array $blocker): string => $blocker['label'], $check['blockers']))]);
            }
            $overridden = true;
        }

        $reviewer = DB::table('company_assignments')->where('company_id', $companyId)->where('assignment_role', 'accountant_in_charge')->where('status', 'active')->whereNull('deleted_at')->orderByDesc('is_primary')->value('user_id');
        $reviewId = $this->reviews->submit($organization, $user, $companyId, $periodId, $reviewer === null ? null : (int) $reviewer);
        $officeId = (int) DB::table('companies')->where('id', $companyId)->value('office_id');
        $this->accounting->audit($organization, $user, $overridden ? 'company.submitted_for_review_with_override' : 'company.submitted_for_review', 'company_period_reviews', $reviewId, [
            'company_id' => $companyId,
            'period_id' => $periodId,
            'blockers' => array_map(fn (array $blocker): string => $blocker['label'], $check['blockers']),
            'warnings' => array_map(fn (array $warning): string => $warning['label'], $check['warnings']),
            'override_reason' => $overridden ? $overrideReason : null,
        ], $officeId);

        return ['review_id' => $reviewId, 'overridden' => $overridden];
    }

    /**
     * Module links the user can open (permission), for the sidebar and module picker. Plan gating is applied in the
     * client with the plan features, as in the company menu.
     *
     * @return list<array{key: string, label: string}>
     */
    public function visibleModules(Organization $organization, User $user): array
    {
        $rows = [];
        foreach (self::MODULES as $key => [$label, $permission]) {
            if ($this->authorization->can($organization, $user, $permission)) {
                $rows[] = ['key' => $key, 'label' => $label];
            }
        }

        return $rows;
    }

    /** @return list<array{key: string, label: string, module: string}> */
    public function quickActions(Organization $organization, User $user): array
    {
        $rows = [];
        foreach (self::QUICK_ACTIONS as $key => [$label, $module, $permission]) {
            if ($this->authorization->can($organization, $user, $permission)) {
                $rows[] = ['key' => $key, 'label' => $label, 'module' => $module];
            }
        }

        return $rows;
    }

    public static function moduleDestination(string $companyPublicId, string $module): string
    {
        $base = '/accounting/companies/'.$companyPublicId;
        $dashboard = $base.'/dashboard?return_to=assistant-dashboard&module=';

        return match ($module) {
            'ventas' => $base.'/sales',
            'compras' => $base.'/purchases',
            'clientes' => $base.'/customers',
            'proveedores' => $base.'/suppliers',
            'cxc' => $base.'/receivables',
            'cxp' => $base.'/payables',
            'bancos' => $base.'/banks',
            'contabilidad' => $base,
            'diario' => $base.'#libro-diario',
            'mayor' => $base.'#libro-mayor',
            'balance' => $base.'#balance-comprobacion',
            'fel' => $base.'/fel',
            'libros-iva' => $base.'/vat-books',
            default => $dashboard.$module,
        };
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|null
     */
    private function safe(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $exception) {
            Log::warning('Assistant dashboard block failed', ['error' => $exception->getMessage()]);

            return null;
        }
    }
}
