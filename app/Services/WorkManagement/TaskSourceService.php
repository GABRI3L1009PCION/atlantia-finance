<?php

declare(strict_types=1);

namespace App\Services\WorkManagement;

use App\Services\ClientPortal\ClientRequestService;
use App\Services\Fiscal\FiscalObligationChecklistService;
use App\Services\Fiscal\FiscalObligationWorkflowService;
use App\Services\Operations\CompanyPeriodReadinessService;
use Illuminate\Support\Facades\DB;
use stdClass;
use Throwable;

/**
 * What the business object behind a task says about the work. A task coordinates; the module executes, so every answer
 * here comes from the real records (a collection activity that exists, a payment proposal that was prepared, a payroll
 * period that reached review, a reconciliation that was approved, a period that is closed...). Nothing is typed by hand.
 *
 * `mirrored` sources have their own review workflow inside the module: the task only reflects it. Otherwise the task
 * is closed as soon as the source shows the result it was created for.
 *
 * @phpstan-type SourceState array{label: string, destination: string, status_label: string, progress: int, ready: bool, pending: list<string>, blockers: list<string>, desired_status: string|null, mirrored: bool, result: string|null, error: string|null}
 */
class TaskSourceService
{
    public const TYPES = [
        'fiscal_obligations', 'client_requests', 'sales_invoices', 'sales_customers', 'promises_to_pay', 'collection_activities', 'purchase_documents', 'payment_proposals',
        'payroll_periods', 'bank_accounts', 'accounting_periods',
    ];

    public function __construct(
        private FiscalObligationChecklistService $obligations,
        private CompanyPeriodReadinessService $periods,
    ) {}

    public function supports(?string $type): bool
    {
        return $type !== null && in_array($type, self::TYPES, true);
    }

    /** @return SourceState|null null when the task has no business object (or it no longer exists) */
    public function state(stdClass $task): ?array
    {
        if (! $this->supports($task->source_type) || $task->source_id === null) {
            return null;
        }

        try {
            return match ($task->source_type) {
                'fiscal_obligations' => $this->obligation($task),
                'client_requests' => $this->clientRequest($task),
                'sales_invoices', 'sales_customers', 'promises_to_pay', 'collection_activities' => $this->collection($task),
                'purchase_documents', 'payment_proposals' => $this->payable($task),
                'payroll_periods' => $this->payroll($task),
                'bank_accounts' => $this->bank($task),
                'accounting_periods' => $this->period($task),
                default => null,
            };
        } catch (Throwable) {
            return $this->failed();
        }
    }

    /** @return SourceState */
    private function obligation(stdClass $task): ?array
    {
        $row = DB::table('fiscal_obligations')->where('id', $task->source_id)->first();
        if ($row === null) {
            return null;
        }
        $summary = in_array($row->status, ['pending', 'in_progress', 'changes_requested'], true) ? $this->obligations->summary($row) : null;
        $desired = match ($row->status) {
            'pending' => 'pending', 'in_progress' => 'in_progress', 'changes_requested' => 'changes_requested', 'ready_for_review' => 'ready_for_review', 'approved' => 'approved', 'completed' => 'completed',
            'cancelled' => 'cancelled', 'not_applicable' => 'not_applicable', default => null,
        };

        return $this->make('Obligación fiscal', '/essential/obligations/'.$row->id, FiscalObligationWorkflowService::STATUS_LABELS[$row->status] ?? (string) $row->status,
            $summary['progress'] ?? ($row->status === 'pending' ? 0 : 100), $summary['ready'] ?? false, $summary['pending'] ?? [], array_column($summary['blockers'] ?? [], 'label'), $desired, false,
            $row->status === 'completed' ? 'Presentada el '.$row->filed_on.' · referencia '.$row->filing_reference : null, $summary['error'] ?? null);
    }

    /** @return SourceState|null */
    private function clientRequest(stdClass $task): ?array
    {
        $row = DB::table('client_requests')->where('id', $task->source_id)->first();
        if ($row === null) {
            return null;
        }
        $progress = ['pending' => 0, 'in_progress' => 20, 'more_information_required' => 40, 'submitted' => 60, 'under_review' => 80, 'completed' => 100];
        $pending = match ($row->status) {
            'pending', 'in_progress' => ['El cliente aún no entrega lo solicitado'],
            'more_information_required' => ['El cliente debe completar: '.($row->review_note ?? 'información adicional')],
            'submitted', 'under_review' => ['La entrega del cliente falta por aceptar'],
            default => [],
        };

        return $this->make('Solicitud al cliente '.$row->code, '/work/client-portal', ClientRequestService::STATUS_LABELS[$row->status] ?? (string) $row->status, $progress[$row->status] ?? 0, $row->status === 'completed', $pending, [],
            $row->status === 'completed' ? 'completed' : (in_array($row->status, ['pending', 'in_progress', 'more_information_required'], true) ? 'waiting_client' : 'in_progress'), false,
            $row->status === 'completed' ? 'Entrega aceptada el '.$row->completed_at : null);
    }

