<?php

declare(strict_types=1);

namespace App\Services\Payables;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Payables sub-ledger against the general ledger for one company at a cutoff. The control account is the company's
 * payable account in its accounting mapping (never a hardcoded code). Each purchase, payment, advance, credit note
 * and adjustment is compared with what its own posted entries left in that account (credit balance), and any posted
 * line in the account without a payables source is listed, so every difference has a drill-down. It is computed live
 * on every read (never stale) and there is no way to force it to match: the source has to be corrected.
 */
class PayablesLedgerReconciliationService
{
    public const STATUS_LABELS = ['matched' => 'Cuadra', 'difference' => 'Con diferencia', 'not_applicable' => 'No aplica', 'error' => 'No fue posible verificar'];

    private const SOURCES = ['purchase_document', 'purchase_payment', 'supplier_advance', 'purchase_credit_note', 'payable_adjustment'];

    /**
     * @return array{status: string, status_label: string, cutoff: string, control_account: string|null, auxiliary_cents: int|null, ledger_cents: int|null, difference_cents: int|null, items: list<array<string, mixed>>, message: string|null, calculated_at: string}
     */
    public function compute(int $companyId, string $cutoff): array
    {
        $base = ['cutoff' => $cutoff, 'calculated_at' => now()->toIso8601String()];
        try {
            $account = DB::table('company_accounting_mappings')->join('ledger_accounts', 'ledger_accounts.id', '=', 'company_accounting_mappings.payable_account_id')
                ->where('company_accounting_mappings.company_id', $companyId)->first(['ledger_accounts.id', 'ledger_accounts.code', 'ledger_accounts.name']);
            if ($account === null) {
                return [...$base, 'status' => 'not_applicable', 'status_label' => self::STATUS_LABELS['not_applicable'], 'control_account' => null, 'auxiliary_cents' => null, 'ledger_cents' => null, 'difference_cents' => null, 'items' => [], 'message' => 'La empresa no tiene cuenta de control de CxP en su mapeo contable.'];
            }
            $company = DB::table('companies')->where('id', $companyId)->first(['public_id']);
            $posted = DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                ->where('journal_lines.ledger_account_id', $account->id)->whereIn('journal_entries.status', ['posted', 'reversed'])->where('journal_entries.entry_date', '<=', $cutoff);
            $ledger = (int) (clone $posted)->selectRaw('CAST(COALESCE(SUM(journal_lines.credit_cents - journal_lines.debit_cents), 0) AS BIGINT) as balance')->value('balance');
            $bySource = (clone $posted)->join('accounting_source_entries as source', 'source.journal_entry_id', '=', 'journal_entries.id')->whereIn('source.source_type', self::SOURCES)
                ->groupBy('source.source_type', 'source.source_id')->selectRaw('source.source_type, source.source_id, CAST(SUM(journal_lines.credit_cents - journal_lines.debit_cents) AS BIGINT) as amount')
                ->get()->mapWithKeys(fn ($row): array => [$row->source_type.':'.$row->source_id => (int) $row->amount])->all();

            $documents = [];
            $activeAt = fn (?string $voidedAt): bool => $voidedAt === null || Carbon::parse($voidedAt)->toDateString() > $cutoff;
            foreach (DB::table('purchase_documents')->where('company_id', $companyId)->whereIn('status', ['approved', 'void'])->where('document_date', '<=', $cutoff)->whereNotNull('approved_at')
                ->get(['id', 'number', 'external_reference', 'supplier_name', 'document_date', 'total_cents', 'status', 'voided_at']) as $purchase) {
                $documents['purchase_document:'.$purchase->id] = ['kind' => 'Compra', 'document' => $purchase->external_reference ?: 'Compra #'.$purchase->number, 'supplier' => $purchase->supplier_name, 'date' => (string) $purchase->document_date,
                    'expected_cents' => $purchase->status === 'approved' || $activeAt($purchase->voided_at) ? (int) $purchase->total_cents : 0, 'destination' => '/payables/documents/'.$purchase->id];
            }
            foreach (DB::table('purchase_payments')->join('purchase_documents', 'purchase_documents.id', '=', 'purchase_payments.purchase_document_id')->where('purchase_documents.company_id', $companyId)->where('purchase_payments.paid_on', '<=', $cutoff)
                ->get(['purchase_payments.id', 'purchase_payments.amount_cents', 'purchase_payments.paid_on', 'purchase_payments.reference', 'purchase_payments.voided_at', 'purchase_documents.id as document_id', 'purchase_documents.supplier_name']) as $payment) {
                // A payment detached to an unapplied balance keeps its entry: it still reduces the payables account.
                $documents['purchase_payment:'.$payment->id] = ['kind' => 'Pago', 'document' => 'Pago '.$payment->reference, 'supplier' => $payment->supplier_name, 'date' => (string) $payment->paid_on,
                    'expected_cents' => $activeAt($payment->voided_at) ? -(int) $payment->amount_cents : 0, 'destination' => '/payables/documents/'.$payment->document_id];
            }
            foreach (DB::table('supplier_advances')->join('purchase_suppliers', 'purchase_suppliers.id', '=', 'supplier_advances.purchase_supplier_id')->where('supplier_advances.company_id', $companyId)->where('supplier_advances.origin', 'advance')
                ->where('supplier_advances.paid_on', '<=', $cutoff)->get(['supplier_advances.id', 'supplier_advances.amount_cents', 'supplier_advances.paid_on', 'supplier_advances.reference', 'supplier_advances.status', 'purchase_suppliers.name']) as $advance) {
                $documents['supplier_advance:'.$advance->id] = ['kind' => 'Anticipo', 'document' => 'Anticipo '.$advance->reference, 'supplier' => $advance->name, 'date' => (string) $advance->paid_on,
                    'expected_cents' => $advance->status === 'cancelled' ? 0 : -(int) $advance->amount_cents, 'destination' => '/payables/advances?advance='.$advance->id];
            }
            foreach (DB::table('purchase_credit_notes')->join('purchase_documents', 'purchase_documents.id', '=', 'purchase_credit_notes.purchase_document_id')->where('purchase_credit_notes.company_id', $companyId)->where('purchase_credit_notes.issued_on', '<=', $cutoff)
                ->get(['purchase_credit_notes.id', 'purchase_credit_notes.amount_cents', 'purchase_credit_notes.issued_on', 'purchase_credit_notes.status', 'purchase_credit_notes.voided_at', 'purchase_credit_notes.fiscal_number', 'purchase_documents.id as document_id', 'purchase_documents.supplier_name']) as $note) {
                $documents['purchase_credit_note:'.$note->id] = ['kind' => 'Nota de crédito', 'document' => 'Nota de crédito '.($note->fiscal_number ?? '#'.$note->id), 'supplier' => $note->supplier_name, 'date' => (string) $note->issued_on,
                    'expected_cents' => $note->status === 'issued' || $activeAt($note->voided_at) ? -(int) $note->amount_cents : 0, 'destination' => '/payables/documents/'.$note->document_id];
            }
            foreach (DB::table('payable_adjustments')->join('purchase_documents', 'purchase_documents.id', '=', 'payable_adjustments.purchase_document_id')->where('payable_adjustments.company_id', $companyId)->where('payable_adjustments.status', 'approved')
                ->whereDate('payable_adjustments.reviewed_at', '<=', $cutoff)->get(['payable_adjustments.id', 'payable_adjustments.kind', 'payable_adjustments.amount_cents', 'payable_adjustments.reviewed_at', 'purchase_documents.id as document_id', 'purchase_documents.supplier_name']) as $adjustment) {
                $documents['payable_adjustment:'.$adjustment->id] = ['kind' => 'Ajuste', 'document' => PayableAdjustmentService::KINDS[$adjustment->kind] ?? 'Ajuste', 'supplier' => $adjustment->supplier_name,
                    'date' => Carbon::parse($adjustment->reviewed_at)->toDateString(), 'expected_cents' => -(int) $adjustment->amount_cents, 'destination' => '/payables/documents/'.$adjustment->document_id];
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
                $items[] = [...$document, 'ledger_cents' => $inLedger, 'difference_cents' => $document['expected_cents'] - $inLedger,
                    'reason' => match (true) {
                        $entry === null => 'Sin asiento contable',
                        ! in_array($entry->status, ['posted', 'reversed'], true) => 'Asiento pendiente de contabilizar',
                        default => 'El asiento no coincide con el documento',
                    },
                    'destination' => $entry === null || $company === null ? $document['destination'] : '/accounting/companies/'.$company->public_id.'?entry='.$entry->public_id];
            }
            foreach ((clone $posted)->leftJoin('accounting_source_entries as source', function ($join): void {
                $join->on('source.journal_entry_id', '=', 'journal_entries.id')->whereIn('source.source_type', self::SOURCES);
            })->whereNull('source.id')->orderBy('journal_entries.entry_date')->limit(200)
                ->get(['journal_entries.public_id', 'journal_entries.number', 'journal_entries.entry_date', 'journal_entries.description', 'journal_lines.debit_cents', 'journal_lines.credit_cents']) as $line) {
                $amount = (int) $line->credit_cents - (int) $line->debit_cents;
                $items[] = ['kind' => 'Asiento', 'document' => 'Asiento #'.$line->number.' · '.$line->description, 'supplier' => null, 'date' => (string) $line->entry_date, 'expected_cents' => 0, 'ledger_cents' => $amount, 'difference_cents' => -$amount,
                    'reason' => 'Movimiento en la cuenta de CxP sin documento de proveedor', 'destination' => $company === null ? null : '/accounting/companies/'.$company->public_id.'?entry='.$line->public_id];
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
                'message' => $status === 'matched' ? 'CxP coincide con el Libro Mayor.' : null];
        } catch (Throwable $exception) {
            Log::warning('Payables reconciliation failed', ['company_id' => $companyId, 'error' => $exception->getMessage()]);

            return [...$base, 'status' => 'error', 'status_label' => self::STATUS_LABELS['error'], 'control_account' => null, 'auxiliary_cents' => null, 'ledger_cents' => null, 'difference_cents' => null, 'items' => [], 'message' => 'No fue posible verificar CxP contra Mayor.'];
        }
    }
}
