<?php

declare(strict_types=1);

namespace App\Services\Receivables;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Customer statement for a date range: opening balance, every movement (invoices, receipts, credit notes and their
 * voids) with running balance, closing balance and open documents. The screen, the exports and the e-mailed PDF use
 * this same result, so the figures never differ.
 *
 * @phpstan-type StatementMovement array{date: string, type: string, document: string, reference: string|null, charge_cents: int, credit_cents: int, balance_cents: int}
 */
class CustomerStatementService
{
    public function __construct(private ReceivableService $receivables) {}

    /**
     * @return array{company: array{id: int, name: string, tax_id: string}, customer: array{id: int, name: string, tax_id: string|null, email: string|null}, from: string, to: string, opening_cents: int, movements: list<StatementMovement>, charges_cents: int, credits_cents: int, closing_cents: int, open_documents: list<array<string, mixed>>}
     */
    public function statement(int $companyId, int $customerId, string $from, string $to): array
    {
        $company = DB::table('companies')->where('id', $companyId)->first(['id', 'name', 'tax_id']);
        $customer = DB::table('sales_customers')->where('id', $customerId)->where('company_id', $companyId)->first(['id', 'name', 'tax_id', 'email']);
        abort_if($company === null || $customer === null, 404);

        $events = [];
        $invoices = DB::table('sales_invoices')->where('company_id', $companyId)->where('sales_customer_id', $customerId)->whereIn('status', ['issued', 'void'])->get(['id', 'number', 'issued_on', 'total_cents', 'status', 'voided_at', 'void_reason']);
        foreach ($invoices as $invoice) {
            $events[] = [(string) $invoice->issued_on, 'Factura', 'Factura #'.$invoice->number, null, (int) $invoice->total_cents, 0];
            if ($invoice->status === 'void' && $invoice->voided_at !== null) {
                $events[] = [Carbon::parse($invoice->voided_at)->toDateString(), 'Anulación', 'Factura #'.$invoice->number, $invoice->void_reason, 0, (int) $invoice->total_cents];
            }
        }
        $invoiceIds = $invoices->pluck('id')->all() ?: [-1];
        foreach (DB::table('sales_receipts')->join('sales_invoices', 'sales_invoices.id', '=', 'sales_receipts.sales_invoice_id')->whereIn('sales_receipts.sales_invoice_id', $invoiceIds)
            ->get(['sales_receipts.received_on', 'sales_receipts.reference', 'sales_receipts.amount_cents', 'sales_receipts.voided_at', 'sales_invoices.number']) as $receipt) {
            $events[] = [(string) $receipt->received_on, 'Cobro', 'Aplicado a factura #'.$receipt->number, $receipt->reference, 0, (int) $receipt->amount_cents];
            if ($receipt->voided_at !== null) {
                $events[] = [Carbon::parse($receipt->voided_at)->toDateString(), 'Anulación de cobro', 'Factura #'.$receipt->number, $receipt->reference, (int) $receipt->amount_cents, 0];
            }
        }
        foreach (DB::table('sales_credit_notes')->join('sales_invoices', 'sales_invoices.id', '=', 'sales_credit_notes.sales_invoice_id')->whereIn('sales_credit_notes.sales_invoice_id', $invoiceIds)
            ->get(['sales_credit_notes.number', 'sales_credit_notes.issued_on', 'sales_credit_notes.amount_cents', 'sales_credit_notes.reason', 'sales_credit_notes.voided_at', 'sales_invoices.number as invoice_number']) as $note) {
            $events[] = [(string) $note->issued_on, 'Nota de crédito', 'NC '.$note->number.' · factura #'.$note->invoice_number, $note->reason, 0, (int) $note->amount_cents];
            if ($note->voided_at !== null) {
                $events[] = [Carbon::parse($note->voided_at)->toDateString(), 'Anulación de nota de crédito', 'NC '.$note->number, null, (int) $note->amount_cents, 0];
            }
        }
        foreach (DB::table('receivable_adjustments')->join('sales_invoices', 'sales_invoices.id', '=', 'receivable_adjustments.sales_invoice_id')->whereIn('receivable_adjustments.sales_invoice_id', $invoiceIds)->where('receivable_adjustments.status', 'approved')
            ->get(['receivable_adjustments.kind', 'receivable_adjustments.amount_cents', 'receivable_adjustments.reason', 'receivable_adjustments.reviewed_at', 'sales_invoices.number']) as $adjustment) {
            $events[] = [Carbon::parse($adjustment->reviewed_at)->toDateString(), ReceivableAdjustmentService::KINDS[$adjustment->kind] ?? 'Ajuste', 'Factura #'.$adjustment->number, $adjustment->reason, 0, (int) $adjustment->amount_cents];
        }
        usort($events, fn (array $a, array $b): int => [$a[0], $a[4] === 0 ? 1 : 0] <=> [$b[0], $b[4] === 0 ? 1 : 0]);

        $opening = 0;
        $movements = [];
        $balance = 0;
        foreach ($events as [$date, $type, $document, $reference, $charge, $credit]) {
            if ($date < $from) {
                $opening += $charge - $credit;
                $balance = $opening;

                continue;
            }
            if ($date > $to) {
                continue;
            }
            $balance += $charge - $credit;
            $movements[] = ['date' => $date, 'type' => $type, 'document' => $document, 'reference' => $reference, 'charge_cents' => $charge, 'credit_cents' => $credit, 'balance_cents' => $balance];
        }
        $charges = array_sum(array_column($movements, 'charge_cents'));
        $credits = array_sum(array_column($movements, 'credit_cents'));

        return [
            'company' => ['id' => (int) $company->id, 'name' => (string) $company->name, 'tax_id' => (string) $company->tax_id],
            'customer' => ['id' => (int) $customer->id, 'name' => (string) $customer->name, 'tax_id' => $customer->tax_id, 'email' => $customer->email],
            'from' => $from,
            'to' => $to,
            'opening_cents' => $opening,
            'movements' => $movements,
            'charges_cents' => $charges,
            'credits_cents' => $credits,
            'closing_cents' => $opening + $charges - $credits,
            'open_documents' => $this->receivables->documents([$companyId], ['customer_id' => $customerId]),
        ];
    }

