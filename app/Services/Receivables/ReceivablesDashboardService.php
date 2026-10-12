<?php

declare(strict_types=1);

namespace App\Services\Receivables;

use App\Models\Organization;
use App\Models\User;
use App\Services\Operations\OperationsService;
use App\Services\Operations\SupervisorService;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use stdClass;
use Throwable;

/**
 * Reads and automatic work of the receivables panel. Money is always per company (never added across client
 * companies); anything that cannot be computed is reported as unavailable instead of zero. Automatic tasks and
 * incidents carry an automation key, so repeated syncs never duplicate them, and they close themselves when the
 * underlying problem is gone.
 */
class ReceivablesDashboardService
{
    public const COMPANY_LABELS = [
        'no_activity' => 'Sin movimiento', 'current' => 'Al día', 'with_upcoming_due' => 'Por vencer', 'with_overdue' => 'Con vencidos', 'collection_in_progress' => 'En gestión',
        'with_disputes' => 'Con disputas', 'with_unapplied_receipts' => 'Cobros sin aplicar', 'reconciliation_difference' => 'Diferencia con Mayor', 'attention_required' => 'Atención', 'error' => 'No disponible',
    ];

    public const INCIDENT_SOURCES = ['promises_to_pay', 'receivable_disputes', 'treasury_receipts', 'sales_invoices', 'sales_customers', 'companies'];

    public function __construct(
        private ReceivableService $receivables,
        private PromiseToPayService $promises,
        private CollectionActivityService $activities,
        private ReceivablesLedgerReconciliationService $reconciliation,
        private OperationsService $operations,
        private SupervisorService $supervisor,
    ) {}

    /**
     * Companies where the user is the active receivables assignee (or an assistant with receivables permission).
     *
     * @return list<int>
     */
    public function companyIds(Organization $organization, User $user): array
    {
        $isOwner = DB::table('organization_user')->where('organization_user.organization_id', $organization->id)->where('user_id', $user->id)->where('role', 'owner')->where('status', 'active')->exists();
        $query = $isOwner
            ? DB::table('companies')->where('companies.organization_id', $organization->id)->pluck('id')
            : DB::table('company_assignments')->where('company_assignments.organization_id', $organization->id)->where('user_id', $user->id)
                ->whereIn('assignment_role', ['accounts_receivable', 'accounting_assistant'])->where('status', 'active')->whereNull('deleted_at')->pluck('company_id');

        return array_values(array_unique(array_map('intval', $query->all())));
    }

