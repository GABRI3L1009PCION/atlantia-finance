<?php

declare(strict_types=1);

namespace App\Services\Treasury;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingAutomationService;
use App\Services\AccountingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Checkbooks and issued checks. Every check takes the next number of an active checkbook (numbers are unique per bank
 * account and never reused, not even by voided checks), records the outflow in the bank account and drafts its journal
 * entry (debit the account it pays, credit the bank's ledger account).
 */
class CheckService
{
    public const STATUSES = ['issued' => 'Emitido', 'cleared' => 'Cobrado', 'voided' => 'Anulado'];

    public function __construct(private AccountingService $accounting, private AccountingAutomationService $automation) {}

    /** @return list<array<string, mixed>> */
    public function checkbooks(int $companyId): array
    {
        return array_values(DB::table('bank_checkbooks as c')->join('bank_accounts as b', 'b.id', '=', 'c.bank_account_id')->where('c.company_id', $companyId)->orderByDesc('c.id')
            ->get(['c.*', 'b.bank_name', 'b.account_name', 'b.last_four'])->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'bank_account_id' => (int) $row->bank_account_id,
                'account' => $row->bank_name.' · '.$row->account_name.' ••••'.$row->last_four, 'series' => $row->series, 'first_number' => (int) $row->first_number, 'last_number' => (int) $row->last_number,
                'next_number' => (int) $row->next_number, 'remaining' => max(0, (int) $row->last_number - (int) $row->next_number + 1), 'status' => (string) $row->status])->all());
    }

    /** @return list<array<string, mixed>> */
    public function checks(int $companyId, int $limit = 200): array
    {
        return array_values(DB::table('bank_checks as k')->join('bank_accounts as b', 'b.id', '=', 'k.bank_account_id')->join('bank_checkbooks as c', 'c.id', '=', 'k.bank_checkbook_id')
            ->join('ledger_accounts as a', 'a.id', '=', 'k.counter_account_id')->where('k.company_id', $companyId)->orderByDesc('k.id')->limit($limit)
            ->get(['k.*', 'b.bank_name', 'b.last_four', 'c.series', 'a.code', 'a.name as counter_name'])->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'number' => (int) $row->number,
                'label' => trim(($row->series ?? '').' '.str_pad((string) $row->number, 6, '0', STR_PAD_LEFT)), 'account' => $row->bank_name.' ••••'.$row->last_four, 'payee' => (string) $row->payee,
                'amount_cents' => (int) $row->amount_cents, 'issued_on' => (string) $row->issued_on, 'memo' => $row->memo, 'counter_account' => $row->code.' · '.$row->counter_name,
                'status' => (string) $row->status, 'status_label' => self::STATUSES[$row->status] ?? (string) $row->status, 'voided_reason' => $row->voided_reason, 'journal_entry_id' => $row->journal_entry_id === null ? null : (int) $row->journal_entry_id])->all());
    }

    /** @param array{bank_account_id: int, series?: string|null, first_number: int, last_number: int} $data */
    public function createCheckbook(Organization $organization, User $user, int $companyId, array $data): int
    {
        $account = DB::table('bank_accounts')->where('company_id', $companyId)->where('id', $data['bank_account_id'])->where('status', 'active')->first();
        if ($account === null) {
            throw ValidationException::withMessages(['bank_account_id' => 'Elige una cuenta bancaria activa de esta empresa.']);
        }
        if ($data['last_number'] < $data['first_number']) {
            throw ValidationException::withMessages(['last_number' => 'El último número debe ser mayor o igual al primero.']);
        }
        $overlaps = DB::table('bank_checkbooks')->where('bank_account_id', $account->id)->where('first_number', '<=', $data['last_number'])->where('last_number', '>=', $data['first_number'])->exists();
        if ($overlaps) {
            throw ValidationException::withMessages(['first_number' => 'Ese rango de numeración se cruza con otra chequera de la misma cuenta.']);
        }
        $id = (int) DB::table('bank_checkbooks')->insertGetId(['organization_id' => $organization->id, 'company_id' => $companyId, 'bank_account_id' => $account->id, 'series' => filled($data['series'] ?? null) ? mb_strtoupper(trim((string) $data['series'])) : null,
            'first_number' => $data['first_number'], 'last_number' => $data['last_number'], 'next_number' => $data['first_number'], 'status' => 'active', 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->accounting->audit($organization, $user, 'bank.checkbook.created', 'bank_checkbooks', $id, ['from' => $data['first_number'], 'to' => $data['last_number']]);

        return $id;
    }

    /** @param array{bank_account_id: int, payee: string, amount: string, issued_on: string, memo?: string|null, counter_account_id: int} $data */
    public function issue(Organization $organization, User $user, int $companyId, array $data): int
    {
        return DB::transaction(function () use ($organization, $user, $companyId, $data): int {
            $this->accounting->lock($companyId);
            $account = DB::table('bank_accounts')->where('company_id', $companyId)->where('id', $data['bank_account_id'])->where('status', 'active')->lockForUpdate()->first();
            if ($account === null) {
                throw ValidationException::withMessages(['bank_account_id' => 'Elige una cuenta bancaria activa de esta empresa.']);
            }
            $counter = DB::table('ledger_accounts')->where('company_id', $companyId)->where('id', $data['counter_account_id'])->where('is_active', true)->where('accepts_movements', true)->where('is_group', false)->first();
            if ($counter === null || (int) $counter->id === (int) $account->ledger_account_id) {
                throw ValidationException::withMessages(['counter_account_id' => 'Elige la cuenta que paga el cheque (distinta de la cuenta del banco).']);
            }
            $amount = $this->accounting->cents($data['amount']);
            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => 'El importe debe ser mayor que cero.']);
            }
            $book = DB::table('bank_checkbooks')->where('bank_account_id', $account->id)->where('status', 'active')->orderBy('first_number')->lockForUpdate()->first();
            if ($book === null) {
                throw ValidationException::withMessages(['bank_account_id' => 'La cuenta no tiene una chequera activa con números disponibles. Registra una chequera.']);
            }
            $number = (int) $book->next_number;
            if (DB::table('bank_checks')->where('bank_account_id', $account->id)->where('number', $number)->exists()) {
                throw ValidationException::withMessages(['number' => 'El número de cheque '.$number.' ya fue utilizado en esta cuenta.']);
            }
            $label = trim(($book->series ?? '').' '.str_pad((string) $number, 6, '0', STR_PAD_LEFT));
            $balance = (int) $account->bank_balance_cents - $amount;
            $movementId = (int) DB::table('bank_transactions')->insertGetId(['public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'bank_account_id' => $account->id, 'transaction_date' => $data['issued_on'],
                'reference' => 'CHQ '.$label, 'description' => 'Cheque '.$label.' a '.trim($data['payee']), 'flow' => 'outflow', 'amount_cents' => $amount, 'running_balance_cents' => $balance,
                'reconciliation_status' => 'pending', 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('bank_accounts')->where('id', $account->id)->update(['bank_balance_cents' => $balance, 'updated_at' => now()]);
            $checkId = (int) DB::table('bank_checks')->insertGetId(['organization_id' => $organization->id, 'company_id' => $companyId, 'bank_account_id' => $account->id, 'bank_checkbook_id' => $book->id, 'number' => $number,
                'payee' => mb_substr(trim($data['payee']), 0, 150), 'amount_cents' => $amount, 'issued_on' => $data['issued_on'], 'memo' => filled($data['memo'] ?? null) ? mb_substr(trim((string) $data['memo']), 0, 255) : null,
                'counter_account_id' => $counter->id, 'status' => 'issued', 'bank_transaction_id' => $movementId, 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
            $entryId = $this->automation->draft($organization, $user, $companyId, 'bank_check', $checkId, $data['issued_on'], 'Cheque '.$label.' a '.trim($data['payee']), 'CHQ-'.$label, [
                ['account_id' => (int) $counter->id, 'debit' => $this->decimal($amount), 'credit' => '0', 'description' => 'Cheque '.$label.' · '.trim($data['payee'])],
                ['account_id' => (int) $account->ledger_account_id, 'debit' => '0', 'credit' => $this->decimal($amount), 'description' => 'Cheque '.$label.' · '.trim($data['payee'])]], 'bank_check.issued');
            DB::table('bank_checks')->where('id', $checkId)->update(['journal_entry_id' => $entryId]);
            $next = $number + 1;
            DB::table('bank_checkbooks')->where('id', $book->id)->update(['next_number' => $next, 'status' => $next > (int) $book->last_number ? 'exhausted' : 'active', 'updated_at' => now()]);
            $this->accounting->audit($organization, $user, 'bank.check.issued', 'bank_checks', $checkId, ['number' => $number, 'amount_cents' => $amount]);

            return $checkId;
        });
    }

    /** Marks an issued check as cashed by the bank. */
    public function clear(Organization $organization, User $user, int $companyId, int $checkId): void
    {
        $check = DB::table('bank_checks')->where('company_id', $companyId)->where('id', $checkId)->lockForUpdate()->firstOrFail();
        if ($check->status !== 'issued') {
            throw ValidationException::withMessages(['check' => 'Solo un cheque emitido puede marcarse como cobrado.']);
        }
        DB::table('bank_checks')->where('id', $check->id)->update(['status' => 'cleared', 'updated_at' => now()]);
        $this->accounting->audit($organization, $user, 'bank.check.cleared', 'bank_checks', (int) $check->id);
    }

    /** Voids a check: the bank balance returns, its entry is reversed and the number stays used. */
    public function void(Organization $organization, User $user, int $companyId, int $checkId, string $reason): void
    {
        DB::transaction(function () use ($organization, $user, $companyId, $checkId, $reason): void {
            $this->accounting->lock($companyId);
            $check = DB::table('bank_checks')->where('company_id', $companyId)->where('id', $checkId)->lockForUpdate()->firstOrFail();
            if ($check->status !== 'issued') {
                throw ValidationException::withMessages(['check' => 'Solo un cheque emitido y no cobrado puede anularse.']);
            }
            $account = DB::table('bank_accounts')->where('id', $check->bank_account_id)->lockForUpdate()->firstOrFail();
            $balance = (int) $account->bank_balance_cents + (int) $check->amount_cents;
            DB::table('bank_transactions')->insert(['public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'bank_account_id' => $account->id, 'transaction_date' => now()->toDateString(),
                'reference' => 'ANUL CHQ '.$check->number, 'description' => 'Anulación del cheque '.$check->number.': '.mb_substr($reason, 0, 150), 'flow' => 'inflow', 'amount_cents' => $check->amount_cents,
                'running_balance_cents' => $balance, 'reconciliation_status' => 'pending', 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('bank_accounts')->where('id', $account->id)->update(['bank_balance_cents' => $balance, 'updated_at' => now()]);
            $this->automation->reverseSource($organization, $user, $companyId, 'bank_check', (int) $check->id, now()->toDateString(), $reason);
            DB::table('bank_checks')->where('id', $check->id)->update(['status' => 'voided', 'voided_reason' => mb_substr($reason, 0, 255), 'updated_at' => now()]);
            $this->accounting->audit($organization, $user, 'bank.check.voided', 'bank_checks', (int) $check->id, ['reason' => $reason]);
        });
    }

    private function decimal(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
