<?php

declare(strict_types=1);

namespace App\Services\Payables;

use App\Models\Organization;
use App\Models\User;
use App\Services\Operations\OperationsService;
use App\Services\Operations\SupervisorService;
use App\Services\Treasury\TreasuryPaymentService;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use stdClass;
use Throwable;

/**
 * Reads and automatic work of the accounts payable panel. Debts are always shown per company (never added across
 * client companies); anything that cannot be computed is reported as unavailable instead of zero. Automatic tasks and
 * incidents carry an automation key (no duplicates) and close themselves when the underlying problem is gone.
 */
class PayablesDashboardService
{
    public const COMPANY_LABELS = [
        'no_activity' => 'Sin movimiento', 'current' => 'Al día', 'with_upcoming_due' => 'Por vencer', 'with_overdue' => 'Con vencidos', 'payment_preparation' => 'En gestión',
        'with_unapplied_payments' => 'Pagos sin aplicar', 'with_advances' => 'Con anticipos', 'with_disputes' => 'Con disputas', 'reconciliation_difference' => 'Diferencia con Mayor',
        'attention_required' => 'Atención', 'error' => 'No disponible',
    ];

    public function __construct(
        private PayableService $payables,
        private PaymentProposalService $proposals,
        private SupplierAdvanceService $advances,
        private PayablesLedgerReconciliationService $reconciliation,
        private SupplierInvoiceDuplicateService $duplicates,
        private OperationsService $operations,
        private SupervisorService $supervisor,
    ) {}

    /** @return list<int> */
    public function companyIds(Organization $organization, User $user): array
    {
        $isOwner = DB::table('organization_user')->where('organization_user.organization_id', $organization->id)->where('user_id', $user->id)->where('role', 'owner')->where('status', 'active')->exists();
        $query = $isOwner
            ? DB::table('companies')->where('companies.organization_id', $organization->id)->pluck('id')
            : DB::table('company_assignments')->where('company_assignments.organization_id', $organization->id)->where('user_id', $user->id)
                ->whereIn('assignment_role', ['accounts_payable', 'accounting_assistant'])->where('status', 'active')->whereNull('deleted_at')->pluck('company_id');

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
            $documents = collect($this->payables->documents($companyIds));
        } catch (Throwable $exception) {
            Log::warning('Payables portfolio failed', ['error' => $exception->getMessage()]);
            $documents = null;
        }
        $count = fn (Builder $query) => $query->groupBy('company_id')->selectRaw('company_id, CAST(COUNT(*) AS BIGINT) as total')->pluck('total', 'company_id');
        $pendingProposals = $count(DB::table('payment_proposals')->whereIn('company_id', $ids)->where('status', 'pending_approval'));
        $inProgress = $count(DB::table('payment_proposals')->whereIn('company_id', $ids)->whereIn('status', ['draft', 'approved', 'sent_to_treasury', 'partially_executed']));
        $advances = $count(DB::table('supplier_advances')->whereIn('company_id', $ids)->whereIn('status', ['open', 'partially_applied']));
        $unapplied = $count(DB::table('supplier_advances')->whereIn('company_id', $ids)->where('origin', 'unapplied_payment')->whereIn('status', ['open', 'partially_applied']));
        $disputes = $count(DB::table('payable_disputes')->whereIn('company_id', $ids)->whereIn('status', ['open', 'under_review']));
        $incidents = $count($this->incidentQuery($organization->id, $companyIds)->whereIn('status', ['open', 'in_progress', 'blocked']));
        $critical = $count($this->incidentQuery($organization->id, $companyIds)->whereIn('status', ['open', 'in_progress', 'blocked'])->where('severity', 'high'));