    /**
     * @param  list<int>  $companyIds
     * @return list<array<string, mixed>>
     */
    public function companies(Organization $organization, array $companyIds): array
    {
        $ids = $companyIds === [] ? [-1] : $companyIds;
        try {
            $documents = collect($this->receivables->documents($companyIds));
        } catch (Throwable $exception) {
            Log::warning('Receivables portfolio failed', ['error' => $exception->getMessage()]);
            $documents = null;
        }
        $count = fn ($query) => $query->groupBy('company_id')->selectRaw('company_id, CAST(COUNT(*) AS BIGINT) as total')->pluck('total', 'company_id');
        $unapplied = $count(DB::table('treasury_receipts')->whereIn('company_id', $ids)->where('unapplied_cents', '>', 0));
        $disputes = $count(DB::table('receivable_disputes')->whereIn('company_id', $ids)->whereIn('status', ['open', 'under_review']));
        $broken = $count(DB::table('promises_to_pay')->whereIn('company_id', $ids)->where('status', 'broken')->where('updated_at', '>=', now()->subDays(30)));
        $followUps = $count(DB::table('collection_activities')->whereIn('company_id', $ids)->where('status', 'open'));
        $incidents = $count($this->incidentQuery($organization->id, $companyIds)->whereIn('status', ['open', 'in_progress', 'blocked']));

        return array_values(DB::table('companies')->whereIn('id', $ids)->orderBy('name')->get(['id', 'public_id', 'name', 'tax_id'])->map(function (stdClass $company) use ($documents, $unapplied, $disputes, $broken, $followUps, $incidents): array {
            $base = ['id' => (int) $company->id, 'public_id' => (string) $company->public_id, 'name' => (string) $company->name, 'tax_id' => (string) $company->tax_id,
                'unapplied_receipts' => (int) ($unapplied[$company->id] ?? 0), 'incidents' => (int) ($incidents[$company->id] ?? 0), 'destination' => '/receivables/companies/'.$company->id];
            if ($documents === null) {
                return [...$base, 'customers_with_balance' => null, 'open_documents' => null, 'overdue_documents' => null, 'total_cents' => null, 'current_cents' => null, 'overdue_cents' => null, 'overdue_customers' => null, 'reconciliation' => null, 'status' => 'error', 'status_label' => self::COMPANY_LABELS['error'], 'error' => 'Información de cartera no disponible.'];
            }
            $rows = $documents->where('company_id', (int) $company->id);
            $overdue = $rows->where('overdue', true);
            $reconciliation = $this->reconciliation->compute((int) $company->id, $this->receivables->today((int) $company->id));
            $status = match (true) {
                $reconciliation['status'] === 'difference' => 'reconciliation_difference',
                (int) ($broken[$company->id] ?? 0) > 0 => 'attention_required',
                (int) ($disputes[$company->id] ?? 0) > 0 => 'with_disputes',
                (int) ($unapplied[$company->id] ?? 0) > 0 => 'with_unapplied_receipts',
                $overdue->isNotEmpty() && (int) ($followUps[$company->id] ?? 0) > 0 => 'collection_in_progress',
                $overdue->isNotEmpty() => 'with_overdue',
                $rows->contains(fn (array $row): bool => $row['days_to_due'] <= 7) => 'with_upcoming_due',
                $rows->isNotEmpty() => 'current',
                default => 'no_activity',
            };

            return [
                ...$base,
                'customers_with_balance' => $rows->pluck('customer_id')->unique()->count(),
                'open_documents' => $rows->count(),
                'overdue_documents' => $overdue->count(),
                'total_cents' => (int) $rows->sum('outstanding_cents'),
                'current_cents' => (int) $rows->where('overdue', false)->sum('outstanding_cents'),
                'overdue_cents' => (int) $overdue->sum('outstanding_cents'),
                'overdue_customers' => $overdue->pluck('customer_id')->unique()->count(),
                'reconciliation' => ['status' => $reconciliation['status'], 'status_label' => $reconciliation['status_label'], 'difference_cents' => $reconciliation['difference_cents']],
                'status' => $status,
                'status_label' => self::COMPANY_LABELS[$status],
                'error' => null,
            ];
        })->all());
    }

