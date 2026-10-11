<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The JSON shape of each resource, shared by the public API and the webhook payloads so an integration reads the same
 * object both ways. Money is always integer cents in the company currency (GTQ) plus the original currency data.
 */
class ApiResources
{
    /** @return array<string, mixed> */
    public function company(stdClass $row): array
    {
        return ['id' => (string) $row->public_id, 'name' => (string) $row->name, 'trade_name' => $row->trade_name ?? null, 'tax_id' => (string) $row->tax_id,
            'currency' => (string) $row->currency, 'email' => $row->email ?? null];
    }

    /** @return array<string, mixed> */
    public function customer(stdClass $row): array
    {
        return ['id' => (int) $row->id, 'name' => (string) $row->name, 'tax_id' => $row->tax_id, 'email' => $row->email,
            'payment_terms_days' => $row->payment_terms_days === null ? null : (int) $row->payment_terms_days, 'created_at' => $this->time($row->created_at), 'updated_at' => $this->time($row->updated_at)];
    }

    /** @return array<string, mixed> */
    public function supplier(stdClass $row): array
    {
        return ['id' => (int) $row->id, 'name' => (string) $row->name, 'tax_id' => $row->tax_id, 'email' => $row->email, 'phone' => $row->phone,
            'payment_terms_days' => $row->payment_terms_days === null ? null : (int) $row->payment_terms_days, 'created_at' => $this->time($row->created_at), 'updated_at' => $this->time($row->updated_at)];
    }

    /** @return array<string, mixed> */
    public function salesInvoice(stdClass $row, bool $withLines = false): array
    {
        $paid = (int) DB::table('sales_receipts')->where('sales_invoice_id', $row->id)->whereNull('voided_at')->sum('amount_cents');
        $credited = (int) DB::table('sales_credit_notes')->where('sales_invoice_id', $row->id)->where('status', 'issued')->sum('amount_cents');
        $resource = ['id' => (string) $row->public_id, 'number' => $row->number === null ? null : (int) $row->number, 'status' => (string) $row->status,
            'customer' => ['id' => (int) $row->sales_customer_id, 'name' => (string) $row->customer_name, 'tax_id' => $row->customer_tax_id],
            'issued_on' => (string) $row->issued_on, 'due_on' => (string) $row->due_on, 'currency' => (string) $row->currency, 'exchange_rate' => (string) $row->exchange_rate,
            'total_cents' => (int) $row->total_cents, 'foreign_total_cents' => $row->foreign_total_cents === null ? null : (int) $row->foreign_total_cents, 'vat_cents' => (int) $row->vat_cents,
            'paid_cents' => $paid, 'credited_cents' => $credited, 'balance_cents' => $row->status === 'issued' ? max(0, (int) $row->total_cents - $paid - $credited) : 0,
            'fiscal' => $this->fiscal($row), 'issued_at' => $this->time($row->issued_at), 'voided_at' => $this->time($row->voided_at), 'void_reason' => $row->void_reason,
            'created_at' => $this->time($row->created_at), 'updated_at' => $this->time($row->updated_at)];
        if ($withLines) {
            $resource['lines'] = DB::table('sales_invoice_lines')->where('sales_invoice_id', $row->id)->orderBy('position')->get()
                ->map(fn (stdClass $line): array => ['position' => (int) $line->position, 'description' => (string) $line->description, 'quantity' => (int) $line->quantity,
                    'unit_cents' => (int) $line->unit_cents, 'total_cents' => (int) $line->unit_cents * (int) $line->quantity, 'vat_cents' => (int) $line->vat_cents, 'tax_treatment' => (string) $line->tax_treatment])->all();
        }

        return $resource;
    }

    /** @return array<string, mixed> */
    public function purchaseDocument(stdClass $row, bool $withLines = false): array
    {
        $paid = (int) DB::table('purchase_payments')->where('purchase_document_id', $row->id)->whereNull('voided_at')->sum('amount_cents');
        $resource = ['id' => (string) $row->public_id, 'number' => $row->number === null ? null : (int) $row->number, 'document_type' => (string) $row->document_type, 'status' => (string) $row->status,
            'supplier' => ['id' => (int) $row->purchase_supplier_id, 'name' => (string) $row->supplier_name, 'tax_id' => $row->supplier_tax_id], 'external_reference' => $row->external_reference,
            'document_date' => (string) $row->document_date, 'due_on' => (string) $row->due_on, 'currency' => (string) $row->currency, 'exchange_rate' => (string) $row->exchange_rate,
            'total_cents' => (int) $row->total_cents, 'foreign_total_cents' => $row->foreign_total_cents === null ? null : (int) $row->foreign_total_cents, 'vat_cents' => (int) $row->vat_cents,
            'paid_cents' => $paid, 'balance_cents' => $row->status === 'approved' ? max(0, (int) $row->total_cents - $paid) : 0, 'fiscal' => $this->fiscal($row),
            'approved_at' => $this->time($row->approved_at), 'voided_at' => $this->time($row->voided_at), 'void_reason' => $row->void_reason,
            'created_at' => $this->time($row->created_at), 'updated_at' => $this->time($row->updated_at)];
        if ($withLines) {
            $resource['lines'] = DB::table('purchase_document_lines')->leftJoin('ledger_accounts', 'ledger_accounts.id', '=', 'purchase_document_lines.ledger_account_id')
                ->where('purchase_document_id', $row->id)->orderBy('position')->get(['purchase_document_lines.*', 'ledger_accounts.code as account_code', 'ledger_accounts.name as account_name'])
                ->map(fn (stdClass $line): array => ['position' => (int) $line->position, 'account' => ['id' => (int) $line->ledger_account_id, 'code' => $line->account_code, 'name' => $line->account_name],
                    'description' => (string) $line->description, 'quantity' => (int) $line->quantity, 'unit_cents' => (int) $line->unit_cents,
                    'total_cents' => (int) $line->unit_cents * (int) $line->quantity, 'vat_cents' => (int) $line->vat_cents, 'tax_treatment' => (string) $line->tax_treatment])->all();
        }

        return $resource;
    }