        return array_values(DB::table('companies')->whereIn('id', $ids)->orderBy('name')->get(['id', 'public_id', 'name', 'tax_id'])->map(function (stdClass $company) use ($documents, $pendingProposals, $inProgress, $advances, $unapplied, $disputes, $incidents, $critical): array {
            $base = ['id' => (int) $company->id, 'public_id' => (string) $company->public_id, 'name' => (string) $company->name, 'tax_id' => (string) $company->tax_id,
                'proposals_waiting_approval' => (int) ($pendingProposals[$company->id] ?? 0), 'unapplied_advances' => (int) ($advances[$company->id] ?? 0), 'incidents' => (int) ($incidents[$company->id] ?? 0),
                'destination' => '/payables/companies/'.$company->id];
            if ($documents === null) {
                return [...$base, 'suppliers_with_balance' => null, 'open_documents' => null, 'overdue_documents' => null, 'total_cents' => null, 'current_cents' => null, 'overdue_cents' => null, 'reconciliation' => null, 'status' => 'error', 'status_label' => self::COMPANY_LABELS['error'], 'error' => 'Información de CxP no disponible.'];
            }
            $rows = $documents->where('company_id', (int) $company->id);
            $overdue = $rows->where('overdue', true);
            $reconciliation = $this->reconciliation->compute((int) $company->id, $this->payables->today((int) $company->id));
            $status = match (true) {
                $reconciliation['status'] === 'difference' => 'reconciliation_difference',
                (int) ($critical[$company->id] ?? 0) > 0 => 'attention_required',
                (int) ($disputes[$company->id] ?? 0) > 0 => 'with_disputes',
                (int) ($unapplied[$company->id] ?? 0) > 0 => 'with_unapplied_payments',
                (int) ($inProgress[$company->id] ?? 0) + (int) ($pendingProposals[$company->id] ?? 0) > 0 => 'payment_preparation',
                $overdue->isNotEmpty() => 'with_overdue',
                (int) ($advances[$company->id] ?? 0) > 0 => 'with_advances',
                $rows->contains(fn (array $row): bool => $row['days_to_due'] <= 7) => 'with_upcoming_due',
                $rows->isNotEmpty() => 'current',
                default => 'no_activity',
            };

            return [
                ...$base,
                'suppliers_with_balance' => $rows->pluck('supplier_id')->unique()->count(),
                'open_documents' => $rows->count(),
                'overdue_documents' => $overdue->count(),
                'total_cents' => (int) $rows->sum('outstanding_cents'),
                'current_cents' => (int) $rows->where('overdue', false)->sum('outstanding_cents'),
                'overdue_cents' => (int) $overdue->sum('outstanding_cents'),
                'reconciliation' => ['status' => $reconciliation['status'], 'status_label' => $reconciliation['status_label'], 'difference_cents' => $reconciliation['difference_cents']],
                'status' => $status,
                'status_label' => self::COMPANY_LABELS[$status],
                'error' => null,
            ];
        })->all());
    }

    /**
     * Payments of the companies: the treasury payments (the one shared payment entity) with how much of each was applied
     * to invoices or is still unapplied, plus the payments recorded directly in Purchases.
     *
     * @param  list<int>  $companyIds
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function payments(array $companyIds, array $filters = []): array
    {
        $ids = $companyIds === [] ? [-1] : $companyIds;
        $rows = [];
        foreach (DB::table('payment_instructions as payment')->join('companies', 'companies.id', '=', 'payment.company_id')->leftJoin('bank_accounts', 'bank_accounts.id', '=', 'payment.bank_account_id')
            ->leftJoin('users as executor', 'executor.id', '=', 'payment.executed_by')->leftJoin('payment_proposals as proposal', 'proposal.id', '=', 'payment.payment_proposal_id')
            ->whereIn('payment.company_id', $ids)->where('payment.kind', '!=', 'payroll')->orderByDesc('payment.id')->limit(300)
            ->get(['payment.*', 'companies.name as company_name', 'bank_accounts.bank_name', 'bank_accounts.last_four', 'executor.name as executor_name', 'proposal.reference as proposal_reference']) as $payment) {
            $applied = (int) DB::table('purchase_payments')->where('payment_instruction_id', $payment->id)->whereNull('voided_at')->whereNull('unapplied_at')->sum('amount_cents')
                + (int) DB::table('supplier_advance_applications')->join('supplier_advances', 'supplier_advances.id', '=', 'supplier_advance_applications.supplier_advance_id')
                    ->where('supplier_advances.payment_instruction_id', $payment->id)->whereNull('supplier_advance_applications.reversed_at')->sum('supplier_advance_applications.amount_cents');
            $executed = $payment->status === 'executed';
            $approvers = DB::table('payment_approvals')->join('users', 'users.id', '=', 'payment_approvals.approver_id')->where('payment_instruction_id', $payment->id)->where('payment_approvals.status', 'approved')->pluck('users.name')->all();
            $rows[] = [
                'key' => 'instruction-'.$payment->id, 'id' => (int) $payment->id, 'company_id' => (int) $payment->company_id, 'company' => (string) $payment->company_name, 'supplier' => (string) $payment->supplier_name,
                'kind' => (string) $payment->kind, 'kind_label' => $payment->kind === 'advance' ? 'Anticipo' : 'Pago de facturas', 'date' => (string) ($payment->executed_on ?? $payment->scheduled_on ?? Carbon::parse($payment->created_at)->toDateString()),
                'reference' => (string) ($payment->execution_reference ?? $payment->proposal_reference ?? 'TES-'.$payment->id), 'amount_cents' => (int) $payment->amount_cents,
                'account' => $payment->bank_name === null ? 'Por definir' : $payment->bank_name.' ****'.$payment->last_four, 'status' => (string) $payment->status,
                'status_label' => TreasuryPaymentService::STATUS_LABELS[$payment->status] ?? $payment->status, 'applied_cents' => $executed ? $applied : null,
                'unapplied_cents' => $executed ? (int) $payment->amount_cents - $applied : null, 'executor' => $payment->executor_name, 'approvers' => $approvers,
                'proposal' => $payment->proposal_reference, 'source' => 'Tesorería', 'destination' => '/treasury/payments/'.$payment->id,
            ];
        }
        foreach (DB::table('purchase_payments')->join('purchase_documents', 'purchase_documents.id', '=', 'purchase_payments.purchase_document_id')->join('companies', 'companies.id', '=', 'purchase_documents.company_id')
            ->whereIn('purchase_documents.company_id', $ids)->whereNull('purchase_payments.payment_instruction_id')->orderByDesc('purchase_payments.paid_on')->limit(200)
            ->get(['purchase_payments.*', 'purchase_documents.supplier_name', 'purchase_documents.company_id', 'purchase_documents.id as document_id', 'companies.name as company_name']) as $payment) {
            $rows[] = [
                'key' => 'purchase-'.$payment->id, 'id' => (int) $payment->id, 'company_id' => (int) $payment->company_id, 'company' => (string) $payment->company_name, 'supplier' => (string) $payment->supplier_name,
                'kind' => 'invoices', 'kind_label' => 'Pago registrado en Compras', 'date' => (string) $payment->paid_on, 'reference' => (string) $payment->reference, 'amount_cents' => (int) $payment->amount_cents,
                'account' => 'Caja / cuenta por mapeo', 'status' => $payment->voided_at === null ? 'executed' : 'cancelled', 'status_label' => $payment->voided_at === null ? 'Ejecutado' : 'Anulado',
                'applied_cents' => $payment->voided_at === null ? (int) $payment->amount_cents : 0, 'unapplied_cents' => 0, 'executor' => null, 'approvers' => [], 'proposal' => null,
                'source' => 'Compras', 'destination' => '/payables/documents/'.$payment->document_id,
            ];
        }
        usort($rows, fn (array $a, array $b): int => strcmp($b['date'], $a['date']));
        if (! empty($filters['unapplied'])) {
            $rows = array_values(array_filter($rows, fn (array $row): bool => ($row['unapplied_cents'] ?? 0) > 0));
        }

        return $rows;
    }

    /** @param list<int> $companyIds */
    public function incidentQuery(int $organizationId, array $companyIds): Builder
    {
        return DB::table('operational_incidents')->where('operational_incidents.organization_id', $organizationId)->whereIn('company_id', $companyIds === [] ? [-1] : $companyIds)->where('type', 'payables');
    }

    /** Why an automatic incident cannot be resolved yet (its source problem still exists), or null. */
    public function resolutionBlocker(stdClass $incident): ?string
    {
        $key = (string) $incident->automation_key;
        $parts = explode(':', $key);

        return match ($parts[0]) {
            'duplicate_invoice' => DB::table('purchase_documents')->whereIn('id', array_slice($parts, 1))->whereIn('status', ['draft', 'approved'])->count() > 1 ? 'Ambos documentos siguen vigentes: anula el duplicado o descarta la incidencia indicando que no es duplicado.' : null,
            'ap_difference' => $this->reconciliation->compute((int) ($parts[1] ?? 0), $this->payables->today((int) ($parts[1] ?? 0)))['status'] === 'difference' ? 'CxP aún no coincide con el Mayor; corrige el origen de la diferencia.' : null,
            'payable_overdue' => ($document = $this->payables->find((int) ($parts[1] ?? 0))) !== null && $document['overdue'] && ! $document['in_proposal'] && $document['committed_cents'] === 0 ? 'La obligación sigue vencida y sin propuesta ni pago en proceso.' : null,
            'payable_dispute' => DB::table('payable_disputes')->where('id', (int) ($parts[1] ?? 0))->whereIn('status', ['open', 'under_review'])->exists() ? 'Cierra primero la disputa con su resolución.' : null,
            default => null,
        };
    }

    /**
     * Automatic payables work (idempotent): payments to prepare, overdue obligations, unapplied advances, rejected
     * proposals, disputes, duplicated invoices and ledger differences, plus a grouped weekly due notice.
     *
     * @param  list<int>  $companyIds
     * @return array{tasks: int, incidents: int}
     */
    public function syncWork(Organization $organization, array $companyIds): array
    {
        $ids = $companyIds === [] ? [-1] : $companyIds;
        $assignees = DB::table('company_assignments')->whereIn('company_id', $ids)->where('assignment_role', 'accounts_payable')->where('status', 'active')->whereNull('deleted_at')
            ->orderByDesc('is_primary')->get(['company_id', 'user_id'])->unique('company_id')->keyBy('company_id');
        $offices = DB::table('companies')->whereIn('id', $ids)->pluck('office_id', 'id');
        $tasks = 0;
        $incidents = 0;
        $done = [];
        $documents = collect($this->payables->documents($companyIds));

        foreach ($documents as $document) {
            $key = 'prepare_payment:'.$document['id'];
            $needsPayment = ($document['overdue'] || $document['days_to_due'] <= 3) && ! $document['in_proposal'] && $document['committed_cents'] === 0 && ! $document['disputed'];
            if (! $needsPayment) {
                $done[] = $key;

                continue;
            }
            $tasks += $this->ensure($organization->id, 'work_tasks', $key, [
                'office_id' => $offices[$document['company_id']] ?? null, 'company_id' => $document['company_id'], 'module' => 'payables', 'task_type' => 'prepare_payment',
                'title' => 'Proponer pago de '.$document['document'].' · '.$document['supplier'], 'assigned_to' => $assignees->get($document['company_id'])?->user_id,
                'priority' => $document['overdue'] ? 'high' : 'normal', 'status' => 'pending', 'due_at' => Carbon::parse($document['due_date'])->endOfDay(), 'source_type' => 'purchase_documents', 'source_id' => $document['id'],
            ]);
            if ($document['overdue_days'] > 30) {
                $incidents += $this->ensure($organization->id, 'operational_incidents', 'payable_overdue:'.$document['id'], [
                    'office_id' => $offices[$document['company_id']] ?? null, 'company_id' => $document['company_id'], 'type' => 'payables', 'severity' => 'medium', 'status' => 'open', 'blocks_period' => false,
                    'title' => 'Obligación vencida sin gestión: '.$document['document'], 'description' => $document['supplier'].' · Q '.number_format($document['outstanding_cents'] / 100, 2).' con '.$document['overdue_days'].' días de atraso sin propuesta de pago.',
                    'responsible_id' => $assignees->get($document['company_id'])?->user_id, 'source_type' => 'purchase_documents', 'source_id' => $document['id'],
                ]);
            }
        }
        $settled = $documents->filter(fn (array $row): bool => ! $row['overdue'] || $row['in_proposal'] || $row['committed_cents'] > 0)->pluck('id')->map(fn (int $id): string => 'payable_overdue:'.$id)->all();
        DB::table('operational_incidents')->where('operational_incidents.organization_id', $organization->id)->whereIn('automation_key', $settled === [] ? ['-'] : $settled)->whereIn('status', ['open', 'in_progress', 'blocked'])
            ->update(['status' => 'resolved', 'resolution' => 'La obligación ya tiene propuesta, pago en proceso o dejó de estar vencida.', 'resolved_at' => now(), 'updated_at' => now()]);
        $closedDocuments = DB::table('work_tasks')->where('work_tasks.organization_id', $organization->id)->where('automation_key', 'like', 'prepare_payment:%')->whereIn('company_id', $ids)
            ->whereNotIn('source_id', $documents->pluck('id')->all() ?: [-1])->pluck('automation_key')->all();
        array_push($done, ...$closedDocuments);

        foreach (DB::table('supplier_advances')->where('supplier_advances.organization_id', $organization->id)->whereIn('company_id', $ids)->get(['id', 'office_id', 'company_id', 'status', 'paid_on', 'reference', 'origin']) as $advance) {
            $key = 'apply_advance:'.$advance->id;
            if (! in_array($advance->status, ['open', 'partially_applied'], true) || Carbon::parse($advance->paid_on)->gt(now()->subDays(7))) {
                if (! in_array($advance->status, ['open', 'partially_applied'], true)) {
                    $done[] = $key;
                }

                continue;
            }
            $tasks += $this->ensure($organization->id, 'work_tasks', $key, [
                'office_id' => $advance->office_id, 'company_id' => $advance->company_id, 'module' => 'payables', 'task_type' => 'apply_advance',
                'title' => ($advance->origin === 'advance' ? 'Aplicar anticipo ' : 'Aplicar pago sin aplicar ').$advance->reference, 'assigned_to' => $assignees->get($advance->company_id)?->user_id,
                'priority' => 'normal', 'status' => 'pending', 'due_at' => now()->addDays(3)->endOfDay(), 'source_type' => 'supplier_advances', 'source_id' => $advance->id,
            ]);
        }

        foreach (DB::table('payment_proposals')->where('payment_proposals.organization_id', $organization->id)->whereIn('company_id', $ids)->get(['id', 'office_id', 'company_id', 'status', 'reference', 'prepared_by']) as $proposal) {
            $key = 'proposal_rejected:'.$proposal->id;
            if ($proposal->status !== 'rejected') {
                $done[] = $key;

                continue;
            }
            $tasks += $this->ensure($organization->id, 'work_tasks', $key, [
                'office_id' => $proposal->office_id, 'company_id' => $proposal->company_id, 'module' => 'payables', 'task_type' => 'revise_proposal',
                'title' => 'Corregir propuesta rechazada '.$proposal->reference, 'assigned_to' => $proposal->prepared_by, 'priority' => 'high', 'status' => 'pending',
                'due_at' => now()->addDay()->endOfDay(), 'source_type' => 'payment_proposals', 'source_id' => $proposal->id,
            ]);
        }

        foreach (DB::table('payable_disputes')->where('payable_disputes.organization_id', $organization->id)->whereIn('company_id', $ids)->whereIn('status', ['open', 'under_review'])->get(['id', 'office_id', 'company_id', 'responsible_id', 'purchase_document_id']) as $dispute) {
            $tasks += $this->ensure($organization->id, 'work_tasks', 'review_payable_dispute:'.$dispute->id, [
                'office_id' => $dispute->office_id, 'company_id' => $dispute->company_id, 'module' => 'payables', 'task_type' => 'review_dispute',
                'title' => 'Resolver disputa de la compra #'.DB::table('purchase_documents')->where('id', $dispute->purchase_document_id)->value('number'), 'assigned_to' => $dispute->responsible_id ?? $assignees->get($dispute->company_id)?->user_id,
                'priority' => 'normal', 'status' => 'pending', 'due_at' => now()->addDays(5)->endOfDay(), 'source_type' => 'payable_disputes', 'source_id' => $dispute->id,
            ]);
        }

        foreach ($this->duplicates->candidates($companyIds) as $pair) {
            $incidents += $this->ensure($organization->id, 'operational_incidents', $pair['key'], [
                'office_id' => $offices[$pair['company_id']] ?? null, 'company_id' => $pair['company_id'], 'type' => 'payables', 'severity' => $pair['level'] === 'strong' ? 'high' : 'medium', 'status' => 'open', 'blocks_period' => false,
                'title' => 'Posible factura duplicada: '.$pair['documents'][0]['supplier'], 'description' => $pair['reason'].': '.$pair['documents'][0]['document'].' y '.$pair['documents'][1]['document'].'.',
                'responsible_id' => $assignees->get($pair['company_id'])?->user_id, 'source_type' => 'purchase_documents', 'source_id' => $pair['documents'][1]['id'],
            ]);
        }

        foreach ($companyIds as $companyId) {
            $key = 'ap_difference:'.$companyId;
            $result = $this->reconciliation->compute($companyId, $this->payables->today($companyId));
            if ($result['status'] === 'difference') {
                $existing = DB::table('operational_incidents')->where('operational_incidents.organization_id', $organization->id)->where('automation_key', $key)->first(['id', 'status']);
                if ($existing !== null && in_array($existing->status, ['resolved', 'dismissed'], true)) {
                    DB::table('operational_incidents')->where('id', $existing->id)->update(['status' => 'open', 'resolved_at' => null, 'resolved_by' => null, 'updated_at' => now()]);
                }
                $incidents += $this->ensure($organization->id, 'operational_incidents', $key, [
                    'office_id' => $offices[$companyId] ?? null, 'company_id' => $companyId, 'type' => 'payables', 'severity' => 'high', 'status' => 'open', 'blocks_period' => false,
                    'title' => 'CxP no coincide con el Mayor', 'description' => 'Diferencia de Q '.number_format((int) $result['difference_cents'] / 100, 2).' con '.count($result['items']).' partida(s) por investigar.',
                    'responsible_id' => $assignees->get($companyId)?->user_id, 'source_type' => 'companies', 'source_id' => $companyId,
                ]);
            } elseif ($result['status'] === 'matched') {
                DB::table('operational_incidents')->where('operational_incidents.organization_id', $organization->id)->where('automation_key', $key)->whereIn('status', ['open', 'in_progress', 'blocked'])
                    ->update(['status' => 'resolved', 'resolution' => 'CxP coincide nuevamente con el Mayor.', 'resolved_at' => now(), 'updated_at' => now()]);
            }
        }

        DB::table('work_tasks')->where('work_tasks.organization_id', $organization->id)->whereIn('automation_key', $done === [] ? ['-'] : $done)->whereNotIn('status', ['completed', 'cancelled'])
            ->update(['status' => 'completed', 'completed_at' => now(), 'updated_at' => now()]);

        // The grouped "due this week" notice is a Luzma automatic reminder (LuzmaReminderService::syncAutomatic).
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
                'purchase_documents' => '/payables/documents/'.$source->source_id,
                'supplier_advances' => '/payables/advances?advance='.$source->source_id,
                'payment_proposals' => '/payables/proposals/'.$source->source_id,
                'payable_disputes' => '/payables/disputes',
                'companies' => '/payables/reconciliation?company_id='.$source->source_id,
                default => $task['module_destination'] ?? $task['destination'],
            };
            $automatic = $source?->automation_key !== null;

            return [...$task, 'continue_to' => $continue ?? $task['destination'], 'automatic' => $automatic, 'can_start' => ! $automatic && in_array($task['status'], ['pending', 'changes_requested', 'blocked'], true)];
        }, array_filter($rows, fn (array $task): bool => $task['company_id'] !== null && in_array($task['company_id'], $companyIds, true) && in_array($task['module'], ['payables', 'purchases'], true))));
    }

    /**
     * @param  list<int>  $companyIds
     * @return list<array<string, mixed>>
     */
    public function calendar(Organization $organization, User $user, array $companyIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $events = [];
        foreach ($this->payables->documents($companyIds) as $document) {
            if ($document['due_date'] >= $from->toDateString() && $document['due_date'] <= $to->toDateString()) {
                $events[] = ['date' => $document['due_date'], 'type' => 'statutory', 'title' => 'Vence '.$document['document'].' · '.$document['supplier'], 'subtitle' => $document['company'].' · Q '.number_format($document['outstanding_cents'] / 100, 2).($document['in_proposal'] ? ' · en propuesta' : ''), 'priority' => $document['overdue'] ? 'critical' : 'normal', 'destination' => $document['destination']];
            }
        }
        foreach ($this->proposals->list($organization, $companyIds, ['status' => 'active']) as $proposal) {
            if ($proposal['proposed_date'] >= $from->toDateString() && $proposal['proposed_date'] <= $to->toDateString()) {
                $events[] = ['date' => $proposal['proposed_date'], 'type' => 'internal', 'title' => 'Propuesta '.$proposal['reference'].' · '.$proposal['status_label'], 'subtitle' => $proposal['company'].' · Q '.number_format($proposal['total_cents'] / 100, 2), 'priority' => 'high', 'destination' => $proposal['destination']];
            }
        }
        foreach (DB::table('payment_instructions')->whereIn('company_id', $companyIds === [] ? [-1] : $companyIds)->where('kind', '!=', 'payroll')->whereIn('status', ['approved', 'scheduled'])->whereBetween('scheduled_on', [$from->toDateString(), $to->toDateString()])->get(['id', 'supplier_name', 'scheduled_on', 'amount_cents']) as $payment) {
            $events[] = ['date' => (string) $payment->scheduled_on, 'type' => 'review', 'title' => 'Pago programado · '.$payment->supplier_name, 'subtitle' => 'Tesorería · Q '.number_format((int) $payment->amount_cents / 100, 2), 'priority' => 'normal', 'destination' => '/payables/payments'];
        }
        foreach ($this->tasks($organization, $user, $companyIds, ['status' => 'open']) as $task) {
            if ($task['due_at'] !== null && Carbon::parse($task['due_at'])->between($from, $to) && ! str_starts_with((string) $task['continue_to'], '/payables/documents/')) {
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
        $documents = $failed ? null : collect($this->payables->documents($companyIds));
        $selected = collect($companies)->firstWhere('id', $selectedCompany) ?? collect($companies)->sortByDesc('total_cents')->first();
        $ids = $companyIds === [] ? [-1] : $companyIds;

        return [
            'metrics' => [
                'assigned_companies' => count($companyIds),
                'open_documents' => $documents?->count(),
                'overdue_documents' => $documents?->where('overdue', true)->count(),
                'suppliers_with_balance' => $documents?->map(fn (array $row): string => $row['company_id'].':'.$row['supplier_id'])->unique()->count(),
                'payments_to_prepare' => $documents?->filter(fn (array $row): bool => ($row['overdue'] || $row['days_to_due'] <= 7) && ! $row['in_proposal'] && $row['committed_cents'] === 0 && ! $row['disputed'])->count(),
                'payments_waiting_approval' => DB::table('payment_proposals')->whereIn('company_id', $ids)->where('status', 'pending_approval')->count()
                    + DB::table('payment_instructions')->whereIn('company_id', $ids)->where('kind', '!=', 'payroll')->where('status', 'pending_approval')->count(),
                'unapplied_advances' => DB::table('supplier_advances')->whereIn('company_id', $ids)->whereIn('status', ['open', 'partially_applied'])->count(),
                'incidents' => $this->incidentQuery($organization->id, $companyIds)->whereIn('status', ['open', 'in_progress', 'blocked'])->count(),
            ],
            'companies' => $companies,
            'selected_company' => $selected,
            'due_months' => $selected === null || $selected['status'] === 'error' ? [] : $this->payables->byDueMonth((int) $selected['id']),
            'upcoming' => $documents === null ? [] : array_values($documents->filter(fn (array $row): bool => $row['days_to_due'] <= 15)->sortBy('due_date')->take(8)->values()->all()),
            'proposals' => array_slice($this->proposals->list($organization, $companyIds), 0, 6),
            'payments' => array_slice($this->payments($companyIds), 0, 6),
            'advances' => array_slice($this->advances->list($companyIds, ['status' => 'open']), 0, 6),
            'tasks' => array_slice($this->tasks($organization, $user, $companyIds, ['status' => 'open']), 0, 6),
            'last_updated_at' => now()->toIso8601String(),
        ];
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
