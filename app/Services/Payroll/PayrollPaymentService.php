<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingAutomationService;
use App\Services\AccountingService;
use App\Services\Operations\OperationalIncidentService;
use App\Services\Treasury\TreasuryPaymentService;
use App\Services\WorkManagement\WorkNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Payroll payments: Payroll sends the payment orders of an approved payroll to Treasury (one payment per method) and
 * Treasury approves and executes them. The execution settles the salaries payable against the bank; a failed, rejected
 * or cancelled payment returns its orders so they can be sent again.
 */
class PayrollPaymentService
{
    public const ORDER_STATUS_LABELS = ['pending' => 'Por enviar', 'sent' => 'En Tesorería', 'paid' => 'Pagada', 'failed' => 'Fallida', 'cancelled' => 'Cancelada'];

    public const METHOD_LABELS = ['transfer' => 'Transferencia', 'check' => 'Cheque', 'cash' => 'Efectivo'];

    public function __construct(
        private AccountingService $accounting,
        private AccountingAutomationService $automation,
        private PayrollSetupService $setup,
        private OperationalIncidentService $incidents,
        private WorkNotificationService $notifications,
    ) {}

    /** Sends the pending (or returned) orders of an approved payroll to Treasury; returns the payments created. */
    public function sendToTreasury(Organization $organization, User $actor, int $periodId, ?int $bankAccountId = null): int
    {
        return DB::transaction(function () use ($organization, $actor, $periodId, $bankAccountId): int {
            $period = DB::table('payroll_periods')->where('payroll_periods.organization_id', $organization->id)->where('id', $periodId)->lockForUpdate()->first();
            abort_if($period === null, 404);
            if ($period->status !== 'approved') {
                throw ValidationException::withMessages(['status' => 'Solo se envían a Tesorería nóminas aprobadas.']);
            }
            $orders = DB::table('payroll_payment_orders')->where('payroll_period_id', $periodId)->whereIn('status', ['pending', 'failed'])->lockForUpdate()->get();
            if ($orders->isEmpty()) {
                throw ValidationException::withMessages(['status' => 'No hay órdenes de pago pendientes de enviar.']);
            }
            $company = (string) DB::table('companies')->where('id', $period->company_id)->value('name');
            $treasury = app(TreasuryPaymentService::class);
            $created = 0;
            foreach ($orders->groupBy('method') as $method => $group) {
                $instruction = $treasury->prepare($organization, $actor, (int) $period->company_id, [
                    'bank_account_id' => $bankAccountId, 'method' => (string) $method, 'priority' => 'high', 'scheduled_on' => (string) $period->payment_date, 'items' => [],
                    'concept' => 'Nómina '.$period->name.' · '.$group->count().' empleado(s) · '.(self::METHOD_LABELS[$method] ?? $method),
                    'kind' => 'payroll', 'payroll_period_id' => $periodId, 'amount_cents' => (int) $group->sum('amount_cents'), 'payee' => 'Nómina '.$period->name.' · '.$company,
                ]);
                DB::table('payroll_payment_orders')->whereIn('id', $group->pluck('id'))->update(['status' => 'sent', 'payment_instruction_id' => $instruction, 'failure_reason' => null, 'updated_at' => now()]);
                $created++;
            }
            $this->accounting->audit($organization, $actor, 'payroll.sent_to_treasury', 'payroll_periods', $periodId, ['payments' => $created, 'orders' => $orders->count()], (int) $period->office_id);
            foreach (DB::table('company_assignments')->where('company_id', $period->company_id)->where('assignment_role', 'treasury')->where('status', 'active')->whereNull('deleted_at')->pluck('user_id') as $recipient) {
                $this->notifications->notify($organization->id, (int) $recipient, $actor->id, 'payroll.payment', 'Pago de nómina por aprobar: '.$period->name, $company.' · '.$created.' pago(s) · Q '.number_format((int) $orders->sum('amount_cents') / 100, 2), 'payroll_periods', $periodId);
            }

            return $created;
        });
    }

