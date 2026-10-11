<?php

declare(strict_types=1);

namespace App\Services\Treasury;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\WorkManagement\WorkNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Formal bank reconciliation of one account at a cutoff date. Every figure is computed here from the bank
 * movements and the posted ledger lines (never typed by the user): bank balance at the cutoff, ledger balance,
 * bank items not in books, book items not in bank and the resulting difference. There is no way to force it to
 * balance; a reconciliation can only be submitted with a zero difference, is approved by someone other than its
 * preparer and becomes stale (and must be recalculated) when its sources change after the calculation.
 *
 * @phpstan-type ReconciliationItem array{side: string, date: string, reference: string, description: string, amount_cents: int, reason: string, transaction_id: int|null, entry_id: int|null}
 * @phpstan-type ReconciliationFigures array{bank_balance_cents: int, ledger_balance_cents: int, bank_not_in_books_cents: int, books_not_in_bank_cents: int, adjusted_bank_cents: int, adjusted_ledger_cents: int, difference_cents: int, items: list<ReconciliationItem>, items_count: int, hash: string}
 */
class BankReconciliationService
{
    public const STATUS_LABELS = ['in_progress' => 'En proceso', 'with_differences' => 'Con diferencias', 'submitted' => 'Pendiente de aprobación', 'changes_requested' => 'Con observaciones', 'approved' => 'Aprobada'];

    private const ITEM_LIMIT = 300;

    public function __construct(
        private AccountingService $accounting,
        private WorkNotificationService $notifications,
    ) {}

    /** @return ReconciliationFigures */
    public function compute(int $bankAccountId, string $cutoff): array
    {
        $account = DB::table('bank_accounts')->where('id', $bankAccountId)->first(['id', 'ledger_account_id', 'bank_balance_cents']);
        abort_if($account === null, 404);
        $after = (int) DB::table('bank_transactions')->where('bank_account_id', $bankAccountId)->where('transaction_date', '>', $cutoff)
            ->selectRaw("CAST(COALESCE(SUM(CASE WHEN flow = 'inflow' THEN amount_cents ELSE -amount_cents END), 0) AS BIGINT) as total")->value('total');
        $bankBalance = (int) $account->bank_balance_cents - $after;
        $ledgerBalance = (int) DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.ledger_account_id', $account->ledger_account_id)->whereIn('journal_entries.status', ['posted', 'reversed'])->where('journal_entries.entry_date', '<=', $cutoff)
            ->selectRaw('CAST(COALESCE(SUM(journal_lines.debit_cents - journal_lines.credit_cents), 0) AS BIGINT) as balance')->value('balance');

        $items = [];
        $linked = [];
        $movements = DB::table('bank_transactions')->where('bank_account_id', $bankAccountId)->where('transaction_date', '<=', $cutoff)->orderBy('transaction_date')->orderBy('id')->get();
        foreach ($movements as $movement) {
            $entries = $movement->reconciliation_status === 'matched' ? $this->linkedEntries($movement) : [];
            $posted = $entries === [] ? [] : DB::table('journal_entries')->whereIn('id', $entries)->whereIn('status', ['posted', 'reversed'])->pluck('id')->map(fn ($id): int => (int) $id)->all();
            array_push($linked, ...$posted);
            if ($posted === []) {
                $items[] = [
                    'side' => 'bank', 'date' => (string) $movement->transaction_date, 'reference' => (string) $movement->reference, 'description' => (string) $movement->description,
                    'amount_cents' => $movement->flow === 'inflow' ? (int) $movement->amount_cents : -(int) $movement->amount_cents,
                    'reason' => $movement->reconciliation_status === 'matched' ? 'Asiento pendiente de contabilizar' : ($movement->reconciliation_status === 'unmatched' ? 'Movimiento sin identificar' : 'Sin registrar en libros'),
                    'transaction_id' => (int) $movement->id, 'entry_id' => null,
                ];
            }
        }

        $lines = DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.ledger_account_id', $account->ledger_account_id)->whereIn('journal_entries.status', ['posted', 'reversed'])->where('journal_entries.entry_date', '<=', $cutoff)
            ->whereNotIn('journal_entries.id', array_values(array_unique($linked)) ?: [0])
            ->orderBy('journal_entries.entry_date')->orderBy('journal_entries.id')
            ->get(['journal_entries.id', 'journal_entries.entry_date', 'journal_entries.number', 'journal_entries.reference', 'journal_entries.description', 'journal_lines.debit_cents', 'journal_lines.credit_cents']);
        foreach ($lines as $line) {
            $items[] = [
                'side' => 'books', 'date' => (string) $line->entry_date, 'reference' => (string) ($line->reference ?: '#'.$line->number), 'description' => (string) $line->description,
                'amount_cents' => (int) $line->debit_cents - (int) $line->credit_cents,
                'reason' => (int) $line->debit_cents > 0 ? 'Depósito en tránsito' : 'Pago o cheque en circulación',
                'transaction_id' => null, 'entry_id' => (int) $line->id,
            ];
        }

        $bankNotInBooks = array_sum(array_map(fn (array $item): int => $item['side'] === 'bank' ? $item['amount_cents'] : 0, $items));
        $booksNotInBank = array_sum(array_map(fn (array $item): int => $item['side'] === 'books' ? $item['amount_cents'] : 0, $items));
        $adjustedBank = $bankBalance + $booksNotInBank;
        $adjustedLedger = $ledgerBalance + $bankNotInBooks;
        $hash = hash('sha256', (string) json_encode([$bankBalance, $ledgerBalance, array_map(fn (array $item): array => [$item['side'], $item['transaction_id'], $item['entry_id'], $item['amount_cents']], $items)]));

        return [
            'bank_balance_cents' => $bankBalance,
            'ledger_balance_cents' => $ledgerBalance,
            'bank_not_in_books_cents' => $bankNotInBooks,
            'books_not_in_bank_cents' => $booksNotInBank,
            'adjusted_bank_cents' => $adjustedBank,
            'adjusted_ledger_cents' => $adjustedLedger,
            'difference_cents' => $adjustedBank - $adjustedLedger,
            'items' => array_slice($items, 0, self::ITEM_LIMIT),
            'items_count' => count($items),
            'hash' => $hash,
        ];
    }

