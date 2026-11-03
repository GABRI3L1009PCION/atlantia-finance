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
use Throwable;

/**
 * Rules that classify bank movements by themselves: "if the description says ENERGUATE, it goes to electricity
 * expense". A rule matches a text in the description or reference (without accents or case), optionally a direction
 * and an amount range; the first active rule by priority wins. Its entry is drafted against the bank's ledger account,
 * so the accountant reviews it like any other, and the movement counts as reconciled.
 */
class BankRuleService
{
    public function __construct(
        private AccountingService $accounting,
        private AccountingAutomationService $automation,
    ) {}

    /** @return list<array{id: int, name: string, match_text: string, flow: string, min_amount_cents: int|null, max_amount_cents: int|null, ledger_account_id: int, account: string, priority: int, is_active: bool, times_applied: int}> */
    public function rules(int $companyId): array
    {
        return array_values(DB::table('bank_rules')->join('ledger_accounts', 'ledger_accounts.id', '=', 'bank_rules.ledger_account_id')->where('bank_rules.company_id', $companyId)
            ->orderBy('bank_rules.priority')->orderBy('bank_rules.id')
            ->get(['bank_rules.*', 'ledger_accounts.code', 'ledger_accounts.name as account_name'])
            ->map(fn (stdClass $rule): array => ['id' => (int) $rule->id, 'name' => (string) $rule->name, 'match_text' => (string) $rule->match_text, 'flow' => (string) $rule->flow,
                'min_amount_cents' => $rule->min_amount_cents === null ? null : (int) $rule->min_amount_cents, 'max_amount_cents' => $rule->max_amount_cents === null ? null : (int) $rule->max_amount_cents,
                'ledger_account_id' => (int) $rule->ledger_account_id, 'account' => $rule->code.' · '.$rule->account_name, 'priority' => (int) $rule->priority,
                'is_active' => (bool) $rule->is_active, 'times_applied' => (int) $rule->times_applied])->all());
    }

