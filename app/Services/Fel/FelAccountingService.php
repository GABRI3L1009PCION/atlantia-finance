<?php

declare(strict_types=1);

namespace App\Services\Fel;

use App\Models\FelDocument;
use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingAutomationService;
use App\Services\AccountingService;
use App\Services\FiscalConfigurationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FelAccountingService
{
    public function __construct(
        private AccountingService $accounting,
        private AccountingAutomationService $automation,
        private FiscalConfigurationService $fiscal,
    ) {}

    public function draft(FelDocument $document, Organization $organization, User $user): int
    {
        return DB::transaction(function () use ($document, $organization, $user): int {
            $this->accounting->lock($document->company_id);
            $doc = FelDocument::forCompany($document->company_id)->whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($doc->status !== FelDocumentStatus::Certified) {
                throw ValidationException::withMessages(['accounting' => 'Solo una factura certificada puede originar un asiento.']);
            }
            if ($doc->document_type === 'NCRE') {
                throw ValidationException::withMessages(['accounting' => 'La nota de crédito FEL se contabilizó al certificarse contra la factura que acredita.']);
            }
            $mapping = $this->automation->ensureMapping($doc->company_id);
            $vatAccount = null;
            if ($doc->tax_cents > 0) {
                // The account configured on the VAT tax wins; 2201 is the starter chart fallback.
                $this->fiscal->linkDefaultAccounts($doc->company_id);
                $vatAccount = $this->fiscal->vatRule($doc->company_id, $doc->issued_on->toDateString())->payable_account_id
                    ?? DB::table('ledger_accounts')->where('company_id', $doc->company_id)->where('code', '2201')->value('id');
            }
            if (! $mapping || ($doc->tax_cents > 0 && ! $vatAccount)) {
                throw ValidationException::withMessages(['accounting' => 'Configura las cuentas contables, incluida la de IVA débito fiscal en Parámetros fiscales.']);
            }
            $amount = fn (int $value): string => intdiv($value, 100).'.'.str_pad((string) ($value % 100), 2, '0', STR_PAD_LEFT);
            $lines = [
                ['account_id' => $mapping->receivable_account_id, 'debit' => $amount($doc->total_cents), 'credit' => '0'],
                ['account_id' => $mapping->revenue_account_id, 'debit' => '0', 'credit' => $amount($doc->subtotal_cents - $doc->discount_cents)],
            ];
            if ($doc->tax_cents > 0) {
                $lines[] = ['account_id' => $vatAccount, 'debit' => '0', 'credit' => $amount($doc->tax_cents)];
            }
            $entry = $this->automation->draft($organization, $user, $doc->company_id, 'fel_document', $doc->id,
                $doc->issued_on->toDateString(), 'FEL SIMULADO · '.$doc->series.'-'.$doc->number, $doc->public_id, $lines);
            if (! $entry) {
                throw ValidationException::withMessages(['accounting' => 'Abre un período contable para la fecha de emisión.']);
            }

            return $entry;
        });
    }
}
