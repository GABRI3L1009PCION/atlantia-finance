<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

class AccountingAutomationService
{
    public function __construct(private AccountingService $accounting) {}

    /** Create default mappings from the starter chart without overwriting company choices. */
    public function ensureMapping(int $companyId): ?stdClass
    {
        $mapping = DB::table('company_accounting_mappings')->where('company_id', $companyId)->first();
        if ($mapping) {
            return $mapping;
        }

        $accounts = DB::table('ledger_accounts')->where('company_id', $companyId)
            ->whereIn('code', ['1102', '1103', '2102', '4101', '5201'])->pluck('id', 'code');
        if ($accounts->count() !== 5) {
            return null;
        }

        DB::table('company_accounting_mappings')->insertOrIgnore([
            'company_id' => $companyId,
            'cash_account_id' => $accounts['1102'],
            'receivable_account_id' => $accounts['1103'],
            'revenue_account_id' => $accounts['4101'],
            'payable_account_id' => $accounts['2102'],
            'default_expense_account_id' => $accounts['5201'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('company_accounting_mappings')->where('company_id', $companyId)->first();
    }

    /**
     * @param array{
     *     cash_account_id: int|string,
     *     receivable_account_id: int|string,
     *     revenue_account_id: int|string,
     *     payable_account_id: int|string,
     *     default_expense_account_id: int|string
     * } $data
     */
    public function updateMapping(int $companyId, array $data): void
    {
        $ids = collect($data)->map(fn ($value) => (int) $value)->values();
        $valid = DB::table('ledger_accounts')->where('company_id', $companyId)->whereIn('id', $ids)->count();
        if ($valid !== $ids->unique()->count()) {
            throw ValidationException::withMessages(['mapping' => 'Todas las cuentas seleccionadas deben pertenecer a esta empresa.']);
        }

        $exists = DB::table('company_accounting_mappings')->where('company_id', $companyId)->exists();
        DB::table('company_accounting_mappings')->updateOrInsert(
            ['company_id' => $companyId],
            [...$data, 'updated_at' => now(), ...($exists ? [] : ['created_at' => now()])],
        );
    }

    /**
     * Create an idempotent balanced draft. Missing configuration is explicit to the caller.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    public function draft(
        Organization $organization,
        User $user,
        int $companyId,
        string $sourceType,
        int $sourceId,
        string $date,
        string $description,
        string $reference,
        array $lines,
    ): ?int {
        $existing = DB::table('accounting_source_entries')->where([
            'company_id' => $companyId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'event' => 'origin',
        ])->value('journal_entry_id');
        if ($existing) {
            return (int) $existing;
        }

        $period = DB::table('accounting_periods')->where('company_id', $companyId)
            ->whereNull('closed_at')->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)
            ->orderByDesc('starts_on')->first();
        if (! $period) {
            return null;
        }

        $normalized = $this->accounting->lines($companyId, $lines);
        $entryId = DB::table('journal_entries')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'accounting_period_id' => $period->id,
            'entry_date' => $date,
            'description' => $description,
            'reference' => $reference,
            'kind' => 'standard',
            'status' => 'draft',
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('journal_lines')->insert(array_map(fn (array $line): array => [...$line, 'journal_entry_id' => $entryId], $normalized));
        DB::table('accounting_source_entries')->insert([
            'company_id' => $companyId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'event' => 'origin',
            'journal_entry_id' => $entryId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->accounting->audit($organization, $user, 'entry.automated.drafted', 'journal_entries', $entryId, [
            'source_type' => $sourceType,
            'source_id' => $sourceId,
        ]);

        return $entryId;
    }

    /** Preserve the original movement. Drafts are voided; posted entries receive a draft reversal. */
    public function reverseSource(
        Organization $organization,
        User $user,
        int $companyId,
        string $sourceType,
        int $sourceId,
        string $date,
        string $reason,
    ): ?int {
        $source = DB::table('accounting_source_entries')
            ->where('company_id', $companyId)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('event', 'origin')
            ->first();
        if (! $source) {
            return null;
        }
        $entry = DB::table('journal_entries')->where('id', $source->journal_entry_id)->first();
        if (! $entry || $entry->status === 'void') {
            return null;
        }
        if ($entry->status === 'draft') {
            DB::table('journal_entries')->where('id', $entry->id)->update(['status' => 'void', 'updated_at' => now()]);
            $this->accounting->audit($organization, $user, 'entry.automated.voided', 'journal_entries', $entry->id, ['reason' => $reason]);

            return null;
        }

        $existing = DB::table('accounting_source_entries')->where([
            'company_id' => $companyId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'event' => 'reversal',
        ])->value('journal_entry_id');
        if ($existing) {
            return (int) $existing;
        }
        $period = DB::table('accounting_periods')->where('company_id', $companyId)
            ->whereNull('closed_at')->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->first();
        if (! $period) {
            throw ValidationException::withMessages(['accounting' => 'Abre un período que incluya la fecha para registrar el reverso.']);
        }

        $reversalId = DB::table('journal_entries')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'accounting_period_id' => $period->id,
            'entry_date' => $date,
            'description' => 'Reverso automático: '.$reason,
            'reference' => $entry->reference,
            'kind' => 'reversal',
            'status' => 'draft',
            'created_by' => $user->id,
            'reversal_of' => $entry->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('journal_lines')->insert(DB::table('journal_lines')->where('journal_entry_id', $entry->id)->orderBy('position')->get()
            ->map(fn ($line): array => ['journal_entry_id' => $reversalId, 'ledger_account_id' => $line->ledger_account_id,
                'position' => $line->position, 'debit_cents' => $line->credit_cents, 'credit_cents' => $line->debit_cents])->all());
        DB::table('accounting_source_entries')->insert([
            'company_id' => $companyId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'event' => 'reversal',
            'journal_entry_id' => $reversalId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->accounting->audit($organization, $user, 'entry.automated.reversal.drafted', 'journal_entries', $reversalId, ['reason' => $reason]);

        return $reversalId;
    }
}