    /** @param  array{name: string, match_text: string, flow: string, min_amount_cents: int|null, max_amount_cents: int|null, ledger_account_id: int, priority: int}  $data */
    public function create(Organization $organization, User $user, int $companyId, array $data): int
    {
        $this->assertAccount($companyId, $data['ledger_account_id']);
        if ($data['min_amount_cents'] !== null && $data['max_amount_cents'] !== null && $data['min_amount_cents'] > $data['max_amount_cents']) {
            throw ValidationException::withMessages(['max_amount' => 'El monto máximo debe ser mayor que el mínimo.']);
        }
        $id = DB::table('bank_rules')->insertGetId([...$data, 'organization_id' => $organization->id, 'company_id' => $companyId, 'match_text' => trim($data['match_text']),
            'is_active' => true, 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->accounting->audit($organization, $user, 'bank_rule.created', 'bank_rules', $id, ['match_text' => $data['match_text']]);

        return $id;
    }

    public function toggle(Organization $organization, User $user, int $companyId, int $ruleId, bool $active): void
    {
        abort_unless(DB::table('bank_rules')->where('company_id', $companyId)->where('id', $ruleId)->update(['is_active' => $active, 'updated_at' => now()]) === 1, 404);
        $this->accounting->audit($organization, $user, $active ? 'bank_rule.activated' : 'bank_rule.deactivated', 'bank_rules', $ruleId, []);
    }

    public function delete(Organization $organization, User $user, int $companyId, int $ruleId): void
    {
        abort_unless(DB::table('bank_rules')->where('company_id', $companyId)->where('id', $ruleId)->delete() === 1, 404);
        $this->accounting->audit($organization, $user, 'bank_rule.deleted', 'bank_rules', $ruleId, []);
    }

    /**
     * Applies the rules to the company's pending movements (or to the given ones). Returns how many were classified.
     *
     * @param  list<int>|null  $transactionIds
     */
    public function apply(Organization $organization, User $user, int $companyId, ?array $transactionIds = null): int
    {
        $rules = DB::table('bank_rules')->where('company_id', $companyId)->where('is_active', true)->orderBy('priority')->orderBy('id')->get();
        if ($rules->isEmpty()) {
            return 0;
        }
        $movements = DB::table('bank_transactions')->join('bank_accounts', 'bank_accounts.id', '=', 'bank_transactions.bank_account_id')
            ->where('bank_accounts.company_id', $companyId)->where('bank_transactions.reconciliation_status', 'pending')->whereNull('bank_transactions.classification')
            ->when($transactionIds !== null, fn ($query) => $query->whereIn('bank_transactions.id', $transactionIds ?? []))
            ->orderBy('bank_transactions.transaction_date')->limit(2000)
            ->get(['bank_transactions.*', 'bank_accounts.ledger_account_id', 'bank_accounts.bank_name']);
        $applied = 0;
        foreach ($movements as $movement) {
            $rule = $rules->first(fn (stdClass $rule): bool => $this->matches($rule, $movement));
            if ($rule === null || $movement->ledger_account_id === null) {
                continue;
            }
            try {
                DB::transaction(function () use ($organization, $user, $companyId, $rule, $movement): void {
                    $amount = number_format((int) $movement->amount_cents / 100, 2, '.', '');
                    $lines = $movement->flow === 'outflow'
                        ? [['account_id' => (int) $rule->ledger_account_id, 'debit' => $amount, 'credit' => '0', 'description' => $rule->name], ['account_id' => (int) $movement->ledger_account_id, 'debit' => '0', 'credit' => $amount]]
                        : [['account_id' => (int) $movement->ledger_account_id, 'debit' => $amount, 'credit' => '0'], ['account_id' => (int) $rule->ledger_account_id, 'debit' => '0', 'credit' => $amount, 'description' => $rule->name]];
                    $entry = $this->automation->draft($organization, $user, $companyId, 'bank_transaction', (int) $movement->id, (string) $movement->transaction_date,
                        mb_substr($rule->name.' · '.$movement->bank_name, 0, 250), (string) $movement->reference, $lines);
                    if ($entry === null) {
                        throw ValidationException::withMessages(['rule' => 'Sin período contable abierto.']);
                    }
                    DB::table('bank_transactions')->where('id', $movement->id)->update(['classification' => 'rule', 'reconciliation_status' => 'matched', 'matched_journal_entry_id' => $entry,
                        'matched_source_type' => 'journal_entries', 'matched_source_id' => $entry, 'matched_by' => $user->id, 'matched_at' => now(), 'updated_at' => now()]);
                    DB::table('bank_rules')->where('id', $rule->id)->update(['times_applied' => DB::raw('times_applied + 1'), 'last_applied_at' => now(), 'updated_at' => now()]);
                });
                $applied++;
            } catch (Throwable) {
                continue;
            }
        }
        if ($applied > 0) {
            $this->accounting->audit($organization, $user, 'bank_rule.applied', 'companies', $companyId, ['movements' => $applied]);
        }

        return $applied;
    }

    /** Whether a rule recognizes a movement. */
    public function matches(stdClass $rule, stdClass $movement): bool
    {
        $text = self::plain((string) $movement->description.' '.(string) $movement->reference);
        $amount = (int) $movement->amount_cents;

        return str_contains($text, self::plain((string) $rule->match_text))
            && ($rule->flow === 'any' || $rule->flow === $movement->flow)
            && ($rule->min_amount_cents === null || $amount >= (int) $rule->min_amount_cents)
            && ($rule->max_amount_cents === null || $amount <= (int) $rule->max_amount_cents);
    }

    private function assertAccount(int $companyId, int $accountId): void
    {
        if (! DB::table('ledger_accounts')->where('company_id', $companyId)->where('id', $accountId)->exists()) {
            throw ValidationException::withMessages(['ledger_account_id' => 'La cuenta debe pertenecer a esta empresa.']);
        }
    }

    private static function plain(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', Str::lower(Str::ascii($value))) ?? '');
    }
}
