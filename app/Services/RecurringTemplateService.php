<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use App\Support\SpanishMonth;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;
use Throwable;

/**
 * What repeats in the books, done by itself:
 *  - recurring journal entries (rent, depreciation, provisions) drafted on their day, each period once;
 *  - recurring invoices (a monthly fee to a customer) drafted on their day;
 *  - entries scheduled to reverse themselves (a provision of this month undone on the first day of the next one): once
 *    the original is posted, its reversal is posted on that date.
 * Drafts follow the normal review; nothing is posted without the usual approval except the reversal of an entry that
 * was already approved and posted.
 */
class RecurringTemplateService
{
    public const FREQUENCIES = ['monthly' => 'Mensual', 'quarterly' => 'Trimestral', 'yearly' => 'Anual'];

    private const MONTHS = ['monthly' => 1, 'quarterly' => 3, 'yearly' => 12];

    /** Runs a template misses at most once per run when it fell behind, so a forgotten template never floods the books. */
    private const MAX_CATCH_UP = 12;

    public function __construct(
        private AccountingService $accounting,
        private SalesInvoiceDraftService $invoices,
    ) {}

    /** @return list<array<string, mixed>> */
    public function templates(int $companyId): array
    {
        return array_values(DB::table('recurring_templates')->where('company_id', $companyId)->orderBy('kind')->orderBy('name')->get()
            ->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'kind' => (string) $row->kind, 'name' => (string) $row->name, 'frequency' => (string) $row->frequency,
                'day_of_month' => (int) $row->day_of_month, 'next_run_on' => (string) $row->next_run_on, 'ends_on' => $row->ends_on === null ? null : (string) $row->ends_on,
                'auto_reverse' => (bool) $row->auto_reverse, 'is_active' => (bool) $row->is_active, 'runs' => (int) $row->runs,
                'last_run_on' => $row->last_run_on === null ? null : (string) $row->last_run_on, 'last_error' => $row->last_error === null ? null : (string) $row->last_error,
                'payload' => json_decode((string) $row->payload, true)])->all());
    }

    /**
     * @param  array{kind: string, name: string, frequency: string, day_of_month: int, starts_on: string, ends_on: string|null, auto_reverse: bool, payload: array<string, mixed>}  $data
     */
    public function create(Organization $organization, User $user, int $companyId, array $data): int
    {
        $payload = $data['kind'] === 'entry' ? $this->entryPayload($companyId, $data['payload']) : $this->invoicePayload($companyId, $data['payload']);
        $first = $this->firstRun(CarbonImmutable::parse($data['starts_on']), $data['day_of_month']);
        if ($data['ends_on'] !== null && $data['ends_on'] < $first->toDateString()) {
            throw ValidationException::withMessages(['ends_on' => 'La fecha final debe ser posterior a la primera ejecución.']);
        }
        $id = DB::table('recurring_templates')->insertGetId([
            'organization_id' => $organization->id, 'company_id' => $companyId, 'kind' => $data['kind'], 'name' => $data['name'], 'frequency' => $data['frequency'],
            'day_of_month' => $data['day_of_month'], 'next_run_on' => $first->toDateString(), 'ends_on' => $data['ends_on'], 'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'auto_reverse' => $data['kind'] === 'entry' && $data['auto_reverse'], 'is_active' => true, 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->accounting->audit($organization, $user, 'recurring_template.created', 'recurring_templates', $id, ['kind' => $data['kind'], 'frequency' => $data['frequency']]);

        return $id;
    }

    public function toggle(Organization $organization, User $user, int $companyId, int $templateId, bool $active): void
    {
        abort_unless(DB::table('recurring_templates')->where('company_id', $companyId)->where('id', $templateId)->update(['is_active' => $active, 'updated_at' => now()]) === 1, 404);
        $this->accounting->audit($organization, $user, $active ? 'recurring_template.activated' : 'recurring_template.paused', 'recurring_templates', $templateId, []);
    }

    /** Schedules a draft entry (a provision) to reverse itself on a date once it is posted. */
    public function scheduleReversal(Organization $organization, User $user, int $companyId, string $entryPublicId, ?string $date): void
    {
        $entry = DB::table('journal_entries')->join('accounting_periods', 'accounting_periods.id', '=', 'journal_entries.accounting_period_id')
            ->where('accounting_periods.company_id', $companyId)->where('journal_entries.public_id', $entryPublicId)->first(['journal_entries.*']);
        abort_if($entry === null, 404);
        if (! in_array($entry->status, ['draft', 'pending_review', 'reviewed', 'approved'], true)) {
            throw ValidationException::withMessages(['auto_reverse_on' => 'El reverso automático se programa antes de contabilizar el asiento.']);
        }
        if ($date !== null && $date <= (string) $entry->entry_date) {
            throw ValidationException::withMessages(['auto_reverse_on' => 'El reverso debe ser posterior a la fecha del asiento.']);
        }
        DB::table('journal_entries')->where('id', $entry->id)->update(['auto_reverse_on' => $date, 'updated_at' => now()]);
        $this->accounting->audit($organization, $user, 'journal_entry.auto_reverse_scheduled', 'journal_entries', (int) $entry->id, ['on' => $date]);
    }

    /** @return list<array{public_id: string, description: string, entry_date: string, status: string, auto_reverse_on: string|null, reversed: bool}> */
    public function scheduledReversals(int $companyId): array
    {
        return array_values(DB::table('journal_entries')->join('accounting_periods', 'accounting_periods.id', '=', 'journal_entries.accounting_period_id')
            ->where('accounting_periods.company_id', $companyId)->whereNotNull('journal_entries.auto_reverse_on')->orderByDesc('journal_entries.auto_reverse_on')->limit(30)
            ->get(['journal_entries.id', 'journal_entries.public_id', 'journal_entries.description', 'journal_entries.entry_date', 'journal_entries.status', 'journal_entries.auto_reverse_on'])
            ->map(fn (stdClass $row): array => ['public_id' => (string) $row->public_id, 'description' => (string) $row->description, 'entry_date' => (string) $row->entry_date,
                'status' => (string) $row->status, 'auto_reverse_on' => (string) $row->auto_reverse_on,
                'reversed' => DB::table('journal_entries')->where('reversal_of', $row->id)->exists()])->all());
    }

    /**
     * Runs today's recurring templates and reversals of the organization.
     *
     * @return array{entries: int, invoices: int, reversals: int, errors: int}
     */
    public function run(Organization $organization, ?CarbonInterface $today = null): array
    {
        $today = CarbonImmutable::parse(($today ?? now('America/Guatemala'))->toDateString())->toDateString();
        $totals = ['entries' => 0, 'invoices' => 0, 'reversals' => 0, 'errors' => 0];
        $templates = DB::table('recurring_templates')->where('organization_id', $organization->id)->where('is_active', true)->where('next_run_on', '<=', $today)->orderBy('id')->get();
        foreach ($templates as $template) {
            $actor = $this->actor($organization, $template->created_by === null ? null : (int) $template->created_by);
            if ($actor === null) {
                continue;
            }
            for ($run = 0; $run < self::MAX_CATCH_UP; $run++) {
                $date = (string) $template->next_run_on;
                if ($date > $today || ($template->ends_on !== null && $date > (string) $template->ends_on)) {
                    break;
                }
                try {
                    DB::transaction(function () use ($organization, $actor, $template, $date, &$totals): void {
                        if ($template->kind === 'entry') {
                            $this->runEntry($organization, $actor, $template, $date);
                            $totals['entries']++;
                        } else {
                            $this->runInvoice($organization, $actor, $template, $date);
                            $totals['invoices']++;
                        }
                    });
                    $template->next_run_on = $this->next(CarbonImmutable::parse($date), (string) $template->frequency, (int) $template->day_of_month)->toDateString();
                    DB::table('recurring_templates')->where('id', $template->id)->update(['next_run_on' => $template->next_run_on, 'last_run_on' => $date, 'runs' => DB::raw('runs + 1'),
                        'last_error' => null, 'is_active' => $template->ends_on === null || $template->next_run_on <= (string) $template->ends_on, 'updated_at' => now()]);
                } catch (Throwable $exception) {
                    $totals['errors']++;
                    $message = $exception instanceof ValidationException ? (string) collect($exception->errors())->flatten()->first() : $exception->getMessage();
                    DB::table('recurring_templates')->where('id', $template->id)->update(['last_error' => mb_substr($message, 0, 255), 'updated_at' => now()]);
                    Log::warning('Recurring template failed', ['template_id' => $template->id, 'error' => $message]);

                    break;
                }
            }
        }
        $totals['reversals'] = $this->reverseDue($organization, $today);

        return $totals;
    }

    private function runEntry(Organization $organization, User $actor, stdClass $template, string $date): void
    {
        $payload = json_decode((string) $template->payload, true);
        $lines = $this->accounting->lines((int) $template->company_id, $payload['lines'] ?? []);
        $entryId = $this->accounting->createDraft($organization, $actor, (object) ['id' => (int) $template->company_id], [
            'request_id' => (string) Str::uuid(), 'period_id' => $this->period((int) $template->company_id, $date), 'entry_date' => $date,
            'description' => mb_substr(($payload['description'] ?? $template->name).' · '.SpanishMonth::label(CarbonImmutable::parse($date)), 0, 250),
            'reference' => 'REC-'.$template->id, 'kind' => $template->auto_reverse ? 'provision' : 'standard', 'posting_key' => 'recurring:'.$template->id.':'.$date,
        ], $lines);
        if ($template->auto_reverse) {
            DB::table('journal_entries')->where('id', $entryId)->update(['auto_reverse_on' => CarbonImmutable::parse($date)->startOfMonth()->addMonthNoOverflow()->toDateString()]);
        }
    }

    private function runInvoice(Organization $organization, User $actor, stdClass $template, string $date): void
    {
        $payload = json_decode((string) $template->payload, true);
        $company = DB::table('companies')->where('id', $template->company_id)->first() ?? abort(404);
        $this->invoices->create($organization, $actor, $company, [
            'request_id' => (string) Str::uuid(), 'customer_id' => (int) ($payload['customer_id'] ?? 0), 'issued_on' => $date,
            'due_on' => CarbonImmutable::parse($date)->addDays((int) ($payload['due_days'] ?? 0))->toDateString(),
            'lines' => array_map(fn (array $line): array => ['description' => (string) $line['description'], 'quantity' => (int) $line['quantity'], 'unit_price' => (string) $line['unit_price'],
                'tax_treatment' => (string) ($line['tax_treatment'] ?? 'services')], $payload['lines'] ?? []),
        ], ['source' => 'recurring_template', 'template_id' => (int) $template->id]);
    }

    /** Posts the reversal of every posted entry whose scheduled date arrived. */
    private function reverseDue(Organization $organization, string $today): int
    {
        $entries = DB::table('journal_entries')->join('accounting_periods', 'accounting_periods.id', '=', 'journal_entries.accounting_period_id')
            ->where('accounting_periods.organization_id', $organization->id)->where('journal_entries.status', 'posted')->whereNull('journal_entries.reversal_of')
            ->where('journal_entries.auto_reverse_on', '<=', $today)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('journal_entries as r')->whereColumn('r.reversal_of', 'journal_entries.id'))
            ->limit(500)->get(['journal_entries.*', 'accounting_periods.company_id']);
        $reversed = 0;
        foreach ($entries as $entry) {
            $actor = $this->actor($organization, $entry->posted_by === null ? null : (int) $entry->posted_by);
            if ($actor === null) {
                continue;
            }
            try {
                DB::transaction(function () use ($organization, $actor, $entry): void {
                    $date = (string) $entry->auto_reverse_on;
                    $this->accounting->reverse($organization, $actor, (int) $entry->company_id, $entry, $this->period((int) $entry->company_id, $date), $date, 'reverso automático de '.$entry->description);
                });
                $reversed++;
            } catch (Throwable $exception) {
                Log::warning('Automatic reversal failed', ['journal_entry_id' => $entry->id, 'error' => $exception->getMessage()]);
            }
        }

        return $reversed;
    }

    /** The open period that holds a date, created for its month when the company has none. */
    private function period(int $companyId, string $date): int
    {
        $existing = DB::table('accounting_periods')->where('company_id', $companyId)->whereNull('closed_at')->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)
            ->orderByDesc('starts_on')->value('id');
        if ($existing !== null) {
            return (int) $existing;
        }
        if (DB::table('accounting_periods')->where('company_id', $companyId)->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)->exists()) {
            throw ValidationException::withMessages(['period' => 'El período del '.$date.' está cerrado.']);
        }
        $day = CarbonImmutable::parse($date);

        return (int) DB::table('accounting_periods')->insertGetId(['company_id' => $companyId, 'name' => ucfirst(SpanishMonth::label($day)),
            'starts_on' => $day->startOfMonth()->toDateString(), 'ends_on' => $day->endOfMonth()->toDateString(), 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function actor(Organization $organization, ?int $preferred): ?User
    {
        $active = fn (int $id): bool => DB::table('organization_user')->where('organization_id', $organization->id)->where('user_id', $id)->where('status', 'active')->exists();
        if ($preferred !== null && $active($preferred)) {
            return User::query()->find($preferred);
        }
        $owner = DB::table('organization_user')->where('organization_id', $organization->id)->where('role', 'owner')->where('status', 'active')->orderBy('id')->value('user_id');

        return $owner === null ? null : User::query()->find($owner);
    }

    private function firstRun(CarbonImmutable $start, int $day): CarbonImmutable
    {
        $candidate = $start->startOfMonth()->addDays($day - 1);

        return $candidate->lt($start) ? $candidate->addMonthNoOverflow() : $candidate;
    }

    private function next(CarbonImmutable $date, string $frequency, int $day): CarbonImmutable
    {
        return $date->startOfMonth()->addMonthsNoOverflow(self::MONTHS[$frequency] ?? 1)->addDays($day - 1);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{description: string, lines: list<array{account_id: int, debit: string, credit: string, description: string|null}>}
     */
    private function entryPayload(int $companyId, array $payload): array
    {
        $lines = array_values(array_map(fn (array $line): array => ['account_id' => (int) $line['account_id'], 'debit' => (string) ($line['debit'] ?? '0') ?: '0',
            'credit' => (string) ($line['credit'] ?? '0') ?: '0', 'description' => filled($line['description'] ?? null) ? (string) $line['description'] : null], (array) ($payload['lines'] ?? [])));
        // Validates accounts and balance now, so a wrong template is rejected when it is written, not on its day.
        $this->accounting->lines($companyId, $lines);

        return ['description' => (string) ($payload['description'] ?? ''), 'lines' => $lines];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{customer_id: int, due_days: int, lines: list<array{description: string, quantity: int, unit_price: string, tax_treatment: string}>}
     */
    private function invoicePayload(int $companyId, array $payload): array
    {
        $customerId = (int) ($payload['customer_id'] ?? 0);
        if (! DB::table('sales_customers')->where('company_id', $companyId)->where('id', $customerId)->exists()) {
            throw ValidationException::withMessages(['customer_id' => 'Elige un cliente de esta empresa.']);
        }
        $lines = array_values(array_map(fn (array $line): array => ['description' => mb_substr(trim((string) $line['description']), 0, 250), 'quantity' => max(1, (int) $line['quantity']),
            'unit_price' => (string) $line['unit_price'], 'tax_treatment' => in_array($line['tax_treatment'] ?? 'services', ['goods', 'services', 'exempt'], true) ? (string) ($line['tax_treatment'] ?? 'services') : 'services'],
            (array) ($payload['lines'] ?? [])));
        if ($lines === [] || collect($lines)->contains(fn (array $line): bool => $line['description'] === '' || $this->accounting->cents($line['unit_price']) <= 0)) {
            throw ValidationException::withMessages(['lines' => 'Cada línea necesita descripción y un precio mayor que cero.']);
        }

        return ['customer_id' => $customerId, 'due_days' => max(0, min(120, (int) ($payload['due_days'] ?? 0))), 'lines' => $lines];
    }
}
