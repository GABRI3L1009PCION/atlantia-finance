<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use App\Services\Fiscal\DocumentVatCalculator;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Drafts a purchase or expense document (no journal entry) with the same rules for the screen and the public API.
 * The request id makes the call idempotent.
 */
class PurchaseDocumentDraftService
{
    public function __construct(
        private AccountingService $accounting,
        private DocumentVatCalculator $vat,
        private FiscalConfigurationService $fiscal,
    ) {}

    /**
     * @param  array{request_id: string, supplier_id: int|string, document_type: string, external_reference?: string|null, document_date: string, due_on?: string|null, currency?: string|null, lines: list<array{account_id: int|string, description: string, quantity: int|string, unit_price: string, tax_treatment?: string|null}>, fiscal_document_type?: string|null, fiscal_series?: string|null, fiscal_number?: string|null, fiscal_authorization?: string|null}  $data
     * @param  array<string, mixed>  $auditContext
     * @return array{id: int, created: bool}
     */
    public function create(Organization $organization, User $user, stdClass $company, array $data, array $auditContext = []): array
    {
        return DB::transaction(function () use ($organization, $user, $company, $data, $auditContext): array {
            $this->accounting->lock((int) $company->id);
            $existing = DB::table('purchase_documents')->where('company_id', $company->id)->where('public_id', $data['request_id'])->value('id');
            if ($existing !== null) {
                return ['id' => (int) $existing, 'created' => false];
            }
            $this->ensure(! DB::table('purchase_documents')->where('public_id', $data['request_id'])->exists(), 'Solicitud inválida. Recarga la página.');
            // A supplier of another company is reported as not found, never revealed.
            $supplier = DB::table('purchase_suppliers')->where('company_id', $company->id)->where('id', $data['supplier_id'])->first() ?? abort(404, 'El proveedor no existe en esta empresa.');
            // The due date comes from the document or, when omitted, from the supplier's payment terms.
            if (empty($data['due_on'])) {
                $this->ensure($supplier->payment_terms_days !== null, 'Indica el vencimiento o configura el plazo de pago del proveedor.');
                $data['due_on'] = Carbon::parse($data['document_date'])->addDays((int) $supplier->payment_terms_days)->toDateString();
            }
            // A foreign-currency purchase is converted with the company's registered exchange rate; amounts stay in GTQ.
            $currency = $data['currency'] ?? 'GTQ';
            $rate = $currency === 'GTQ' ? '1' : (string) $this->fiscal->exchangeRate((int) $company->id, $currency, $data['document_date'])->rate;
            $foreignTotal = 0;
            $accountIds = DB::table('ledger_accounts')->where('company_id', $company->id)->whereIn('type', ['asset', 'expense'])->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $fiscal = [
                'fiscal_document_type' => $data['fiscal_document_type'] ?? 'FACT',
                'fiscal_series' => SalesInvoiceDraftService::fiscalCode($data['fiscal_series'] ?? null),
                'fiscal_number' => SalesInvoiceDraftService::fiscalCode($data['fiscal_number'] ?? null),
                'fiscal_authorization' => SalesInvoiceDraftService::fiscalCode($data['fiscal_authorization'] ?? null),
            ];
            $this->ensure($fiscal['fiscal_number'] === null || ! DB::table('purchase_documents')
                ->where('company_id', $company->id)->where('purchase_supplier_id', $supplier->id)->where('status', '!=', 'void')
                ->where('fiscal_document_type', $fiscal['fiscal_document_type'])->where('fiscal_number', $fiscal['fiscal_number'])
                ->where(fn ($query) => $fiscal['fiscal_series'] === null ? $query->whereNull('fiscal_series') : $query->where('fiscal_series', $fiscal['fiscal_series']))
                ->exists(), 'Ese documento fiscal del proveedor ya está registrado. Registrarlo de nuevo duplicaría el crédito fiscal.');
            $total = 0;
            $lines = [];
            foreach ($data['lines'] as $position => $line) {
                $this->ensure(in_array((int) $line['account_id'], $accountIds, true), 'Cada línea debe usar una cuenta de activo o gasto de esta empresa.');
                $foreignUnit = $this->accounting->cents((string) $line['unit_price']);
                $this->ensure($foreignUnit > 0, 'Cada precio debe ser mayor que cero.');
                $foreignTotal += $foreignUnit * (int) $line['quantity'];
                $unit = $this->fiscal->convertToQuetzales((int) $company->id, $foreignUnit, $currency, $data['document_date']);
                $total += $unit * (int) $line['quantity'];
                $this->ensure($total <= 100000000000, 'El total supera el máximo permitido.');
                $lines[] = ['ledger_account_id' => (int) $line['account_id'], 'position' => $position + 1,
                    'description' => $line['description'], 'quantity' => (int) $line['quantity'], 'unit_cents' => $unit,
                    'tax_treatment' => $line['tax_treatment'] ?? 'goods'];
            }
            $breakdown = $this->vat->forPurchase((int) $company->id, $fiscal['fiscal_document_type'], $data['document_date'],
                array_map(fn (array $line): array => ['gross_cents' => $line['unit_cents'] * $line['quantity'], 'tax_treatment' => $line['tax_treatment']], $lines));
            foreach ($lines as $index => $line) {
                $lines[$index]['vat_cents'] = $breakdown['line_vat_cents'][$index];
            }
            $id = DB::table('purchase_documents')->insertGetId([
                'public_id' => $data['request_id'], 'company_id' => $company->id, 'purchase_supplier_id' => $supplier->id,
                'document_type' => $data['document_type'], 'external_reference' => filled($data['external_reference'] ?? null) ? trim((string) $data['external_reference']) : null,
                'document_date' => $data['document_date'], 'due_on' => $data['due_on'], 'total_cents' => $total,
                'currency' => $currency, 'exchange_rate' => $rate, 'foreign_total_cents' => $currency === 'GTQ' ? null : $foreignTotal,
                'supplier_name' => $supplier->name, 'supplier_tax_id' => $supplier->tax_id, 'created_by' => $user->id,
                ...$fiscal, ...Arr::except($breakdown, 'line_vat_cents'),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('purchase_document_lines')->insert(array_map(fn (array $line): array => [...$line, 'purchase_document_id' => $id], $lines));
            $this->accounting->audit($organization, $user, 'purchase.document.drafted', 'purchase_documents', $id, ['total_cents' => $total, ...$auditContext]);

            return ['id' => $id, 'created' => true];
        });
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['purchases' => $message]);
        }
    }
}
