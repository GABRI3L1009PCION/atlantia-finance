<?php

declare(strict_types=1);

namespace App\Services\Receivables;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Receivables sub-ledger against the general ledger for one company at a cutoff. The control account is the company's
 * receivable account in its accounting mapping (never a hardcoded code). Each invoice, receipt and credit note is
 * compared with what its own posted entries left in that account, and any posted line in the account without a
 * receivables source is listed, so the difference always has a drill-down. It is computed live on every read, so
 * it is never stale, and there is no way to force it to match: the source has to be corrected.
 *
 * @phpstan-type ReconciliationItem array{kind: string, document: string, customer: string|null, date: string, expected_cents: int, ledger_cents: int, difference_cents: int, reason: string, destination: string|null}
 */
class ReceivablesLedgerReconciliationService
{
    public const STATUS_LABELS = ['matched' => 'Cuadra', 'difference' => 'Con diferencia', 'not_applicable' => 'No aplica', 'error' => 'No fue posible verificar'];

    /**
     * @return array{status: string, status_label: string, cutoff: string, control_account: string|null, auxiliary_cents: int|null, ledger_cents: int|null, difference_cents: int|null, items: list<ReconciliationItem>, message: string|null, calculated_at: string}
     */
    public function compute(int $companyId, string $cutoff): array
    {
        $base = ['cutoff' => $cutoff, 'calculated_at' => now()->toIso8601String()];
        try {
            $account = DB::table('company_accounting_mappings')->join('ledger_accounts', 'ledger_accounts.id', '=', 'company_accounting_mappings.receivable_account_id')
                ->where('company_accounting_mappings.company_id', $companyId)->first(['ledger_accounts.id', 'ledger_accounts.code', 'ledger_accounts.name']);
            if ($account === null) {
                return [...$base, 'status' => 'not_applicable', 'status_label' => self::STATUS_LABELS['not_applicable'], 'control_account' => null, 'auxiliary_cents' => null, 'ledger_cents' => null, 'difference_cents' => null, 'items' => [], 'message' => 'La empresa no tiene cuenta de control de CxC en su mapeo contable.'];
            }
            $company = DB::table('companies')->where('id', $companyId)->first(['public_id']);
            $posted = DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                ->where('journal_lines.ledger_account_id', $account->id)->whereIn('journal_entries.status', ['posted', 'reversed'])->where('journal_entries.entry_date', '<=', $cutoff);
            $ledger = (int) (clone $posted)->selectRaw('CAST(COALESCE(SUM(journal_lines.debit_cents - journal_lines.credit_cents), 0) AS BIGINT) as balance')->value('balance');
            $bySource = (clone $posted)->join('accounting_source_entries as source', 'source.journal_entry_id', '=', 'journal_entries.id')
                ->whereIn('source.source_type', ['sales_invoice', 'sales_receipt', 'sales_credit_note', 'receivable_adjustment'])
                ->groupBy('source.source_type', 'source.source_id')->selectRaw('source.source_type, source.source_id, CAST(SUM(journal_lines.debit_cents - journal_lines.credit_cents) AS BIGINT) as amount')
                ->get()->mapWithKeys(fn ($row): array => [$row->source_type.':'.$row->source_id => (int) $row->amount])->all();

            $documents = [];
            $voidedAfter = fn (?string $voidedAt): bool => $voidedAt === null || Carbon::parse($voidedAt)->toDateString() > $cutoff;
            foreach (DB::table('sales_invoices')->where('company_id', $companyId)->whereIn('status', ['issued', 'void'])->where('issued_on', '<=', $cutoff)->get(['id', 'public_id', 'number', 'customer_name', 'issued_on', 'total_cents', 'status', 'voided_at']) as $invoice) {
                $documents['sales_invoice:'.$invoice->id] = ['kind' => 'Factura', 'document' => 'Factura #'.$invoice->number, 'customer' => $invoice->customer_name, 'date' => (string) $invoice->issued_on,
                    'expected_cents' => $invoice->status === 'issued' || $voidedAfter($invoice->voided_at) ? (int) $invoice->total_cents : 0, 'destination' => '/receivables/documents/'.$invoice->id];
            }
            foreach (DB::table('sales_receipts')->join('sales_invoices', 'sales_invoices.id', '=', 'sales_receipts.sales_invoice_id')->where('sales_invoices.company_id', $companyId)->where('sales_receipts.received_on', '<=', $cutoff)
                ->get(['sales_receipts.id', 'sales_receipts.amount_cents', 'sales_receipts.received_on', 'sales_receipts.reference', 'sales_receipts.voided_at', 'sales_invoices.id as invoice_id', 'sales_invoices.number', 'sales_invoices.customer_name']) as $receipt) {
                $documents['sales_receipt:'.$receipt->id] = ['kind' => 'Cobro', 'document' => 'Cobro '.$receipt->reference.' · factura #'.$receipt->number, 'customer' => $receipt->customer_name, 'date' => (string) $receipt->received_on,
                    'expected_cents' => $voidedAfter($receipt->voided_at) ? -(int) $receipt->amount_cents : 0, 'destination' => '/receivables/documents/'.$receipt->invoice_id];
            }
            foreach (DB::table('sales_credit_notes')->join('sales_invoices', 'sales_invoices.id', '=', 'sales_credit_notes.sales_invoice_id')->where('sales_credit_notes.company_id', $companyId)->where('sales_credit_notes.issued_on', '<=', $cutoff)
                ->get(['sales_credit_notes.id', 'sales_credit_notes.number as note_number', 'sales_credit_notes.amount_cents', 'sales_credit_notes.issued_on', 'sales_credit_notes.status', 'sales_credit_notes.voided_at', 'sales_invoices.id as invoice_id', 'sales_invoices.number', 'sales_invoices.customer_name']) as $note) {
                $documents['sales_credit_note:'.$note->id] = ['kind' => 'Nota de crédito', 'document' => 'Nota de crédito '.$note->note_number.' · factura #'.$note->number, 'customer' => $note->customer_name, 'date' => (string) $note->issued_on,
                    'expected_cents' => $note->status === 'issued' || $voidedAfter($note->voided_at) ? -(int) $note->amount_cents : 0, 'destination' => '/receivables/documents/'.$note->invoice_id];
            }

            foreach (DB::table('receivable_adjustments')->join('sales_invoices', 'sales_invoices.id', '=', 'receivable_adjustments.sales_invoice_id')->where('receivable_adjustments.company_id', $companyId)->where('receivable_adjustments.status', 'approved')
                ->whereDate('receivable_adjustments.reviewed_at', '<=', $cutoff)->get(['receivable_adjustments.id', 'receivable_adjustments.kind', 'receivable_adjustments.amount_cents', 'receivable_adjustments.reviewed_at', 'sales_invoices.id as invoice_id', 'sales_invoices.number', 'sales_invoices.customer_name']) as $adjustment) {
                $documents['receivable_adjustment:'.$adjustment->id] = ['kind' => 'Ajuste', 'document' => (ReceivableAdjustmentService::KINDS[$adjustment->kind] ?? 'Ajuste').' · factura #'.$adjustment->number, 'customer' => $adjustment->customer_name,
                    'date' => Carbon::parse($adjustment->reviewed_at)->toDateString(), 'expected_cents' => -(int) $adjustment->amount_cents, 'destination' => '/receivables/documents/'.$adjustment->invoice_id];
            }

            $items = [];
            foreach ($documents as $key => $document) {
                $inLedger = $bySource[$key] ?? 0;
                if ($inLedger === $document['expected_cents']) {
                    continue;
                }
                [$type, $id] = explode(':', $key);
                $entry = DB::table('accounting_source_entries')->join('journal_entries', 'journal_entries.id', '=', 'accounting_source_entries.journal_entry_id')
                    ->where('accounting_source_entries.company_id', $companyId)->where('accounting_source_entries.source_type', $type)->where('accounting_source_entries.source_id', (int) $id)->where('accounting_source_entries.event', 'origin')
                    ->first(['journal_entries.status', 'journal_entries.public_id']);
                $items[] = [
                    ...$document,
                    'ledger_cents' => $inLedger,
                    'difference_cents' => $document['expected_cents'] - $inLedger,
                    'reason' => match (true) {
                        $entry === null => 'Sin asiento contable',
                        ! in_array($entry->status, ['posted', 'reversed'], true) => 'Asiento pendiente de contabilizar',
                        default => 'El asiento no coincide con el documento',
                    },
                    'destination' => $entry === null || $company === null ? $document['destination'] : '/accounting/companies/'.$company->public_id.'?entry='.$entry->public_id,
                ];
            }
            foreach ((clone $posted)->leftJoin('accounting_source_entries as source', function ($join): void {
                $join->on('source.journal_entry_id', '=', 'journal_entries.id')->whereIn('source.source_type', ['sales_invoice', 'sales_receipt', 'sales_credit_note', 'receivable_adjustment']);
            })->whereNull('source.id')->orderBy('journal_entries.entry_date')->limit(200)
                ->get(['journal_entries.source_type', 'journal_entries.public_id', 'journal_entries.number', 'journal_entries.entry_date', 'journal_entries.description', 'journal_lines.debit_cents', 'journal_lines.credit_cents']) as $line) {
                $amount = (int) $line->debit_cents - (int) $line->credit_cents;
                $items[] = ['kind' => 'Asiento', 'document' => 'Asiento #'.$line->number.' · '.$line->description, 'customer' => null, 'date' => (string) $line->entry_date, 'expected_cents' => 0, 'ledger_cents' => $amount, 'difference_cents' => -$amount,
                    'reason' => $line->source_type === 'fel_document' ? 'Factura FEL contabilizada sin factura interna en Ventas' : 'Movimiento en la cuenta de CxC sin documento de cartera', 'destination' => $company === null ? null : '/accounting/companies/'.$company->public_id.'?entry='.$line->public_id];
            }
            usort($items, fn (array $a, array $b): int => abs($b['difference_cents']) <=> abs($a['difference_cents']));
            $auxiliary = array_sum(array_column($documents, 'expected_cents'));
            $difference = $auxiliary - $ledger;
            $status = match (true) {
                $documents === [] && $items === [] => 'not_applicable',
                $difference === 0 && $items === [] => 'matched',
                default => 'difference',
            };

            return [...$base, 'status' => $status, 'status_label' => self::STATUS_LABELS[$status], 'control_account' => $account->code.' '.$account->name, 'auxiliary_cents' => $auxiliary, 'ledger_cents' => $ledger, 'difference_cents' => $difference, 'items' => $items,
                'message' => $status === 'matched' ? 'CxC coincide con el Libro Mayor.' : null];
        } catch (Throwable $exception) {
            Log::warning('Receivables reconciliation failed', ['company_id' => $companyId, 'error' => $exception->getMessage()]);

            return [...$base, 'status' => 'error', 'status_label' => self::STATUS_LABELS['error'], 'control_account' => null, 'auxiliary_cents' => null, 'ledger_cents' => null, 'difference_cents' => null, 'items' => [], 'message' => 'No fue posible verificar CxC contra Mayor.'];
        }
    }
}
