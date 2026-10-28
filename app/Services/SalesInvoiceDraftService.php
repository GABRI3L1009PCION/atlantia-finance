<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use App\Services\Fiscal\DocumentVatCalculator;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Drafts a sales invoice (no journal entry): the same rules apply whether it comes from the screen or the public API.
 * The request id makes the call idempotent: repeating it returns the invoice already created.
 */
class SalesInvoiceDraftService
{
    public function __construct(
        private AccountingService $accounting,
        private DocumentVatCalculator $vat,
        private FiscalConfigurationService $fiscal,
    ) {}

    /**
     * @param  array{request_id: string, customer_id: int|string, issued_on: string, due_on?: string|null, currency?: string|null, lines: list<array{description: string, quantity: int|string, unit_price: string, tax_treatment?: string|null}>, fiscal_document_type?: string|null, fiscal_series?: string|null, fiscal_number?: string|null, fiscal_authorization?: string|null, customs_document_type?: string|null, customs_document_number?: string|null}  $data
     * @param  array<string, mixed>  $auditContext
     * @return array{id: int, created: bool}
     */
    public function create(Organization $organization, User $user, stdClass $company, array $data, array $auditContext = []): array
    {
        return DB::transaction(function () use ($organization, $user, $company, $data, $auditContext): array {
            $this->accounting->lock((int) $company->id);
            $existing = DB::table('sales_invoices')->where('company_id', $company->id)->where('public_id', $data['request_id'])->value('id');
            if ($existing !== null) {
                return ['id' => (int) $existing, 'created' => false];
            }
            $this->ensure(! DB::table('sales_invoices')->where('public_id', $data['request_id'])->exists(), 'Solicitud no válida. Recarga el formulario.');
            // A customer of another company is reported as not found, never revealed.
            $customer = DB::table('sales_customers')->where('company_id', $company->id)->where('id', $data['customer_id'])->first() ?? abort(404, 'El cliente no existe en esta empresa.');
            // The due date comes from the document or, when omitted, from the customer's payment terms.
            if (empty($data['due_on'])) {
                $this->ensure($customer->payment_terms_days !== null, 'Indica el vencimiento o configura el plazo de pago del cliente.');
                $data['due_on'] = Carbon::parse($data['issued_on'])->addDays((int) $customer->payment_terms_days)->toDateString();
            }
            // A foreign-currency sale is converted with the company's registered exchange rate; amounts stay in GTQ.
            $currency = $data['currency'] ?? 'GTQ';
            $rate = $currency === 'GTQ' ? '1' : (string) $this->fiscal->exchangeRate((int) $company->id, $currency, $data['issued_on'])->rate;
            $foreignTotal = 0;
            $profile = $this->fiscal->ensureDefaults((int) $company->id);
            $fiscal = [
                'fiscal_document_type' => $data['fiscal_document_type'] ?? ($profile->vat_regime === 'small_taxpayer' ? 'FPEQ' : 'FACT'),
                'fiscal_series' => self::fiscalCode($data['fiscal_series'] ?? null),
                'fiscal_number' => self::fiscalCode($data['fiscal_number'] ?? null),
                'fiscal_authorization' => self::fiscalCode($data['fiscal_authorization'] ?? null),
            ];
            $this->ensure($fiscal['fiscal_number'] === null || ! DB::table('sales_invoices')
                ->where('company_id', $company->id)->where('fiscal_document_type', $fiscal['fiscal_document_type'])
                ->where('fiscal_number', $fiscal['fiscal_number'])
                ->where(fn ($query) => $fiscal['fiscal_series'] === null ? $query->whereNull('fiscal_series') : $query->where('fiscal_series', $fiscal['fiscal_series']))
                ->exists(), 'Ya existe una venta con esa serie y número de documento fiscal.');
            $total = 0;
            $lines = [];
            foreach ($data['lines'] as $position => $line) {
                $foreignUnit = $this->accounting->cents((string) $line['unit_price']);
                $this->ensure($foreignUnit > 0, 'Cada precio debe ser mayor que cero.');
                $foreignTotal += $foreignUnit * (int) $line['quantity'];
                $unit = $this->fiscal->convertToQuetzales((int) $company->id, $foreignUnit, $currency, $data['issued_on']);
                $total += $unit * (int) $line['quantity'];
                $this->ensure($total <= 100000000000, 'El total supera el máximo permitido: Q1,000,000,000.');
                $lines[] = ['position' => $position + 1, 'description' => $line['description'], 'quantity' => (int) $line['quantity'], 'unit_cents' => $unit,
                    'tax_treatment' => $line['tax_treatment'] ?? 'goods'];
            }
            $breakdown = $this->vat->forSale((int) $company->id, $fiscal['fiscal_document_type'], $data['issued_on'],
                array_map(fn (array $line): array => ['gross_cents' => $line['unit_cents'] * $line['quantity'], 'tax_treatment' => $line['tax_treatment']], $lines));
            foreach ($lines as $index => $line) {
                $lines[$index]['vat_cents'] = $breakdown['line_vat_cents'][$index];
            }
            $id = DB::table('sales_invoices')->insertGetId(['public_id' => $data['request_id'], 'company_id' => $company->id,
                'sales_customer_id' => $customer->id, 'customer_name' => $customer->name, 'customer_tax_id' => $customer->tax_id,
                'issued_on' => $data['issued_on'], 'due_on' => $data['due_on'], 'total_cents' => $total,
                'currency' => $currency, 'exchange_rate' => $rate, 'foreign_total_cents' => $currency === 'GTQ' ? null : $foreignTotal,
                ...$fiscal, ...Arr::except($breakdown, 'line_vat_cents'),
                'customs_document_type' => filled($data['customs_document_number'] ?? null) ? ($data['customs_document_type'] ?? 'DUA') : null,
                'customs_document_number' => filled($data['customs_document_number'] ?? null) ? trim((string) $data['customs_document_number']) : null,
                'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('sales_invoice_lines')->insert(array_map(fn ($line) => [...$line, 'sales_invoice_id' => $id], $lines));
            $this->accounting->audit($organization, $user, 'sales.invoice.drafted', 'sales_invoices', $id, ['total_cents' => $total, ...$auditContext]);

            return ['id' => $id, 'created' => true];
        });
    }

    /** Normalizes a FEL series, number or authorization as printed on the document. */
    public static function fiscalCode(?string $value): ?string
    {
        $value = $value === null ? '' : Str::upper(preg_replace('/\s+/', '', $value) ?? '');

        return $value === '' ? null : $value;
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['sales' => $message]);
        }
    }
}