    /** Treasury executed a payroll payment: its orders are paid and the salaries payable is settled against the bank. */
    public function fromExecutedPayment(Organization $organization, User $actor, stdClass $instruction, string $executedOn, string $reference, int $bankLedger): void
    {
        $period = DB::table('payroll_periods')->where('id', $instruction->payroll_period_id)->lockForUpdate()->first();
        if ($period === null || ! in_array($period->status, ['approved', 'paid'], true)) {
            throw ValidationException::withMessages(['status' => 'La nómina de este pago no está aprobada.']);
        }
        if ($executedOn < (string) $period->starts_on) {
            throw ValidationException::withMessages(['executed_on' => 'La fecha de ejecución no puede ser anterior al período de la nómina.']);
        }
        $mapping = $this->setup->ensure((int) $period->company_id);
        $amount = number_format((int) $instruction->amount_cents / 100, 2, '.', '');
        $entry = $this->automation->draft($organization, $actor, (int) $instruction->company_id, 'payroll_payment', (int) $instruction->id, $executedOn, 'Pago de nómina '.$period->name, 'PNOM-'.$reference, [
            ['account_id' => (int) $mapping->salaries_payable_id, 'debit' => $amount, 'credit' => '0', 'description' => 'Pago de sueldos'],
            ['account_id' => $bankLedger, 'debit' => '0', 'credit' => $amount],
        ], 'payroll_payment.origin');
        if ($entry === null) {
            throw ValidationException::withMessages(['executed_on' => 'Abre un período contable que incluya la fecha del pago.']);
        }
        DB::table('payroll_payment_orders')->where('payment_instruction_id', $instruction->id)->where('status', 'sent')
            ->update(['status' => 'paid', 'paid_on' => $executedOn, 'reference' => mb_substr($reference, 0, 120), 'updated_at' => now()]);
        $open = DB::table('payroll_payment_orders')->where('payroll_period_id', $period->id)->whereNotIn('status', ['paid', 'cancelled'])->exists();
        if (! $open) {
            DB::table('payroll_periods')->where('id', $period->id)->update(['status' => 'paid', 'paid_at' => now(), 'updated_at' => now()]);
            $this->accounting->audit($organization, $actor, 'payroll.paid', 'payroll_periods', (int) $period->id, ['net_cents' => (int) $period->net_cents], (int) $period->office_id);
            $this->notifications->notify($organization->id, (int) ($period->submitted_by ?? $period->calculated_by), $actor->id, 'payroll.payment', 'Nómina pagada: '.$period->name, 'Tesorería ejecutó todos los pagos de la nómina.', 'payroll_periods', (int) $period->id);
        }
    }

    /** Follows a payroll payment: a failed, rejected or cancelled one returns its orders so they can be sent again. */
    public function refresh(int $instructionId): void
    {
        $instruction = DB::table('payment_instructions')->where('id', $instructionId)->first();
        if ($instruction === null || $instruction->payroll_period_id === null || ! in_array($instruction->status, ['failed', 'rejected', 'cancelled'], true)) {
            return;
        }
        $reason = (string) ($instruction->failure_reason ?? $instruction->rejection_reason ?? 'Pago no ejecutado por Tesorería.');
        $returned = DB::table('payroll_payment_orders')->where('payment_instruction_id', $instructionId)->where('status', 'sent')
            ->update(['status' => 'failed', 'failure_reason' => mb_substr($reason, 0, 255), 'updated_at' => now()]);
        if ($returned === 0) {
            return;
        }
        $period = DB::table('payroll_periods')->where('id', $instruction->payroll_period_id)->first(['id', 'name', 'office_id', 'company_id', 'calculated_by', 'submitted_by']);
        $organization = Organization::query()->find((int) $instruction->organization_id);
        if ($period === null || $organization === null) {
            return;
        }
        $responsible = (int) ($period->submitted_by ?? $period->calculated_by);
        if ($instruction->status === 'failed') {
            $this->incidents->create($organization, null, [
                'office_id' => (int) $period->office_id, 'company_id' => (int) $period->company_id, 'type' => 'payroll', 'severity' => 'high',
                'title' => 'Pago de nómina fallido: '.$period->name, 'description' => $reason, 'source_type' => 'payroll_periods', 'source_id' => (int) $period->id,
                'automation_key' => 'payroll_payment_failed:'.$instructionId,
            ]);
        }
        $this->notifications->notify($organization->id, $responsible, null, 'payroll.payment', 'Pago de nómina devuelto: '.$period->name, $returned.' orden(es) quedaron por reenviar. '.$reason, 'payroll_periods', (int) $period->id);
    }

    /** @return array<string, mixed> */
    public function orderRow(stdClass $row): array
    {
        return [
            'id' => (int) $row->id, 'period_id' => (int) $row->payroll_period_id, 'period' => (string) ($row->period_name ?? ''), 'company' => (string) ($row->company_name ?? ''),
            'employee' => trim(($row->first_name ?? '').' '.($row->last_name ?? '')), 'employee_code' => (string) ($row->employee_code ?? ''), 'method' => (string) $row->method,
            'method_label' => self::METHOD_LABELS[$row->method] ?? (string) $row->method, 'amount_cents' => (int) $row->amount_cents, 'bank_name' => $row->bank_name, 'bank_account_mask' => $row->bank_account_mask,
            'status' => (string) $row->status, 'status_label' => self::ORDER_STATUS_LABELS[$row->status] ?? (string) $row->status, 'paid_on' => $row->paid_on, 'reference' => $row->reference,
            'failure_reason' => $row->failure_reason, 'payment_instruction_id' => $row->payment_instruction_id === null ? null : (int) $row->payment_instruction_id,
        ];
    }
}
