<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\FinancialReportService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Reconciliation of ledger accounts that are not bank accounts (link, intercompany, clearing, third party balances):
 * the ledger balance at a date is compared with the supporting balance (statement, confirmation, subledger) and every
 * difference must be explained by reconciling items before the reconciliation can be closed and reviewed.
 */
class AccountReconciliationService
{
    public const KINDS = ['in_transit' => 'Partida en tránsito', 'error' => 'Error por corregir', 'pending_adjustment' => 'Ajuste pendiente', 'other' => 'Otra partida'];

    public function __construct(private AccountingService $accounting, private FinancialReportService $reports) {}

    /** @return list<array<string, mixed>> */
    public function list(int $companyId): array
    {
        $rows = DB::table('account_reconciliations as r')->join('ledger_accounts as a', 'a.id', '=', 'r.ledger_account_id')->leftJoin('users as u', 'u.id', '=', 'r.prepared_by')->where('r.company_id', $companyId)
            ->orderByDesc('r.as_of')->orderByDesc('r.id')->limit(100)->get(['r.*', 'a.code', 'a.name as account_name', 'u.name as prepared_name']);
        $items = DB::table('account_reconciliation_items')->whereIn('account_reconciliation_id', $rows->pluck('id')->all() ?: [-1])->get()->groupBy('account_reconciliation_id');

        return array_values($rows->map(fn (stdClass $row): array => $this->present($row, $items[$row->id] ?? collect()))->all());
    }