    /** @return SourceState|null Collection work is resolved by a real collection activity registered after the task started. */
    private function collection(stdClass $task): ?array
    {
        $customerId = match ($task->source_type) {
            'sales_invoices' => DB::table('sales_invoices')->where('id', $task->source_id)->value('sales_customer_id'),
            'sales_customers' => $task->source_id,
            'promises_to_pay' => DB::table('promises_to_pay')->where('id', $task->source_id)->value('sales_customer_id'),
            default => DB::table('collection_activities')->where('id', $task->source_id)->value('sales_customer_id'),
        };
        if ($customerId === null) {
            return null;
        }
        $activity = DB::table('collection_activities')->where('company_id', $task->company_id)->where('sales_customer_id', $customerId)->where('status', '!=', 'superseded')
            ->when($task->started_at !== null, fn ($query) => $query->where('performed_at', '>=', $task->started_at), fn ($query) => $query->whereRaw('1 = 0'))->orderByDesc('performed_at')->first();

        return $this->make('Gestión de cobro', $this->company((int) $task->company_id).'/receivables', $activity === null ? 'Sin gestión registrada' : 'Gestión registrada', $activity === null ? 0 : 100, $activity !== null,
            $activity === null ? ['Registrar una gestión de cobro real (llamada, correo, promesa de pago…)'] : [], [], $activity === null ? null : 'completed', false,
            $activity === null ? null : 'Gestión registrada el '.$activity->performed_at.' · resultado '.$activity->result);
    }

    /** @return SourceState|null Payment preparation is done when a payment proposal with the document exists beyond draft. */
    private function payable(stdClass $task): ?array
    {
        $prepared = ['pending_approval', 'approved', 'sent_to_treasury', 'partially_executed', 'completed'];
        if ($task->source_type === 'payment_proposals') {
            $proposal = DB::table('payment_proposals')->where('id', $task->source_id)->first();
            if ($proposal === null) {
                return null;
            }
            $done = in_array($proposal->status, $prepared, true);
            $progress = ['draft' => 30, 'pending_approval' => 70, 'approved' => 85, 'sent_to_treasury' => 100, 'partially_executed' => 100, 'completed' => 100, 'rejected' => 30, 'cancelled' => 0];

            return $this->make('Propuesta de pago '.$proposal->reference, $this->company((int) $task->company_id).'/payables', (string) $proposal->status, $progress[$proposal->status] ?? 0, $done,
                $done ? [] : ['Completar y enviar la propuesta de pago a aprobación'], [], $done ? 'completed' : 'in_progress', false, $done ? 'Propuesta '.$proposal->reference.' preparada' : null);
        }
        $document = DB::table('purchase_documents')->where('id', $task->source_id)->first();
        if ($document === null) {
            return null;
        }
        $proposal = DB::table('payment_proposal_items')->join('payment_proposals', 'payment_proposals.id', '=', 'payment_proposal_items.payment_proposal_id')->where('purchase_document_id', $document->id)
            ->whereIn('payment_proposals.status', $prepared)->first(['payment_proposals.reference', 'payment_proposals.status']);

        return $this->make('Pago a proveedor', $this->company((int) $task->company_id).'/payables', $proposal === null ? 'Sin propuesta de pago' : 'En propuesta '.$proposal->reference, $proposal === null ? 0 : 100, $proposal !== null,
            $proposal === null ? ['Incluir el documento en una propuesta de pago y enviarla a aprobación'] : [], [], $proposal === null ? null : 'completed', false, $proposal === null ? null : 'Incluido en la propuesta '.$proposal->reference);
    }