    /**
     * The same statement as an export report (PDF/Excel).
     *
     * @param  array<string, mixed>  $statement
     * @return array{title: string, filename: string, company: string, scope: string, note: string, headers: list<string>, money: list<int>, rows: list<list<mixed>>}
     */
    public function report(array $statement): array
    {
        $rows = [[$statement['from'], 'Saldo inicial', '', '', 0, 0, $statement['opening_cents']]];
        foreach ($statement['movements'] as $movement) {
            $rows[] = [$movement['date'], $movement['type'], $movement['document'], (string) ($movement['reference'] ?? ''), $movement['charge_cents'], $movement['credit_cents'], $movement['balance_cents']];
        }
        $rows[] = [$statement['to'], 'Saldo final', '', '', $statement['charges_cents'], $statement['credits_cents'], $statement['closing_cents']];

        return [
            'title' => 'Estado de cuenta · '.$statement['customer']['name'],
            'filename' => 'estado-cuenta-'.$statement['customer']['id'].'-'.$statement['to'],
            'company' => $statement['company']['name'].' · NIT '.$statement['company']['tax_id'],
            'scope' => 'Cliente: '.$statement['customer']['name'].' · NIT '.($statement['customer']['tax_id'] ?? 'No indicado').' · Del '.Carbon::parse($statement['from'])->format('d/m/Y').' al '.Carbon::parse($statement['to'])->format('d/m/Y'),
            'note' => count($statement['open_documents']).' documento(s) abierto(s) a la fecha de emisión. Montos en GTQ.',
            'headers' => ['Fecha', 'Movimiento', 'Documento', 'Referencia', 'Cargos', 'Abonos', 'Saldo'],
            'money' => [4, 5, 6],
            'rows' => $rows,
        ];
    }
}
