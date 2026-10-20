<?php

declare(strict_types=1);

namespace App\Services\Fel;

use App\Jobs\CertifyFelDocumentJob;
use App\Models\FelDocument;
use App\Models\FelDocumentLine;
use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

class FelDocumentService
{
    public function __construct(private AccountingService $accounting, private FelCalculator $calculator,
        private FelProviderResolver $providers, private FelAudit $audit, private FelIssuerSettings $issuers) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'issued_on' => ['required', 'date_format:Y-m-d'],
            'currency' => ['required', 'in:GTQ'], 'document_type' => ['required', 'in:FACT,NCRE'],
            'sales_invoice_id' => ['nullable', 'required_if:document_type,NCRE', 'integer'],
            'customer_id' => ['required', 'integer'],
            'receiver_name' => ['required', 'string', 'max:150'], 'receiver_nit' => ['required', 'string', 'max:30'],
            'receiver_address' => ['required', 'string', 'max:255'], 'receiver_email' => ['nullable', 'email', 'max:255'],
            'observations' => ['nullable', 'string', 'max:2000'], 'intent' => ['required', 'in:draft,issue'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.item' => ['required', 'string', 'max:150'], 'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.classification' => ['required', 'in:goods,service'],
            'lines.*.quantity' => ['required', 'regex:/^\d{1,4}(?:\.\d{1,4})?$/D'],
            'lines.*.price' => ['required', 'regex:/^\d{1,6}(?:\.\d{1,2})?$/D'],
            'lines.*.discount' => ['required', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/D'],
        ];
    }

    /** @param array<string, mixed> $input */
    public function save(Organization $organization, User $user, stdClass $company, array $input, ?string $uuid = null): FelDocument
    {
        $data = Validator::make($input, self::rules())->validate();

        return DB::transaction(function () use ($organization, $user, $company, $data, $uuid): FelDocument {
            $this->accounting->lock($company->id);
            $this->accounting->company($organization, $user, $company->public_id, true);
            $issuer = $this->issuers->forCompany($company);
            $this->providers->resolve($issuer['provider'], $issuer['environment']);
            DB::table('sales_customers')->where('company_id', $company->id)->where('id', $data['customer_id'])->firstOrFail();
            $salesInvoiceId = null;
            if ($data['document_type'] === 'NCRE') {
                // An FEL credit note always credits an issued internal invoice of the same customer, never more than its balance.
                $invoice = DB::table('sales_invoices')->where('company_id', $company->id)->where('id', $data['sales_invoice_id'])->where('status', 'issued')->where('sales_customer_id', $data['customer_id'])->first(['id', 'total_cents', 'issued_on']);
                if ($invoice === null) {
                    throw ValidationException::withMessages(['sales_invoice_id' => 'Elige una factura emitida del mismo cliente para la nota de crédito.']);
                }
                if ($data['issued_on'] < (string) $invoice->issued_on) {
                    throw ValidationException::withMessages(['issued_on' => 'La nota de crédito no puede ser anterior a la factura que acredita.']);
                }
                $salesInvoiceId = (int) $invoice->id;
            }
            $doc = $uuid ? FelDocument::forCompany($company->id)->where('public_id', $uuid)->lockForUpdate()->firstOrFail() : new FelDocument;
            if ($doc->status !== FelDocumentStatus::Draft) {
                throw ValidationException::withMessages(['document' => 'Solo se pueden modificar borradores.']);
            }
            $calculated = $this->calculator->calculate($company->id, $data['issued_on'], $data['lines']);
            if ($salesInvoiceId !== null) {
                $balance = (int) DB::table('sales_invoices')->where('id', $salesInvoiceId)->value('total_cents')
                    - (int) DB::table('sales_receipts')->where('sales_invoice_id', $salesInvoiceId)->whereNull('voided_at')->sum('amount_cents')
                    - (int) DB::table('sales_credit_notes')->where('sales_invoice_id', $salesInvoiceId)->where('status', 'issued')->sum('amount_cents')
                    - (int) DB::table('receivable_adjustments')->where('sales_invoice_id', $salesInvoiceId)->where('status', 'approved')->sum('amount_cents');
                if ($calculated['total_cents'] > $balance) {
                    throw ValidationException::withMessages(['sales_invoice_id' => 'La nota de crédito no puede superar el saldo pendiente de la factura (Q '.number_format(max(0, $balance) / 100, 2).').']);
                }
            }
            $new = ! $doc->exists;
            if ($new) {
                DB::table('fel_sequences')->insertOrIgnore(['company_id' => $company->id, 'next_number' => 1]);
                $number = (int) DB::table('fel_sequences')->where('company_id', $company->id)->value('next_number');
                DB::table('fel_sequences')->where('company_id', $company->id)->increment('next_number');
                $doc->forceFill(['public_id' => (string) Str::uuid(), 'organization_id' => $organization->id,
                    'company_id' => $company->id, 'created_by' => $user->id, 'series' => 'INT', 'number' => $number,
                    'provider' => $issuer['provider'], 'environment' => $issuer['environment']]);
            }
            $doc->forceFill([
                'customer_id' => $data['customer_id'], 'issued_on' => $data['issued_on'], 'currency' => $data['currency'],
                'document_type' => $data['document_type'], 'sales_invoice_id' => $salesInvoiceId, 'observations' => $data['observations'] ?? null,
                'receiver_snapshot' => ['name' => $data['receiver_name'], 'nit' => $data['receiver_nit'],
                    'address' => $data['receiver_address'], 'email' => $data['receiver_email'] ?? null],
                'fiscal_snapshot' => [...$issuer, 'profile' => $calculated['fiscal_profile']],
                'subtotal_cents' => $calculated['subtotal_cents'], 'discount_cents' => $calculated['discount_cents'],
                'tax_cents' => $calculated['tax_cents'], 'total_cents' => $calculated['total_cents'],
            ])->save();
            $doc->lines()->delete();
            foreach ($calculated['lines'] as $line) {
                $record = new FelDocumentLine;
                $record->forceFill([...$line, 'fel_document_id' => $doc->id])->save();
            }
            $this->audit->record($doc, $new ? 'created' : 'draft_updated', $user->id);
            if ($data['intent'] === 'issue') {
                $this->requestCertification($doc, $user->id);
            }

            return $doc->fresh();
        }, attempts: 3);
    }