    /** @return SourceState|null The payroll run has its own review inside the payroll module; the task reflects it. */
    private function payroll(stdClass $task): ?array
    {
        $period = DB::table('payroll_periods')->where('id', $task->source_id)->first();
        if ($period === null) {
            return null;
        }
        [$progress, $desired] = match ($period->status) {
            'open' => [10, 'in_progress'], 'calculated' => [45, 'in_progress'], 'changes_requested' => [45, 'changes_requested'], 'in_review' => [70, 'ready_for_review'], 'approved' => [85, 'approved'],
            'paid', 'closed' => [100, 'completed'], default => [0, 'cancelled'],
        };
        $pending = match ($period->status) {
            'open' => ['Calcular la nómina del período'], 'calculated' => ['Enviar la nómina a revisión'], 'changes_requested' => ['Corregir lo observado en la nómina'], 'approved' => ['Pagar la nómina'], default => [],
        };

        return $this->make('Nómina '.$period->name, '/payroll/periods/'.$period->id, (string) $period->status, $progress, in_array($period->status, ['calculated'], true), $pending,
            $period->needs_recalculation ? ['La nómina necesita recalcularse: '.($period->recalculation_reason ?? 'cambiaron sus datos')] : [], $desired, true, in_array($period->status, ['paid', 'closed'], true) ? 'Nómina pagada' : null);
    }

    /** @return SourceState A reconciliation is prepared, submitted and approved inside treasury; the task reflects it. */
    private function bank(stdClass $task): array
    {
        $reconciliation = DB::table('bank_reconciliations')->where('bank_account_id', $task->source_id)->where('company_id', $task->company_id)
            ->when($task->started_at !== null, fn ($query) => $query->where('created_at', '>=', $task->started_at), fn ($query) => $query->whereRaw('1 = 0'))->orderByDesc('cutoff_date')->first();
        if ($reconciliation === null) {
            return $this->make('Conciliación bancaria', $this->company((int) $task->company_id).'/banks', 'Sin conciliación iniciada', 0, false, ['Crear la conciliación del corte y comparar movimientos'], [], null, true, null);
        }
        [$progress, $desired, $pending] = match ($reconciliation->status) {
            'in_progress' => [$reconciliation->difference_cents === 0 ? 70 : 40, 'in_progress', $reconciliation->difference_cents === 0 ? ['Enviar la conciliación a aprobación'] : ['Resolver o documentar una diferencia de Q '.number_format(abs((int) $reconciliation->difference_cents) / 100, 2)]],
            'submitted' => [85, 'ready_for_review', []], 'approved' => [100, 'completed', []], default => [30, 'changes_requested', ['Corregir la conciliación devuelta']],
        };

        return $this->make('Conciliación al '.$reconciliation->cutoff_date, $this->company((int) $task->company_id).'/banks', (string) $reconciliation->status, $progress, $reconciliation->status === 'in_progress' && (int) $reconciliation->difference_cents === 0, $pending,
            [], $desired, true, $reconciliation->status === 'approved' ? 'Conciliación aprobada el '.$reconciliation->approved_at : null);
    }

    /** @return SourceState|null Closing is ready when the period readiness has no blockers and done when it is closed. */
    private function period(stdClass $task): ?array
    {
        $period = DB::table('accounting_periods')->where('id', $task->source_id)->first();
        if ($period === null) {
            return null;
        }
        if ($period->closed_at !== null) {
            return $this->make('Cierre '.$period->name, $this->company((int) $period->company_id), 'Cerrado', 100, true, [], [], 'completed', true, 'Período cerrado el '.$period->closed_at);
        }
        $check = $this->periods->check((int) $task->organization_id, (int) $period->company_id, (int) $period->id);

        return $this->make('Cierre '.$period->name, $this->company((int) $period->company_id), 'Abierto', $check['ready'] ? 90 : max(10, 80 - 15 * count($check['blockers'])), $check['ready'],
            array_column($check['blockers'], 'label'), array_column($check['blockers'], 'label'), 'in_progress', true, null);
    }

    /**
     * @param  list<string>  $pending
     * @param  list<string>  $blockers
     * @return SourceState
     */
    private function make(string $label, string $destination, string $statusLabel, int $progress, bool $ready, array $pending, array $blockers, ?string $desired, bool $mirrored, ?string $result, ?string $error = null): array
    {
        return ['label' => $label, 'destination' => $destination, 'status_label' => $statusLabel, 'progress' => $progress, 'ready' => $ready, 'pending' => $pending, 'blockers' => $blockers,
            'desired_status' => $desired, 'mirrored' => $mirrored, 'result' => $result, 'error' => $error];
    }

    /** @return SourceState */
    private function failed(): array
    {
        return $this->make('Origen de la tarea', '#', 'No disponible', 0, false, [], [], null, false, null, 'No fue posible validar la preparación.');
    }

    private function company(int $companyId): string
    {
        return '/accounting/companies/'.DB::table('companies')->where('id', $companyId)->value('public_id');
    }
}