    /** @return array<string, mixed> */
    public function salesReceipt(stdClass $row): array
    {
        return ['id' => (string) $row->public_id, 'invoice_id' => (string) DB::table('sales_invoices')->where('id', $row->sales_invoice_id)->value('public_id'),
            'amount_cents' => (int) $row->amount_cents, 'received_on' => (string) $row->received_on, 'reference' => $row->reference,
            'voided_at' => $this->time($row->voided_at), 'created_at' => $this->time($row->created_at)];
    }

    /** @return array<string, mixed> */
    public function purchasePayment(stdClass $row): array
    {
        return ['id' => (string) $row->public_id, 'purchase_document_id' => (string) DB::table('purchase_documents')->where('id', $row->purchase_document_id)->value('public_id'),
            'amount_cents' => (int) $row->amount_cents, 'paid_on' => (string) $row->paid_on, 'reference' => $row->reference,
            'voided_at' => $this->time($row->voided_at), 'created_at' => $this->time($row->created_at)];
    }

    /** @return array<string, mixed> */
    public function creditNote(stdClass $row): array
    {
        return ['id' => (string) $row->public_id, 'invoice_id' => (string) DB::table('sales_invoices')->where('id', $row->sales_invoice_id)->value('public_id'),
            'number' => $row->number === null ? null : (int) $row->number, 'status' => (string) $row->status, 'issued_on' => (string) $row->issued_on,
            'amount_cents' => (int) $row->amount_cents, 'vat_cents' => (int) $row->vat_cents, 'reason' => $row->reason, 'created_at' => $this->time($row->created_at)];
    }

    /** @return array<string, mixed> */
    public function felDocument(stdClass $row): array
    {
        $receiver = json_decode((string) $row->receiver_snapshot, true);

        return ['id' => (string) $row->public_id, 'document_type' => (string) $row->document_type, 'status' => (string) $row->status, 'environment' => (string) $row->environment,
            'series' => $row->fel_series ?? $row->series, 'number' => $row->fel_number ?? $row->number, 'authorization_number' => $row->authorization_number,
            'issued_on' => (string) $row->issued_on, 'currency' => (string) $row->currency, 'subtotal_cents' => (int) $row->subtotal_cents, 'tax_cents' => (int) $row->tax_cents, 'total_cents' => (int) $row->total_cents,
            'receiver' => ['name' => is_array($receiver) ? ($receiver['name'] ?? null) : null, 'tax_id' => is_array($receiver) ? ($receiver['tax_id'] ?? $receiver['nit'] ?? null) : null],
            'sales_invoice_id' => $row->sales_invoice_id === null ? null : (string) DB::table('sales_invoices')->where('id', $row->sales_invoice_id)->value('public_id'),
            'certified_at' => $this->time($row->certified_at), 'cancelled_at' => $this->time($row->cancelled_at), 'cancellation_reason' => $row->cancellation_reason];
    }

    /** @return array<string, mixed> */
    public function accountingPeriod(stdClass $row): array
    {
        return ['id' => (int) $row->id, 'name' => (string) $row->name, 'starts_on' => (string) $row->starts_on, 'ends_on' => (string) $row->ends_on,
            'status' => (string) $row->status, 'closed_at' => $this->time($row->closed_at)];
    }

    /** @return array<string, mixed> */
    public function publishedReport(stdClass $row): array
    {
        return ['id' => (string) $row->public_id, 'report_type' => (string) $row->report_type, 'title' => (string) $row->title, 'period' => (string) $row->period_label,
            'period_start' => (string) $row->period_start, 'period_end' => (string) $row->period_end, 'version' => (int) $row->version, 'published_at' => $this->time($row->published_at)];
    }

    /** @return array<string, mixed> */
    public function clientDocument(stdClass $row): array
    {
        return ['id' => (string) $row->public_id, 'title' => (string) $row->title, 'category' => (string) $row->category, 'period' => $row->period_label,
            'status' => (string) $row->status, 'version' => (int) $row->current_version, 'created_at' => $this->time($row->created_at)];
    }

    /** @return array<string, mixed> */
    public function ledgerAccount(stdClass $row): array
    {
        return ['id' => (int) $row->id, 'code' => (string) $row->code, 'name' => (string) $row->name, 'type' => (string) $row->type,
            'is_active' => (bool) $row->is_active, 'accepts_movements' => (bool) $row->accepts_movements, 'is_group' => (bool) $row->is_group];
    }

    /** @return array{document_type: string|null, series: string|null, number: string|null, authorization: string|null} */
    private function fiscal(stdClass $row): array
    {
        return ['document_type' => $row->fiscal_document_type, 'series' => $row->fiscal_series, 'number' => $row->fiscal_number, 'authorization' => $row->fiscal_authorization];
    }

    private function time(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse((string) $value)->toIso8601String();
    }
}