    /** Creates or recalculates the reconciliation of an account at a cutoff; an approved one is never overwritten. */
    public function prepare(Organization $organization, User $actor, int $bankAccountId, string $cutoff): int
    {
        return DB::transaction(function () use ($organization, $actor, $bankAccountId, $cutoff): int {
            $account = DB::table('bank_accounts')->join('companies', 'companies.id', '=', 'bank_accounts.company_id')->where('bank_accounts.id', $bankAccountId)->first(['bank_accounts.id', 'bank_accounts.company_id', 'companies.office_id']);
            abort_if($account === null, 404);
            if ($cutoff > now()->toDateString()) {
                throw ValidationException::withMessages(['cutoff_date' => 'La fecha de corte no puede ser futura.']);
            }
            $existing = DB::table('bank_reconciliations')->where('bank_account_id', $bankAccountId)->where('cutoff_date', $cutoff)->lockForUpdate()->first();
            if ($existing !== null && in_array($existing->status, ['submitted', 'approved'], true)) {
                if ($existing->status === 'approved' && ! $this->isStale($existing)) {
                    return (int) $existing->id;
                }
                if ($existing->status === 'submitted') {
                    throw ValidationException::withMessages(['reconciliation' => 'La conciliación está pendiente de aprobación; solicita cambios antes de recalcularla.']);
                }
            }
            $figures = $this->compute($bankAccountId, $cutoff);
            $values = [
                'status' => $figures['difference_cents'] === 0 ? 'in_progress' : 'with_differences',
                'bank_balance_cents' => $figures['bank_balance_cents'], 'ledger_balance_cents' => $figures['ledger_balance_cents'], 'difference_cents' => $figures['difference_cents'],
                'inputs_hash' => $figures['hash'], 'calculated_at' => now(), 'prepared_by' => $actor->id, 'approved_by' => null, 'approved_at' => null, 'updated_at' => now(),
            ];
            if ($existing === null) {
                $id = DB::table('bank_reconciliations')->insertGetId([...$values, 'public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'office_id' => $account->office_id, 'company_id' => $account->company_id, 'bank_account_id' => $bankAccountId, 'cutoff_date' => $cutoff, 'created_at' => now()]);
            } else {
                $id = (int) $existing->id;
                DB::table('bank_reconciliations')->where('id', $id)->update($values);
            }
            $this->accounting->audit($organization, $actor, 'reconciliation.calculated', 'bank_reconciliations', $id, ['difference_cents' => $figures['difference_cents'], 'cutoff' => $cutoff], (int) $account->office_id);

            return $id;
        });
    }

    public function submit(Organization $organization, User $actor, int $id): void
    {
        DB::transaction(function () use ($organization, $actor, $id): void {
            $row = $this->find($organization, $id);
            if (! in_array($row->status, ['in_progress', 'with_differences', 'changes_requested'], true)) {
                throw ValidationException::withMessages(['reconciliation' => 'Esta conciliación no se puede enviar en su estado actual.']);
            }
            $figures = $this->compute((int) $row->bank_account_id, (string) $row->cutoff_date);
            if ($row->inputs_hash !== $figures['hash']) {
                throw ValidationException::withMessages(['reconciliation' => 'Los movimientos cambiaron desde el último cálculo; recalcula antes de enviar.']);
            }
            if ((int) $row->difference_cents !== 0) {
                throw ValidationException::withMessages(['reconciliation' => 'La conciliación tiene una diferencia sin explicar; resuélvela antes de enviarla a aprobación.']);
            }
            $bankItems = count(array_filter($figures['items'], fn (array $item): bool => $item['side'] === 'bank'));
            if ($bankItems > 0) {
                throw ValidationException::withMessages(['reconciliation' => $bankItems.' movimiento(s) del banco aún no están registrados y contabilizados en libros (comisiones, depósitos sin identificar o asientos en borrador).']);
            }
            DB::table('bank_reconciliations')->where('id', $id)->update(['status' => 'submitted', 'review_note' => null, 'updated_at' => now()]);
            $this->accounting->audit($organization, $actor, 'reconciliation.submitted', 'bank_reconciliations', $id, [], $row->office_id === null ? null : (int) $row->office_id);
            foreach ($this->approverIds($row) as $recipient) {
                if ($recipient !== $actor->id) {
                    $this->notifications->notify($organization->id, $recipient, $actor->id, 'bank_reconciliation', 'Conciliación bancaria por aprobar', 'Corte '.$row->cutoff_date, 'bank_reconciliations', $id);
                }
            }
        });
    }

    public function approve(Organization $organization, User $actor, int $id): void
    {
        DB::transaction(function () use ($organization, $actor, $id): void {
            $row = $this->find($organization, $id);
            if ($row->status !== 'submitted') {
                throw ValidationException::withMessages(['reconciliation' => 'Solo se aprueban conciliaciones enviadas.']);
            }
            if ((int) $row->prepared_by === $actor->id) {
                throw ValidationException::withMessages(['reconciliation' => 'Quien prepara la conciliación no puede aprobarla.']);
            }
            if ($this->isStale($row)) {
                throw ValidationException::withMessages(['reconciliation' => 'Los movimientos cambiaron después del envío; solicita que se recalcule.']);
            }
            DB::table('bank_reconciliations')->where('id', $id)->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now(), 'updated_at' => now()]);
            DB::table('bank_accounts')->where('id', $row->bank_account_id)->where(fn ($query) => $query->whereNull('last_reconciled_on')->orWhere('last_reconciled_on', '<', $row->cutoff_date))
                ->update(['last_reconciled_on' => $row->cutoff_date, 'updated_at' => now()]);
            $this->accounting->audit($organization, $actor, 'reconciliation.approved', 'bank_reconciliations', $id, [], $row->office_id === null ? null : (int) $row->office_id);
            $this->notifications->notify($organization->id, (int) $row->prepared_by, $actor->id, 'bank_reconciliation', 'Conciliación bancaria aprobada', 'Corte '.$row->cutoff_date, 'bank_reconciliations', $id);
        });
    }