    /**
     * Treasury receipts (the same records Treasury registers) with their application to invoices.
     *
     * @param  list<int>  $companyIds
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function receipts(array $companyIds, array $filters = []): array
    {
        return array_values(DB::table('treasury_receipts as receipt')->join('companies', 'companies.id', '=', 'receipt.company_id')->leftJoin('bank_accounts', 'bank_accounts.id', '=', 'receipt.bank_account_id')
            ->whereIn('receipt.company_id', $companyIds === [] ? [-1] : $companyIds)
            ->when(! empty($filters['unapplied']), fn ($query) => $query->where('receipt.unapplied_cents', '>', 0))
            ->when(! empty($filters['company_id']), fn ($query) => $query->where('receipt.company_id', (int) $filters['company_id']))
            ->orderByDesc('receipt.received_on')->orderByDesc('receipt.id')->limit(300)
            ->get(['receipt.*', 'companies.name as company_name', 'bank_accounts.bank_name', 'bank_accounts.last_four'])
            ->map(fn (stdClass $row): array => [
                'id' => (int) $row->id, 'company_id' => (int) $row->company_id, 'company' => (string) $row->company_name,
                'customer' => (string) $row->customer_name, 'identified' => $row->customer_name !== 'Cliente sin identificar',
                'received_on' => (string) $row->received_on, 'amount_cents' => (int) $row->amount_cents, 'applied_cents' => (int) $row->applied_cents, 'unapplied_cents' => (int) $row->unapplied_cents,
                'account' => $row->bank_name === null ? 'Caja' : $row->bank_name.' ****'.$row->last_four, 'reference' => (string) $row->reference, 'status' => (string) $row->status,
                'status_label' => ['applied' => 'Aplicado', 'partially_applied' => 'Aplicado parcialmente', 'unapplied' => 'Sin aplicar'][$row->status] ?? $row->status,
                'applications' => DB::table('sales_receipts')->join('sales_invoices', 'sales_invoices.id', '=', 'sales_receipts.sales_invoice_id')->where('sales_receipts.treasury_receipt_id', $row->id)->whereNull('sales_receipts.voided_at')
                    ->get(['sales_invoices.id', 'sales_invoices.number', 'sales_receipts.amount_cents'])->map(fn (stdClass $item): array => ['invoice_id' => (int) $item->id, 'document' => 'Factura #'.$item->number, 'amount_cents' => (int) $item->amount_cents])->all(),
            ])->all());
    }

    /**
     * Deposits Treasury could not identify (bank side), so receivables can help investigate them.
     *
     * @param  list<int>  $companyIds
     * @return list<array<string, mixed>>
     */
    public function unidentifiedDeposits(array $companyIds): array
    {
        return array_values(DB::table('bank_transactions')->join('bank_accounts', 'bank_accounts.id', '=', 'bank_transactions.bank_account_id')->join('companies', 'companies.id', '=', 'bank_accounts.company_id')
            ->whereIn('bank_accounts.company_id', $companyIds === [] ? [-1] : $companyIds)->where('bank_transactions.flow', 'inflow')->where('bank_transactions.reconciliation_status', '!=', 'matched')
            ->orderByDesc('bank_transactions.transaction_date')->limit(100)
            ->get(['bank_transactions.id', 'bank_transactions.transaction_date', 'bank_transactions.reference', 'bank_transactions.description', 'bank_transactions.amount_cents', 'bank_transactions.reconciliation_status', 'bank_accounts.bank_name', 'bank_accounts.last_four', 'companies.name as company_name', 'companies.id as company_id'])
            ->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'company_id' => (int) $row->company_id, 'company' => (string) $row->company_name, 'date' => (string) $row->transaction_date, 'reference' => (string) $row->reference, 'description' => (string) $row->description,
                'amount_cents' => (int) $row->amount_cents, 'account' => $row->bank_name.' ****'.$row->last_four, 'status_label' => $row->reconciliation_status === 'unmatched' ? 'Sin identificar' : 'Pendiente en Tesorería'])->all());
    }

    /**
     * @param  list<int>  $companyIds
     */
    public function incidentQuery(int $organizationId, array $companyIds): Builder
    {
        return DB::table('operational_incidents')->where('operational_incidents.organization_id', $organizationId)->whereIn('company_id', $companyIds === [] ? [-1] : $companyIds)
            ->where(fn ($query) => $query->where('type', 'receivables')->orWhere('automation_key', 'like', 'unapplied_receipt:%'));
    }

    /**
     * Why an automatic incident cannot be closed yet (its source problem still exists), or null.
     */
    public function resolutionBlocker(stdClass $incident): ?string
    {
        $key = (string) $incident->automation_key;
        $id = (int) Str::afterLast($key, ':');

        return match (true) {
            str_starts_with($key, 'unapplied_receipt:') => (int) DB::table('treasury_receipts')->where('id', $id)->value('unapplied_cents') > 0 ? 'El cobro aún tiene saldo sin aplicar.' : null,
            str_starts_with($key, 'dispute:') => DB::table('receivable_disputes')->where('id', $id)->whereIn('status', ['open', 'under_review'])->exists() ? 'Cierra primero la disputa con su resolución.' : null,
            str_starts_with($key, 'ar_difference:') => $this->reconciliation->compute($id, $this->receivables->today($id))['status'] === 'difference' ? 'CxC aún no coincide con el Mayor; corrige el origen de la diferencia.' : null,
            str_starts_with($key, 'broken_promise:'), str_starts_with($key, 'overdue_no_followup:') => DB::table('collection_activities')->where('sales_customer_id', str_starts_with($key, 'broken_promise:') ? DB::table('promises_to_pay')->where('id', $id)->value('sales_customer_id') : $id)
                ->where('performed_at', '>=', $incident->created_at)->exists() ? null : 'Registra una gestión de cobranza con el cliente antes de resolver.',
            default => null,
        };
    }

    /**
     * Automatic receivables work: promise evaluation, follow-ups for overdue invoices, unapplied receipts, disputes and
     * ledger differences. Idempotent.
     *
     * @param  list<int>  $companyIds
     * @return array{tasks: int, incidents: int}
     */
    public function syncWork(Organization $organization, array $companyIds): array
    {
        $this->promises->evaluate($organization, $companyIds);
        $ids = $companyIds === [] ? [-1] : $companyIds;
        $assignees = DB::table('company_assignments')->whereIn('company_id', $ids)->where('assignment_role', 'accounts_receivable')->where('status', 'active')->whereNull('deleted_at')
            ->orderByDesc('is_primary')->get(['company_id', 'user_id'])->unique('company_id')->keyBy('company_id');
        $offices = DB::table('companies')->whereIn('id', $ids)->pluck('office_id', 'id');
        $tasks = 0;
        $incidents = 0;
        $done = [];
        $documents = collect($this->receivables->documents($companyIds));
        $recentActivity = DB::table('collection_activities')->whereIn('company_id', $ids)->groupBy('sales_customer_id')->selectRaw('sales_customer_id, MAX(performed_at) as last_at')->pluck('last_at', 'sales_customer_id');

        foreach ($documents->where('overdue', true) as $document) {
            $last = $recentActivity[$document['customer_id']] ?? null;
            $key = 'overdue_followup:'.$document['id'];
            if ($last !== null && Carbon::parse($last)->gte(now()->subDays(7))) {
                $done[] = $key;

                continue;
            }
            $tasks += $this->ensure($organization->id, 'work_tasks', $key, [
                'office_id' => $offices[$document['company_id']] ?? null, 'company_id' => $document['company_id'], 'module' => 'receivables', 'task_type' => 'overdue_followup',
                'title' => 'Gestionar cobro de '.$document['document'].' · '.$document['customer'], 'assigned_to' => $assignees->get($document['company_id'])?->user_id,
                'priority' => $document['overdue_days'] > 30 ? 'high' : 'normal', 'status' => 'pending', 'due_at' => now()->addDay()->endOfDay(), 'source_type' => 'sales_invoices', 'source_id' => $document['id'],
            ]);
        }
        $openOverdue = $documents->where('overdue', true)->pluck('id')->map(fn (int $id): string => 'overdue_followup:'.$id)->all();
        DB::table('work_tasks')->where('work_tasks.organization_id', $organization->id)->where('automation_key', 'like', 'overdue_followup:%')->whereIn('company_id', $ids)
            ->whereNotIn('automation_key', $openOverdue === [] ? ['-'] : $openOverdue)->whereNotIn('status', ['completed', 'cancelled'])->update(['status' => 'completed', 'completed_at' => now(), 'updated_at' => now()]);

        // Customers over 30 days overdue with no follow-up in 30 days raise an incident.
        foreach ($documents->where('overdue', true)->where('overdue_days', '>', 30)->groupBy('customer_id') as $customerId => $rows) {
            $last = $recentActivity[$customerId] ?? null;
            $key = 'overdue_no_followup:'.$customerId;
            if ($last !== null && Carbon::parse($last)->gte(now()->subDays(30))) {
                continue;
            }
            $first = $rows->first();
            $incidents += $this->ensure($organization->id, 'operational_incidents', $key, [
                'office_id' => $offices[$first['company_id']] ?? null, 'company_id' => $first['company_id'], 'type' => 'receivables', 'severity' => 'high', 'status' => 'open', 'blocks_period' => false,
                'title' => 'Vencido sin gestión: '.$first['customer'], 'description' => 'Q '.number_format((int) $rows->sum('outstanding_cents') / 100, 2).' vencidos hasta '.$rows->max('overdue_days').' días sin gestión de cobranza registrada.',
                'responsible_id' => $assignees->get($first['company_id'])?->user_id, 'source_type' => 'sales_customers', 'source_id' => $customerId,
            ]);
        }

        foreach (DB::table('treasury_receipts')->where('treasury_receipts.organization_id', $organization->id)->whereIn('company_id', $ids)->get(['id', 'office_id', 'company_id', 'customer_name', 'unapplied_cents', 'reference']) as $receipt) {
            $key = 'apply_receipt:'.$receipt->id;
            if ((int) $receipt->unapplied_cents === 0) {
                $done[] = $key;

                continue;
            }
            $tasks += $this->ensure($organization->id, 'work_tasks', $key, [
                'office_id' => $receipt->office_id, 'company_id' => $receipt->company_id, 'module' => 'receivables', 'task_type' => 'apply_receipt',
                'title' => 'Aplicar cobro '.$receipt->reference.' · '.$receipt->customer_name, 'assigned_to' => $assignees->get($receipt->company_id)?->user_id,
                'priority' => 'high', 'status' => 'pending', 'due_at' => now()->addDays(2)->endOfDay(), 'source_type' => 'treasury_receipts', 'source_id' => $receipt->id,
            ]);
        }

        foreach (DB::table('receivable_disputes')->where('receivable_disputes.organization_id', $organization->id)->whereIn('company_id', $ids)->whereIn('status', ['open', 'under_review'])->get(['id', 'office_id', 'company_id', 'responsible_id', 'sales_invoice_id']) as $dispute) {
            $tasks += $this->ensure($organization->id, 'work_tasks', 'review_dispute:'.$dispute->id, [
                'office_id' => $dispute->office_id, 'company_id' => $dispute->company_id, 'module' => 'receivables', 'task_type' => 'review_dispute',
                'title' => 'Revisar disputa de factura #'.DB::table('sales_invoices')->where('id', $dispute->sales_invoice_id)->value('number'), 'assigned_to' => $dispute->responsible_id ?? $assignees->get($dispute->company_id)?->user_id,
                'priority' => 'normal', 'status' => 'pending', 'due_at' => now()->addDays(5)->endOfDay(), 'source_type' => 'receivable_disputes', 'source_id' => $dispute->id,
            ]);
        }

        foreach ($companyIds as $companyId) {
            $key = 'ar_difference:'.$companyId;
            $result = $this->reconciliation->compute($companyId, $this->receivables->today($companyId));
            if ($result['status'] === 'difference') {
                $existing = DB::table('operational_incidents')->where('operational_incidents.organization_id', $organization->id)->where('automation_key', $key)->first(['id', 'status']);
                if ($existing !== null && in_array($existing->status, ['resolved', 'dismissed'], true)) {
                    DB::table('operational_incidents')->where('id', $existing->id)->update(['status' => 'open', 'resolved_at' => null, 'resolved_by' => null, 'updated_at' => now()]);
                }
                $incidents += $this->ensure($organization->id, 'operational_incidents', $key, [
                    'office_id' => $offices[$companyId] ?? null, 'company_id' => $companyId, 'type' => 'receivables', 'severity' => 'high', 'status' => 'open', 'blocks_period' => false,
                    'title' => 'CxC no coincide con el Mayor', 'description' => 'Diferencia de Q '.number_format((int) $result['difference_cents'] / 100, 2).' con '.count($result['items']).' partida(s) por investigar.',
                    'responsible_id' => $assignees->get($companyId)?->user_id, 'source_type' => 'companies', 'source_id' => $companyId,
                ]);
            } elseif ($result['status'] === 'matched') {
                DB::table('operational_incidents')->where('operational_incidents.organization_id', $organization->id)->where('automation_key', $key)->whereIn('status', ['open', 'in_progress', 'blocked'])
                    ->update(['status' => 'resolved', 'resolution' => 'CxC coincide nuevamente con el Mayor.', 'resolved_at' => now(), 'updated_at' => now()]);
            }
        }

        DB::table('work_tasks')->where('work_tasks.organization_id', $organization->id)->whereIn('automation_key', $done === [] ? ['-'] : $done)->whereNotIn('status', ['completed', 'cancelled'])
            ->update(['status' => 'completed', 'completed_at' => now(), 'updated_at' => now()]);

        return ['tasks' => $tasks, 'incidents' => $incidents];
    }

    /**
     * @param  list<int>  $companyIds
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function tasks(Organization $organization, User $user, array $companyIds, array $filters = []): array
    {
        $rows = $this->operations->tasks($organization->id, $this->supervisor->officeIds($companyIds), [...$filters, 'assigned_to' => $user->id], 500);
        $sources = DB::table('work_tasks')->whereIn('id', array_column($rows, 'id') ?: [-1])->get(['id', 'source_type', 'source_id', 'automation_key'])->keyBy('id');

        return array_values(array_map(function (array $task) use ($sources): array {
            $source = $sources->get($task['id']);
            $continue = match ($source?->source_type) {
                'collection_activities' => '/receivables/customers/'.DB::table('collection_activities')->where('id', $source->source_id)->value('sales_customer_id'),
                'promises_to_pay' => '/receivables/customers/'.DB::table('promises_to_pay')->where('id', $source->source_id)->value('sales_customer_id'),
                'sales_invoices' => '/receivables/documents/'.$source->source_id,
                'treasury_receipts' => '/receivables/unapplied-receipts?receipt='.$source->source_id,
                'receivable_disputes' => '/receivables/disputes',
                'companies' => '/receivables/reconciliation?company_id='.$source->source_id,
                default => $task['module_destination'] ?? $task['destination'],
            };
            $automatic = $source?->automation_key !== null;

            return [...$task, 'continue_to' => $continue ?? $task['destination'], 'automatic' => $automatic, 'can_start' => ! $automatic && in_array($task['status'], ['pending', 'changes_requested', 'blocked'], true)];
        }, array_filter($rows, fn (array $task): bool => $task['company_id'] !== null && in_array($task['company_id'], $companyIds, true) && in_array($task['module'], ['receivables', 'sales'], true))));
    }

    /**
     * Due dates, promised dates, follow-ups and tasks of the range.
     *
     * @param  list<int>  $companyIds
     * @return list<array<string, mixed>>
     */
    public function calendar(Organization $organization, User $user, array $companyIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $events = [];
        foreach ($this->receivables->documents($companyIds) as $document) {
            if ($document['due_date'] >= $from->toDateString() && $document['due_date'] <= $to->toDateString()) {
                $events[] = ['date' => $document['due_date'], 'type' => 'statutory', 'title' => 'Vence '.$document['document'].' · '.$document['customer'], 'subtitle' => $document['company'].' · Q '.number_format($document['outstanding_cents'] / 100, 2), 'priority' => $document['overdue'] ? 'critical' : 'normal', 'destination' => $document['destination']];
            }
        }
        foreach ($this->promises->list($companyIds, ['status' => 'active']) as $promise) {
            if ($promise['promised_date'] >= $from->toDateString() && $promise['promised_date'] <= $to->toDateString()) {
                $events[] = ['date' => $promise['promised_date'], 'type' => 'internal', 'title' => 'Promesa de pago · '.$promise['customer'], 'subtitle' => $promise['company'].' · Q '.number_format($promise['amount_cents'] / 100, 2), 'priority' => 'high', 'destination' => $promise['destination']];
            }
        }
        foreach ($this->activities->list($companyIds, ['status' => 'open']) as $activity) {
            if ($activity['next_action_at'] !== null && Carbon::parse($activity['next_action_at'])->between($from, $to)) {
                $events[] = ['date' => $activity['next_action_at'], 'type' => 'review', 'title' => (string) $activity['next_action'].' · '.$activity['customer'], 'subtitle' => $activity['company'].' · próxima acción', 'priority' => $activity['status'] === 'overdue' ? 'critical' : 'normal', 'destination' => $activity['destination']];
            }
        }
        foreach ($this->tasks($organization, $user, $companyIds, ['status' => 'open']) as $task) {
            if ($task['due_at'] !== null && Carbon::parse($task['due_at'])->between($from, $to) && ! str_starts_with((string) $task['continue_to'], '/receivables/customers/')) {
                $events[] = ['date' => (string) $task['due_at'], 'type' => 'task', 'title' => $task['title'], 'subtitle' => (string) $task['company_name'], 'priority' => $task['priority'], 'destination' => $task['continue_to']];
            }
        }
        usort($events, fn (array $a, array $b): int => strcmp((string) $a['date'], (string) $b['date']));

        return $events;
    }

    /**
     * @param  list<int>  $companyIds
     * @return array<string, mixed>
     */
    public function dashboard(Organization $organization, User $user, array $companyIds, ?int $selectedCompany): array
    {
        $companies = $this->companies($organization, $companyIds);
        $failed = collect($companies)->contains('status', 'error');
        $documents = $failed ? null : collect($this->receivables->documents($companyIds));
        $selected = collect($companies)->firstWhere('id', $selectedCompany) ?? collect($companies)->sortByDesc('total_cents')->first();
        $unapplied = $this->receipts($companyIds, ['unapplied' => true]);

        return [
            'metrics' => [
                'assigned_companies' => count($companyIds),
                'open_documents' => $documents?->count(),
                'overdue_documents' => $documents?->where('overdue', true)->count(),
                'customers_with_balance' => $documents?->map(fn (array $row): string => $row['company_id'].':'.$row['customer_id'])->unique()->count(),
                'active_promises' => DB::table('promises_to_pay')->whereIn('company_id', $companyIds === [] ? [-1] : $companyIds)->whereIn('status', ['pending', 'partially_fulfilled'])->count(),
                'unapplied_receipts' => count($unapplied),
                'overdue_followups' => DB::table('collection_activities')->whereIn('company_id', $companyIds === [] ? [-1] : $companyIds)->where('status', 'open')->where('next_action_at', '<', now())->count(),
                'incidents' => $this->incidentQuery($organization->id, $companyIds)->whereIn('status', ['open', 'in_progress', 'blocked'])->count(),
            ],
            'companies' => $companies,
            'selected_company' => $selected,
            'upcoming' => $documents === null ? [] : array_values($documents->filter(fn (array $row): bool => $row['days_to_due'] <= 15)->sortBy('due_date')->take(8)->values()->all()),
            'activities' => array_slice($this->activities->list($companyIds), 0, 6),
            'unapplied' => array_slice($unapplied, 0, 5),
            'promises' => array_slice($this->promises->list($companyIds, ['status' => 'active']), 0, 6),
            'tasks' => array_slice($this->tasks($organization, $user, $companyIds, ['status' => 'open']), 0, 6),
            'last_updated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Customers with overdue balance ordered by collection priority.
     *
     * @param  list<int>  $companyIds
     * @return list<array<string, mixed>>
     */
    public function overdueCustomers(array $companyIds): array
    {
        $rank = ['critical' => 0, 'high' => 1, 'normal' => 2, 'low' => 3];

        return array_values(collect($this->receivables->customers($companyIds))->filter(fn (array $customer): bool => $customer['overdue_cents'] > 0)
            ->sortBy([fn (array $a, array $b): int => $rank[$a['priority']] <=> $rank[$b['priority']], fn (array $a, array $b): int => $b['max_overdue_days'] <=> $a['max_overdue_days'], fn (array $a, array $b): int => $b['overdue_cents'] <=> $a['overdue_cents']])
            ->values()->all());
    }

    /** @param array<string, mixed> $data */
    private function ensure(int $organizationId, string $table, string $key, array $data): int
    {
        if (($table === 'work_tasks' && $data['assigned_to'] === null) || DB::table($table)->where($table.'.organization_id', $organizationId)->where('automation_key', $key)->exists()) {
            return 0;
        }
        DB::table($table)->insert([...$data, 'public_id' => (string) Str::uuid(), 'organization_id' => $organizationId, 'automation_key' => $key, 'created_at' => now(), 'updated_at' => now()]);

        return 1;
    }
}
