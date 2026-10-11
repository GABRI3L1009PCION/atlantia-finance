<?php

declare(strict_types=1);

namespace App\Services\Receivables;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingAutomationService;
use App\Services\AccountingService;
use App\Services\Treasury\TreasuryReceiptService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Applies a receipt registered by Treasury (the one shared receipt, never a copy) to open invoices. The application
 * itself, its accounting and the unapplied balance live in the treasury receipt service; this adds the receivables
 * rules (the accounting period must not be closed) and re-evaluates the customer's promises right away.
 */
class ReceiptApplicationService
{
    public function __construct(
        private TreasuryReceiptService $receipts,
        private PromiseToPayService $promises,
        private AccountingService $accounting,
        private AccountingAutomationService $automation,
    ) {}

    /**
     * @param  list<array{sales_invoice_id: int, amount_cents: int}>  $allocations
     */
    public function apply(Organization $organization, User $actor, int $receiptId, array $allocations): void
    {
        DB::transaction(function () use ($organization, $actor, $receiptId, $allocations): void {
            $receipt = DB::table('treasury_receipts')->where('treasury_receipts.organization_id', $organization->id)->where('id', $receiptId)->first(['company_id', 'received_on']);
            abort_if($receipt === null, 404);
            $closed = DB::table('accounting_periods')->where('company_id', $receipt->company_id)->where('starts_on', '<=', $receipt->received_on)->where('ends_on', '>=', $receipt->received_on)
                ->whereNotNull('closed_at')->exists();
            if ($closed) {
                throw ValidationException::withMessages(['allocations' => 'El período contable del cobro está cerrado; la aplicación requiere el proceso formal de reapertura o corrección.']);
            }
            $this->receipts->allocate($organization, $actor, $receiptId, $allocations);
            $this->promises->evaluate($organization, [(int) $receipt->company_id]);
        });
    }

    /**
     * Reverts one application of a treasury receipt (sensitive): the application is voided with its reason, its entry
     * reversed, and the amount returns to the receipt's unapplied balance, which is visible again for follow-up.
     */
    public function unapply(Organization $organization, User $actor, int $salesReceiptId, string $reason): int
    {
        return DB::transaction(function () use ($organization, $actor, $salesReceiptId, $reason): int {
            $application = DB::table('sales_receipts')->join('sales_invoices', 'sales_invoices.id', '=', 'sales_receipts.sales_invoice_id')->where('sales_receipts.id', $salesReceiptId)->lockForUpdate()
                ->first(['sales_receipts.*', 'sales_invoices.company_id', 'sales_invoices.number']);
            abort_if($application === null, 404);
            if ($application->treasury_receipt_id === null || $application->voided_at !== null) {
                throw ValidationException::withMessages(['application' => 'Solo se revierten aplicaciones vigentes de cobros de Tesorería.']);
            }
            $receipt = DB::table('treasury_receipts')->where('treasury_receipts.organization_id', $organization->id)->where('id', $application->treasury_receipt_id)->lockForUpdate()->first();
            abort_if($receipt === null, 404);
            $closed = DB::table('accounting_periods')->where('company_id', $application->company_id)->where('starts_on', '<=', $application->received_on)->where('ends_on', '>=', $application->received_on)
                ->whereNotNull('closed_at')->exists();
            if ($closed) {
                throw ValidationException::withMessages(['application' => 'El período contable del cobro está cerrado.']);
            }
            $this->automation->reverseSource($organization, $actor, (int) $application->company_id, 'sales_receipt', $salesReceiptId, now()->toDateString(), $reason);
            DB::table('sales_receipts')->where('id', $salesReceiptId)->update(['voided_by' => $actor->id, 'voided_at' => now(), 'void_reason' => $reason, 'updated_at' => now()]);
            $applied = max(0, (int) $receipt->applied_cents - (int) $application->amount_cents);
            $unapplied = (int) $receipt->amount_cents - $applied;
            DB::table('treasury_receipts')->where('id', $receipt->id)->update(['applied_cents' => $applied, 'unapplied_cents' => $unapplied, 'status' => $applied === 0 ? 'unapplied' : 'partially_applied', 'updated_at' => now()]);
            $key = 'unapplied_receipt:'.$receipt->id;
            $incident = DB::table('operational_incidents')->where('operational_incidents.organization_id', $organization->id)->where('automation_key', $key)->first(['id']);
            if ($incident !== null) {
                DB::table('operational_incidents')->where('id', $incident->id)->update(['status' => 'open', 'resolved_at' => null, 'resolved_by' => null, 'resolution' => null, 'updated_at' => now()]);
            } else {
                DB::table('operational_incidents')->insert(['public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'office_id' => $receipt->office_id, 'company_id' => $receipt->company_id, 'type' => 'accounting', 'severity' => 'medium', 'status' => 'open',
                    'title' => 'Cobro sin aplicar: '.$receipt->reference, 'description' => 'Q '.number_format($unapplied / 100, 2).' devueltos al saldo sin aplicar por reversión.', 'blocks_period' => false, 'responsible_id' => $actor->id,
                    'source_type' => 'treasury_receipts', 'source_id' => $receipt->id, 'automation_key' => $key, 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->accounting->audit($organization, $actor, 'receipt.unapplied', 'treasury_receipts', (int) $receipt->id, ['invoice' => $application->number, 'amount_cents' => (int) $application->amount_cents, 'reason' => $reason], $receipt->office_id === null ? null : (int) $receipt->office_id);

            return (int) $application->sales_invoice_id;
        });
    }
}
