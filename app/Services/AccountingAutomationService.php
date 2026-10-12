<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

class AccountingAutomationService
{
    private const DEFAULT_RULES = [
        'sales_invoice.origin' => ['receivable_account_id', 'revenue_account_id'],
        'sales_receipt.origin' => ['cash_account_id', 'receivable_account_id'],
        'purchase_document.origin' => ['default_expense_account_id', 'payable_account_id'],
        'purchase_payment.origin' => ['payable_account_id', 'cash_account_id'],
    ];

    public function __construct(private AccountingService $accounting) {}

    /** Create default mappings from the starter chart without overwriting company choices. */
    public function ensureMapping(int $companyId): ?stdClass
    {
        $mapping = DB::table('company_accounting_mappings')->where('company_id', $companyId)->first();
        if ($mapping) {
            $this->ensureDefaultRules($companyId);

            return $mapping;
        }

        $this->accounting->installDefaultChart($companyId);
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
        $this->ensureDefaultRules($companyId);

        return DB::table('company_accounting_mappings')->where('company_id', $companyId)->first();
    }

    public function ensureDefaultRules(int $companyId): void
    {
        $now = now();
        foreach (self::DEFAULT_RULES as $event => [$debit, $credit]) {
            DB::table('accounting_posting_rules')->insertOrIgnore([
                'company_id' => $companyId,
                'event' => $event,
                'debit_account_key' => $debit,
                'credit_account_key' => $credit,
                'is_active' => true,
                'metadata' => json_encode(['system_default' => true], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
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
        $this->ensureDefaultRules($companyId);
    }

    /**
     * @param  array<string, mixed>  $dimensions
     * @return list<array{account_id: int, debit: string, credit: string, description?: string, third_party_type?: string, third_party_id?: int, cost_center?: string, project?: string}>
     */
    public function ruleLines(int $companyId, string $event, int $amountCents, array $dimensions = []): array
    {
        if ($amountCents <= 0) {
            throw ValidationException::withMessages(['accounting' => 'La regla contable requiere un importe mayor que cero.']);
        }
        $mapping = $this->ensureMapping($companyId);
        if (! $mapping) {
            throw ValidationException::withMessages(['accounting' => 'Configura el mapeo contable antes de generar asientos automáticos.']);
        }
        $this->ensureDefaultRules($companyId);
        $rule = DB::table('accounting_posting_rules')->where('company_id', $companyId)->where('event', $event)->where('is_active', true)->first();
        if (! $rule) {
            throw ValidationException::withMessages(['accounting' => "No existe una regla activa para el evento {$event}."]);
        }
        foreach ([$rule->debit_account_key, $rule->credit_account_key] as $key) {
            if (! isset($mapping->{$key})) {
                throw ValidationException::withMessages(['accounting' => "La regla {$event} usa una cuenta no configurada."]);
            }
        }
        $amount = $this->decimal($amountCents);
        $common = array_filter([
            'description' => $dimensions['description'] ?? null,
            'third_party_type' => $dimensions['third_party_type'] ?? null,
            'third_party_id' => $dimensions['third_party_id'] ?? null,
            'cost_center' => $dimensions['cost_center'] ?? null,
            'project' => $dimensions['project'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        return [
            [...$common, 'account_id' => (int) $mapping->{$rule->debit_account_key}, 'debit' => $amount, 'credit' => '0'],
            [...$common, 'account_id' => (int) $mapping->{$rule->credit_account_key}, 'debit' => '0', 'credit' => $amount],
        ];
    }

    /**
     * Rule lines for a gross amount whose credit side includes a tax: the rule's credit account receives the
     * net amount and the tax goes to its own liability account (e.g. revenue + IVA débito fiscal).
     *
     * @param  array<string, mixed>  $dimensions
     * @return list<array<string, mixed>>
     */
    public function ruleLinesWithTax(int $companyId, string $event, int $grossCents, int $taxCents, int $taxAccountId, array $dimensions = []): array
    {
        if ($taxCents <= 0 || $taxCents >= $grossCents) {
            throw ValidationException::withMessages(['accounting' => 'El impuesto debe ser mayor que cero y menor que el total del documento.']);
        }

        [$debit, $credit] = $this->ruleLines($companyId, $event, $grossCents, $dimensions);
        $credit['credit'] = $this->decimal($grossCents - $taxCents);

        return [
            $debit,
            $credit,
            [...Arr::except($credit, ['account_id', 'credit', 'description']), 'account_id' => $taxAccountId, 'debit' => '0',
                'credit' => $this->decimal($taxCents), 'description' => 'IVA débito fiscal'],
        ];
    }

    /** @param array<string, mixed> $dimensions */
    public function draftFromRule(
        Organization $organization,
        User $user,
        int $companyId,
        string $sourceType,
        int $sourceId,
        string $date,
        string $description,
        string $reference,
        int $amountCents,
        array $dimensions = [],
        ?string $event = null,
    ): ?int {
        $eventName = $event ?? "{$sourceType}.origin";

        return $this->draft($organization, $user, $companyId, $sourceType, $sourceId, $date, $description, $reference,
            $this->ruleLines($companyId, $eventName, $amountCents, ['description' => $description, ...$dimensions]), $eventName);
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
        ?string $event = null,
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
        $postingKey = "{$sourceType}:{$sourceId}:origin";
        $existing = DB::table('journal_entries')->where('posting_key', $postingKey)->value('id');
        if ($existing) {
            return (int) $existing;
        }

        $period = DB::table('accounting_periods')->where('company_id', $companyId)
            ->whereNull('closed_at')->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)
            ->orderByDesc('starts_on')->first();
        if (! $period) {
            $day = Carbon::parse($date)->startOfDay();
            $day->locale('es');
            $periodId = DB::table('accounting_periods')->insertGetId([
                'company_id' => $companyId,
                'name' => $day->translatedFormat('F Y'),
                'starts_on' => $day->copy()->startOfMonth()->toDateString(),
                'ends_on' => $day->copy()->endOfMonth()->toDateString(),
                'status' => 'open',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $period = DB::table('accounting_periods')->where('id', $periodId)->first();
        }

        $eventName = $event ?? "{$sourceType}.origin";
        $normalized = $this->accounting->lines($companyId, $lines);
        $entryId = $this->accounting->createDraft($organization, $user, (object) ['id' => $companyId], [
            'request_id' => (string) Str::uuid(),
            'period_id' => $period->id,
            'entry_date' => $date,
            'description' => $description,
            'reference' => $reference,
            'kind' => 'automatic',
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'accounting_event' => $eventName,
            'posting_key' => $postingKey,
        ], $normalized);
        $this->accounting->audit($organization, $user, 'entry.automated.drafted', 'journal_entries', $entryId, [
            'source_type' => $sourceType,
            'source_id' => $sourceId,
        ]);

        return $entryId;
    }

    private function decimal(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
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