    public function requestChanges(Organization $organization, User $actor, int $id, string $note): void
    {
        DB::transaction(function () use ($organization, $actor, $id, $note): void {
            $row = $this->find($organization, $id);
            if ($row->status !== 'submitted') {
                throw ValidationException::withMessages(['reconciliation' => 'Solo se devuelven conciliaciones enviadas.']);
            }
            DB::table('bank_reconciliations')->where('id', $id)->update(['status' => 'changes_requested', 'review_note' => $note, 'updated_at' => now()]);
            $this->accounting->audit($organization, $actor, 'reconciliation.changes_requested', 'bank_reconciliations', $id, ['note' => $note], $row->office_id === null ? null : (int) $row->office_id);
            $this->notifications->notify($organization->id, (int) $row->prepared_by, $actor->id, 'bank_reconciliation', 'Conciliación bancaria con observaciones', $note, 'bank_reconciliations', $id);
        });
    }

    public function isStale(stdClass $row): bool
    {
        return $row->inputs_hash !== $this->compute((int) $row->bank_account_id, (string) $row->cutoff_date)['hash'];
    }

    /** @return list<int> */
    public function linkedEntries(stdClass $movement): array
    {
        $ids = $movement->matched_journal_entry_id !== null ? [(int) $movement->matched_journal_entry_id] : [];
        $source = (int) $movement->matched_source_id;
        $found = match ($movement->matched_source_type) {
            'journal_entries' => [$source],
            'bank_transfers' => DB::table('bank_transfers')->where('id', $source)->whereNotNull('journal_entry_id')->pluck('journal_entry_id')->all(),
            'treasury_receipts' => DB::table('journal_entries')->where('source_type', 'sales_receipt')->whereIn('source_id', DB::table('sales_receipts')->where('treasury_receipt_id', $source)->select('id'))->pluck('id')->all(),
            'payment_instructions' => DB::table('journal_entries')->where('source_type', 'purchase_payment')->whereIn('source_id', DB::table('purchase_payments')->where('payment_instruction_id', $source)->select('id'))->pluck('id')->all(),
            default => [],
        };

        return array_values(array_unique(array_map(fn ($id): int => (int) $id, [...$ids, ...$found])));
    }

    /** @return list<int> */
    private function approverIds(stdClass $row): array
    {
        return array_values(DB::table('company_assignments')->where('company_id', $row->company_id)->where('status', 'active')->whereNull('deleted_at')
            ->whereIn('assignment_role', ['accountant_in_charge', 'general_accountant', 'accounting_supervisor'])
            ->distinct()->pluck('user_id')->map(fn ($id): int => (int) $id)->all());
    }

    private function find(Organization $organization, int $id): stdClass
    {
        $row = DB::table('bank_reconciliations')->where('bank_reconciliations.organization_id', $organization->id)->where('id', $id)->lockForUpdate()->first();
        abort_if($row === null, 404);

        return $row;
    }
}
