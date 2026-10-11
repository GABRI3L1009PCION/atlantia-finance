<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Payroll against the general ledger for one company at a cutoff: the net of every approved payroll and every executed
 * payroll payment is compared with what its own posted entries left in the salaries payable account of the payroll
 * mapping. Posted lines in that account without a payroll source are listed too, so every difference has a
 * drill-down. It is computed live and cannot be forced to match.
 */
class PayrollLedgerReconciliationService
{
    public const STATUS_LABELS = ['matched' => 'Cuadra', 'difference' => 'Con diferencia', 'not_applicable' => 'No aplica', 'error' => 'No fue posible verificar'];

    private const SOURCES = ['payroll', 'payroll_payment'];

    /**
     * @return array{status: string, status_label: string, cutoff: string, control_account: string|null, auxiliary_cents: int|null, ledger_cents: int|null, difference_cents: int|null, items: list<array<string, mixed>>, message: string|null}
     */
    public function compute(int $companyId, string $cutoff): array
    {
        $base = ['cutoff' => $cutoff];
        try {
            $account = DB::table('payroll_account_mappings')->join('ledger_accounts', 'ledger_accounts.id', '=', 'payroll_account_mappings.salaries_payable_id')
                ->where('payroll_account_mappings.company_id', $companyId)->first(['ledger_accounts.id', 'ledger_accounts.code', 'ledger_accounts.name']);
            if ($account === null) {
                return [...$base, 'status' => 'not_applicable', 'status_label' => self::STATUS_LABELS['not_applicable'], 'control_account' => null, 'auxiliary_cents' => null, 'ledger_cents' => null, 'difference_cents' => null, 'items' => [], 'message' => 'La empresa aún no tiene nómina configurada.'];
            }
            $company = DB::table('companies')->where('id', $companyId)->first(['public_id']);
            $posted = DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                ->where('journal_lines.ledger_account_id', $account->id)->whereIn('journal_entries.status', ['posted', 'reversed'])->where('journal_entries.entry_date', '<=', $cutoff);
            $ledger = (int) (clone $posted)->selectRaw('CAST(COALESCE(SUM(journal_lines.credit_cents - journal_lines.debit_cents), 0) AS BIGINT) as balance')->value('balance');
            $bySource = (clone $posted)->join('accounting_source_entries as source', 'source.journal_entry_id', '=', 'journal_entries.id')->whereIn('source.source_type', self::SOURCES)
                ->groupBy('source.source_type', 'source.source_id')->selectRaw('source.source_type, source.source_id, CAST(SUM(journal_lines.credit_cents - journal_lines.debit_cents) AS BIGINT) as amount')
                ->get()->mapWithKeys(fn ($row): array => [$row->source_type.':'.$row->source_id => (int) $row->amount])->all();

            $documents = [];
            foreach (DB::table('payroll_periods')->where('company_id', $companyId)->whereIn('status', ['approved', 'paid', 'closed'])->where('ends_on', '<=', $cutoff)->get(['id', 'name', 'ends_on', 'net_cents']) as $period) {
                $documents['payroll:'.$period->id] = ['kind' => 'Nómina', 'document' => 'Nómina '.$period->name, 'date' => (string) $period->ends_on, 'expected_cents' => (int) $period->net_cents, 'destination' => '/payroll/periods/'.$period->id];
            }
            foreach (DB::table('payment_instructions')->join('payroll_periods', 'payroll_periods.id', '=', 'payment_instructions.payroll_period_id')->where('payment_instructions.company_id', $companyId)
                ->where('payment_instructions.kind', 'payroll')->where('payment_instructions.status', 'executed')->where('payment_instructions.executed_on', '<=', $cutoff)
                ->get(['payment_instructions.id', 'payment_instructions.amount_cents', 'payment_instructions.executed_on', 'payment_instructions.execution_reference', 'payroll_periods.id as period_id', 'payroll_periods.name']) as $payment) {
                $documents['payroll_payment:'.$payment->id] = ['kind' => 'Pago', 'document' => 'Pago nómina '.$payment->name.' · '.$payment->execution_reference, 'date' => (string) $payment->executed_on,
                    'expected_cents' => -(int) $payment->amount_cents, 'destination' => '/payroll/periods/'.$payment->period_id];
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
                        default => 'El asiento no coincide con la nómina',
                    },
                    'destination' => $entry === null || $company === null ? $document['destination'] : '/accounting/companies/'.$company->public_id.'?entry='.$entry->public_id];
            }
            foreach ((clone $posted)->leftJoin('accounting_source_entries as source', function ($join): void {
                $join->on('source.journal_entry_id', '=', 'journal_entries.id')->whereIn('source.source_type', self::SOURCES);
            })->whereNull('source.id')->orderBy('journal_entries.entry_date')->limit(200)
                ->get(['journal_entries.public_id', 'journal_entries.number', 'journal_entries.entry_date', 'journal_entries.description', 'journal_lines.debit_cents', 'journal_lines.credit_cents']) as $line) {
                $amount = (int) $line->credit_cents - (int) $line->debit_cents;
                $items[] = ['kind' => 'Asiento', 'document' => 'Asiento #'.$line->number.' · '.$line->description, 'date' => (string) $line->entry_date, 'expected_cents' => 0, 'ledger_cents' => $amount, 'difference_cents' => -$amount,
                    'reason' => 'Movimiento en sueldos por pagar sin nómina de origen', 'destination' => $company === null ? null : '/accounting/companies/'.$company->public_id.'?entry='.$line->public_id];
            }
            usort($items, fn (array $a, array $b): int => abs($b['difference_cents']) <=> abs($a['difference_cents']));
            $auxiliary = array_sum(array_column($documents, 'expected_cents'));
            $difference = $auxiliary - $ledger;
            $status = match (true) {
                $documents === [] && $items === [] => 'not_applicable',
                $difference === 0 && $items === [] => 'matched',
                default => 'difference',
            };

            return [...$base, 'status' => $status, 'status_label' => self::STATUS_LABELS[$status], 'control_account' => $account->code.' '.$account->name, 'auxiliary_cents' => $auxiliary, 'ledger_cents' => $ledger,
                'difference_cents' => $difference, 'items' => $items, 'message' => match ($status) {
                    'matched' => 'La nómina coincide con el Libro Mayor.',
                    'not_applicable' => 'Aún no hay nóminas aprobadas para comparar.',
                    default => null,
                }];
        } catch (Throwable $exception) {
            Log::warning('Payroll reconciliation failed', ['company_id' => $companyId, 'error' => $exception->getMessage()]);

            return [...$base, 'status' => 'error', 'status_label' => self::STATUS_LABELS['error'], 'control_account' => null, 'auxiliary_cents' => null, 'ledger_cents' => null, 'difference_cents' => null, 'items' => [], 'message' => 'No fue posible verificar la nómina contra el Mayor.'];
        }
    }
}