    /** @param array{ledger_account_id: int, as_of: string, counterpart: string, supporting_balance: string, notes?: string|null} $data */
    public function start(Organization $organization, User $user, int $companyId, array $data): int
    {
        $account = DB::table('ledger_accounts')->where('company_id', $companyId)->where('id', $data['ledger_account_id'])->where('is_group', false)->first();
        if ($account === null) {
            throw ValidationException::withMessages(['ledger_account_id' => 'Elige una cuenta de esta empresa.']);
        }
        $id = (int) DB::table('account_reconciliations')->insertGetId(['organization_id' => $organization->id, 'company_id' => $companyId, 'ledger_account_id' => $account->id, 'as_of' => $data['as_of'],
            'counterpart' => trim($data['counterpart']), 'ledger_balance_cents' => $this->ledgerBalance($companyId, (int) $account->id, $data['as_of']), 'supporting_balance_cents' => $this->signed($data['supporting_balance']),
            'status' => 'draft', 'notes' => $data['notes'] ?? null, 'prepared_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->accounting->audit($organization, $user, 'account_reconciliation.started', 'account_reconciliations', $id, ['account' => $account->code, 'as_of' => $data['as_of']]);

        return $id;
    }

    /** @param array{kind: string, description: string, amount: string, journal_entry_id?: int|null} $data */
    public function addItem(Organization $organization, User $user, int $companyId, int $id, array $data): void
    {
        $reconciliation = $this->editable($companyId, $id);
        abort_unless(isset(self::KINDS[$data['kind']]), 422);
        $amount = $this->signed($data['amount']);
        if ($amount === 0) {
            throw ValidationException::withMessages(['amount' => 'La partida debe tener un importe distinto de cero.']);
        }
        if (($data['journal_entry_id'] ?? null) !== null && ! DB::table('journal_entries as e')->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')->where('p.company_id', $companyId)->where('e.id', $data['journal_entry_id'])->exists()) {
            throw ValidationException::withMessages(['journal_entry_id' => 'El asiento no pertenece a esta empresa.']);
        }
        DB::table('account_reconciliation_items')->insert(['organization_id' => $organization->id, 'company_id' => $companyId, 'account_reconciliation_id' => $reconciliation->id, 'kind' => $data['kind'],
            'description' => mb_substr(trim($data['description']), 0, 255), 'amount_cents' => $amount, 'journal_entry_id' => $data['journal_entry_id'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
        $this->accounting->audit($organization, $user, 'account_reconciliation.item_added', 'account_reconciliations', (int) $reconciliation->id, ['kind' => $data['kind'], 'amount_cents' => $amount]);
    }

    public function removeItem(Organization $organization, User $user, int $companyId, int $id, int $itemId): void
    {
        $reconciliation = $this->editable($companyId, $id);
        DB::table('account_reconciliation_items')->where('account_reconciliation_id', $reconciliation->id)->where('id', $itemId)->delete();
        $this->accounting->audit($organization, $user, 'account_reconciliation.item_removed', 'account_reconciliations', (int) $reconciliation->id, ['item' => $itemId]);
    }

    /** Takes a fresh ledger balance (entries may have been posted since the reconciliation was started). */
    public function refresh(Organization $organization, User $user, int $companyId, int $id): void
    {
        $reconciliation = $this->editable($companyId, $id);
        DB::table('account_reconciliations')->where('id', $reconciliation->id)->update(['ledger_balance_cents' => $this->ledgerBalance($companyId, (int) $reconciliation->ledger_account_id, (string) $reconciliation->as_of), 'updated_at' => now()]);
        $this->accounting->audit($organization, $user, 'account_reconciliation.refreshed', 'account_reconciliations', (int) $reconciliation->id);
    }

    /** Closes the reconciliation: only possible when the difference is fully explained. */
    public function reconcile(Organization $organization, User $user, int $companyId, int $id): void
    {
        $reconciliation = $this->editable($companyId, $id);
        $ledger = $this->ledgerBalance($companyId, (int) $reconciliation->ledger_account_id, (string) $reconciliation->as_of);
        $items = (int) DB::table('account_reconciliation_items')->where('account_reconciliation_id', $reconciliation->id)->sum('amount_cents');
        $difference = $ledger - ((int) $reconciliation->supporting_balance_cents + $items);
        if ($difference !== 0) {
            throw ValidationException::withMessages(['reconciliation' => 'Aún hay una diferencia sin explicar de Q '.number_format($difference / 100, 2).'. Agrega las partidas conciliatorias.']);
        }
        DB::table('account_reconciliations')->where('id', $reconciliation->id)->update(['status' => 'reconciled', 'ledger_balance_cents' => $ledger, 'updated_at' => now()]);
        $this->accounting->audit($organization, $user, 'account_reconciliation.reconciled', 'account_reconciliations', (int) $reconciliation->id);
    }

    /** A second person confirms the reconciliation. */
    public function review(Organization $organization, User $user, int $companyId, int $id): void
    {
        $reconciliation = DB::table('account_reconciliations')->where('company_id', $companyId)->where('id', $id)->lockForUpdate()->firstOrFail();
        if ($reconciliation->status !== 'reconciled') {
            throw ValidationException::withMessages(['reconciliation' => 'Solo se revisa una conciliación ya cerrada.']);
        }
        if ((int) $reconciliation->prepared_by === $user->id) {
            throw ValidationException::withMessages(['reconciliation' => 'La conciliación debe revisarla una persona distinta de quien la preparó.']);
        }
        DB::table('account_reconciliations')->where('id', $reconciliation->id)->update(['status' => 'reviewed', 'reviewed_by' => $user->id, 'reviewed_at' => now(), 'updated_at' => now()]);
        $this->accounting->audit($organization, $user, 'account_reconciliation.reviewed', 'account_reconciliations', (int) $reconciliation->id);
    }

    /** Natural balance of the account at a date (assets and expenses by debit, the rest by credit), posted entries only. */
    public function ledgerBalance(int $companyId, int $accountId, string $asOf): int
    {
        $row = $this->reports->lines($companyId, $asOf)->where('a.id', $accountId)->selectRaw('a.type, CAST(COALESCE(SUM(l.debit_cents), 0) AS BIGINT) as debit, CAST(COALESCE(SUM(l.credit_cents), 0) AS BIGINT) as credit')->groupBy('a.type')->first();
        if ($row === null) {
            return 0;
        }

        return in_array($row->type, ['asset', 'expense'], true) ? (int) $row->debit - (int) $row->credit : (int) $row->credit - (int) $row->debit;
    }

    private function editable(int $companyId, int $id): stdClass
    {
        $reconciliation = DB::table('account_reconciliations')->where('company_id', $companyId)->where('id', $id)->lockForUpdate()->firstOrFail();
        if ($reconciliation->status !== 'draft') {
            throw ValidationException::withMessages(['reconciliation' => 'La conciliación ya fue cerrada y no admite cambios.']);
        }

        return $reconciliation;
    }

    /**
     * @param  Collection<int, stdClass>  $items
     * @return array<string, mixed>
     */
    private function present(stdClass $row, $items): array
    {
        $itemsTotal = (int) $items->sum('amount_cents');
        $ledger = $row->status === 'draft' ? $this->ledgerBalance((int) $row->company_id, (int) $row->ledger_account_id, (string) $row->as_of) : (int) $row->ledger_balance_cents;

        return ['id' => (int) $row->id, 'account' => $row->code.' · '.$row->account_name, 'account_id' => (int) $row->ledger_account_id, 'as_of' => (string) $row->as_of, 'counterpart' => (string) $row->counterpart,
            'ledger_balance_cents' => $ledger, 'supporting_balance_cents' => (int) $row->supporting_balance_cents, 'items_cents' => $itemsTotal, 'difference_cents' => $ledger - ((int) $row->supporting_balance_cents + $itemsTotal),
            'status' => (string) $row->status, 'notes' => $row->notes, 'prepared_by' => $row->prepared_name, 'reviewed_at' => $row->reviewed_at,
            'items' => $items->map(fn (stdClass $item): array => ['id' => (int) $item->id, 'kind' => (string) $item->kind, 'kind_label' => self::KINDS[$item->kind] ?? (string) $item->kind, 'description' => (string) $item->description,
                'amount_cents' => (int) $item->amount_cents, 'journal_entry_id' => $item->journal_entry_id === null ? null : (int) $item->journal_entry_id])->values()->all()];
    }

    private function signed(string $amount): int
    {
        $negative = str_starts_with(trim($amount), '-');
        $cents = $this->accounting->cents(ltrim(trim($amount), '-+'));

        return $negative ? -$cents : $cents;
    }
}