    public function requestCertification(FelDocument $document, int $actorId): void
    {
        $this->providers->resolve($document->provider, $document->environment);
        DB::transaction(function () use ($document, $actorId): void {
            $this->accounting->lock($document->company_id);
            $doc = FelDocument::forCompany($document->company_id)->whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($doc->status === FelDocumentStatus::Draft) {
                $company = DB::table('companies')->where('id', $doc->company_id)->firstOrFail();
                $issuer = $this->issuers->forCompany($company);
                $data = [];
                foreach ($doc->lines as $line) {
                    $data[] = [
                        'item' => $line->item, 'description' => $line->description, 'classification' => $line->classification,
                        'quantity' => $line->quantity, 'price' => bcdiv((string) $line->unit_cents, '100', 2),
                        'discount' => bcdiv((string) $line->discount_cents, '100', 2),
                    ];
                }
                $current = $this->calculator->calculate($doc->company_id, $doc->issued_on->toDateString(), $data);
                if ($current['total_cents'] !== $doc->total_cents || $issuer['nit'] !== $doc->fiscal_snapshot['nit']
                    || $issuer['name'] !== $doc->fiscal_snapshot['name']
                    || $issuer['address'] !== ($doc->fiscal_snapshot['address'] ?? '')
                    || $issuer['logo_data_uri'] !== ($doc->fiscal_snapshot['logo_data_uri'] ?? null)
                    || $current['fiscal_profile']['vat_regime'] !== $doc->fiscal_snapshot['profile']['vat_regime']) {
                    throw ValidationException::withMessages(['document' => 'La configuración fiscal cambió. Revisa y guarda nuevamente el borrador.']);
                }
                $doc->provider_payload = null;
            }
            if ($doc->provider === FelProviderResolver::SAT_MANUAL) {
                // Issued by hand in the SAT portal: it waits here until someone registers what the SAT issued.
                $doc->transition(FelDocumentStatus::AwaitingSat);
                $doc->forceFill(['error_code' => null, 'awaiting_sat_at' => now(), 'sat_alerted_at' => null])->save();
                $this->audit->record($doc, 'awaiting_sat', $actorId);

                return;
            }
            $doc->transition(FelDocumentStatus::Pending);
            $doc->forceFill(['error_code' => null])->save();
            $this->audit->record($doc, 'certification_requested', $actorId);
            CertifyFelDocumentJob::dispatch($doc->company_id, $doc->public_id)->afterCommit();
        });
    }
}
