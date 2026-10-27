<?php

declare(strict_types=1);

namespace App\Services\Receivables;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\ReportExportService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;
use Throwable;

/**
 * Automatic collection: once a day each company with rules turned on reminds its customers by itself, with the firm's
 * own templates. Per customer at most one message goes out per run, the most urgent one (broken promise, overdue step,
 * promise due tomorrow, documents about to fall due, monthly statement), and none if the customer got any reminder in
 * the last quiet days. Every step carries a key, so it reaches the same customer only once.
 */
class CollectionAutomationService
{
    /** Overdue steps offered by default: a day after, then a week, two weeks and a month after the due date. */
    public const DEFAULT_OVERDUE_STEPS = [1, 8, 15, 30];

    private const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    public function __construct(
        private CollectionReminderService $reminders,
        private ReceivableService $receivables,
        private CustomerStatementService $statements,
        private ReportExportService $exports,
        private AccountingService $accounting,
    ) {}

    /**
     * The company's rules, or the suggested ones (turned off) when it has none yet.
     *
     * @return array{enabled: bool, upcoming_days: int|null, overdue_steps: list<int>, promise_reminder: bool, broken_promise: bool, monthly_statement: bool, min_overdue_cents: int, quiet_days: int, paused_customer_ids: list<int>, configured_by: string|null, last_run_at: string|null}
     */
    public function rules(int $companyId): array
    {
        $row = DB::table('collection_automation_rules')->leftJoin('users', 'users.id', '=', 'collection_automation_rules.configured_by')
            ->where('collection_automation_rules.company_id', $companyId)->first(['collection_automation_rules.*', 'users.name as configured_by_name']);
        if ($row === null) {
            return ['enabled' => false, 'upcoming_days' => 3, 'overdue_steps' => self::DEFAULT_OVERDUE_STEPS, 'promise_reminder' => true, 'broken_promise' => true,
                'monthly_statement' => false, 'min_overdue_cents' => 0, 'quiet_days' => 3, 'paused_customer_ids' => [], 'configured_by' => null, 'last_run_at' => null];
        }

        return ['enabled' => (bool) $row->enabled, 'upcoming_days' => $row->upcoming_days === null ? null : (int) $row->upcoming_days, 'overdue_steps' => $this->integers($row->overdue_steps),
            'promise_reminder' => (bool) $row->promise_reminder, 'broken_promise' => (bool) $row->broken_promise, 'monthly_statement' => (bool) $row->monthly_statement,
            'min_overdue_cents' => (int) $row->min_overdue_cents, 'quiet_days' => (int) $row->quiet_days, 'paused_customer_ids' => $this->integers($row->paused_customer_ids),
            'configured_by' => $row->configured_by_name === null ? null : (string) $row->configured_by_name,
            'last_run_at' => $row->last_run_at === null ? null : Carbon::parse($row->last_run_at)->toIso8601String()];
    }

    /**
     * Saves the rules. Whoever saves them becomes the sender: replies reach that person when the firm has no verified
     * reply address.
     *
     * @param  array{enabled: bool, upcoming_days: int|null, overdue_steps: list<int>, promise_reminder: bool, broken_promise: bool, monthly_statement: bool, min_overdue_cents: int, quiet_days: int}  $data
     */
    public function save(Organization $organization, User $actor, int $companyId, array $data): void
    {
        $steps = array_values(array_unique(array_map('intval', $data['overdue_steps'])));
        sort($steps);
        if ($data['enabled'] && $data['upcoming_days'] === null && $steps === [] && ! $data['promise_reminder'] && ! $data['broken_promise'] && ! $data['monthly_statement']) {
            throw ValidationException::withMessages(['enabled' => 'Activa al menos un recordatorio para encender la cobranza automática.']);
        }
        $officeId = (int) DB::table('companies')->where('id', $companyId)->value('office_id');
        $values = ['enabled' => $data['enabled'], 'upcoming_days' => $data['upcoming_days'], 'overdue_steps' => json_encode($steps, JSON_THROW_ON_ERROR),
            'promise_reminder' => $data['promise_reminder'], 'broken_promise' => $data['broken_promise'], 'monthly_statement' => $data['monthly_statement'],
            'min_overdue_cents' => $data['min_overdue_cents'], 'quiet_days' => $data['quiet_days'], 'configured_by' => $actor->id, 'updated_at' => now()];
        if (DB::table('collection_automation_rules')->where('company_id', $companyId)->exists()) {
            DB::table('collection_automation_rules')->where('company_id', $companyId)->update($values);
        } else {
            DB::table('collection_automation_rules')->insert([...$values, 'organization_id' => $organization->id, 'office_id' => $officeId, 'company_id' => $companyId,
                'paused_customer_ids' => '[]', 'created_at' => now()]);
        }
        $this->accounting->audit($organization, $actor, 'collections.automation.updated', 'companies', $companyId, ['enabled' => $data['enabled']], $officeId);
    }

    /** @return array{enabled: bool, paused: bool} */
    public function customerState(int $companyId, int $customerId): array
    {
        $rules = $this->rules($companyId);

        return ['enabled' => $rules['enabled'], 'paused' => in_array($customerId, $rules['paused_customer_ids'], true)];
    }

    /** Stops (or resumes) the automatic reminders of one customer, for example while a payment plan is negotiated. */
    public function pause(Organization $organization, User $actor, int $companyId, int $customerId, bool $paused): void
    {
        $rules = $this->rules($companyId);
        if (DB::table('collection_automation_rules')->where('company_id', $companyId)->doesntExist()) {
            $this->save($organization, $actor, $companyId, [...$rules, 'enabled' => false]);
        }
        $ids = collect($rules['paused_customer_ids'])->reject(fn (int $id): bool => $id === $customerId);
        if ($paused) {
            $ids->push($customerId);
        }
        DB::table('collection_automation_rules')->where('company_id', $companyId)->update(['paused_customer_ids' => json_encode($ids->values()->all(), JSON_THROW_ON_ERROR), 'updated_at' => now()]);
        $this->accounting->audit($organization, $actor, $paused ? 'collections.customer.paused' : 'collections.customer.resumed', 'sales_customers', $customerId);
    }

    /**
     * What each customer would receive today, without sending anything.
     *
     * @return list<array{customer_id: int, customer: string, kind: string, kind_label: string, reason: string}>
     */
    public function plan(int $companyId): array
    {
        $rules = DB::table('collection_automation_rules')->where('company_id', $companyId)->first();

        return $rules === null ? [] : array_map(fn (array $step): array => ['customer_id' => $step['customer_id'], 'customer' => $step['customer'], 'kind' => $step['kind'],
            'kind_label' => $step['kind_label'], 'reason' => $step['reason']], $this->steps($rules));
    }

    /** Runs every company of the organization whose rules are on. Returns how many reminders were queued. */
    public function sweep(Organization $organization): int
    {
        $sent = 0;
        foreach (DB::table('collection_automation_rules')->where('organization_id', $organization->id)->where('enabled', true)->orderBy('id')->get() as $rules) {
            $sent += $this->run($organization, $rules);
        }

        return $sent;
    }

    private function run(Organization $organization, stdClass $rules): int
    {
        $sender = User::find((int) $rules->configured_by);
        if ($sender === null) {
            return 0;
        }
        $templates = collect($this->reminders->templates($organization))->groupBy('kind')->map(fn (Collection $group): int => (int) $group->min('id'));
        $sent = 0;
        foreach ($this->steps($rules) as $step) {
            $templateId = $templates->get($step['kind']);
            if ($templateId === null) {
                continue;
            }
            try {
                $range = $step['range'];
                $attachment = isset($range['from'], $range['to'])
                    ? (string) $this->exports->download($this->statements->report($this->statements->statement((int) $rules->company_id, $step['customer_id'], $range['from'], $range['to'])), 'pdf')->getContent()
                    : null;
                $this->reminders->send($organization, $sender, (int) $rules->company_id, $step['customer_id'], $templateId, $attachment, $step['range'], $step['key']);
                $sent++;
            } catch (UniqueConstraintViolationException) {
                // Another run already covered this step.
            } catch (Throwable $exception) {
                report($exception);
            }
        }
        DB::table('collection_automation_rules')->where('id', $rules->id)->update(['last_run_at' => now()]);

        return $sent;
    }

    /**
     * The one message each customer is due today, if any.
     *
     * @return list<array{customer_id: int, customer: string, kind: string, kind_label: string, reason: string, key: string, range: array{from?: string, to?: string}}>
     */
    private function steps(stdClass $rules): array
    {
        $companyId = (int) $rules->company_id;
        $today = Carbon::parse($this->receivables->today($companyId));
        $paused = $this->integers($rules->paused_customer_ids);
        $overdueSteps = $this->integers($rules->overdue_steps);
        $documents = collect($this->receivables->documents([$companyId]))->where('disputed', false)->groupBy('customer_id');
        $customers = DB::table('sales_customers')->where('company_id', $companyId)->whereNotNull('email')->get(['id', 'name', 'email'])
            ->filter(fn (stdClass $customer): bool => filter_var($customer->email, FILTER_VALIDATE_EMAIL) !== false && ! in_array((int) $customer->id, $paused, true));
        $recent = DB::table('collection_reminders')->where('company_id', $companyId)->where('status', '!=', 'failed')
            ->where('created_at', '>=', now()->subDays((int) $rules->quiet_days))->pluck('sales_customer_id')->map(fn ($id): int => (int) $id)->all();
        $alreadySent = DB::table('collection_reminders')->where('company_id', $companyId)->whereNotNull('automation_key')
            ->get(['sales_customer_id', 'automation_key'])->map(fn (stdClass $row): string => $row->sales_customer_id.'|'.$row->automation_key)->flip();
        $promises = DB::table('promises_to_pay')->where('company_id', $companyId)->whereIn('status', ['pending', 'partially_fulfilled', 'broken'])
            ->orderBy('promised_date')->get(['id', 'sales_customer_id', 'status', 'promised_date'])->groupBy('sales_customer_id');

        $steps = [];
        foreach ($customers as $customer) {
            $customerId = (int) $customer->id;
            if (in_array($customerId, $recent, true)) {
                continue;
            }
            $open = $documents->get($customerId, collect());
            $candidates = [];
            foreach ($promises->get($customerId, collect()) as $promise) {
                $date = Carbon::parse((string) $promise->promised_date);
                if ($rules->broken_promise && $promise->status === 'broken') {
                    $candidates[] = ['broken_promise', 'broken:'.$promise->id, 'Incumplió la promesa de pago del '.$date->format('d/m/Y'), []];
                } elseif ($rules->promise_reminder && $promise->status !== 'broken' && $date->isSameDay($today->copy()->addDay())) {
                    $candidates[] = ['promise', 'promise:'.$promise->id, 'Tiene una promesa de pago para mañana', []];
                }
            }
            $overdue = $open->where('overdue', true);
            if ($overdue->isNotEmpty() && (int) $overdue->sum('outstanding_cents') >= (int) $rules->min_overdue_cents) {
                $days = (int) $overdue->max('overdue_days');
                $step = collect($overdueSteps)->filter(fn (int $threshold): bool => $threshold <= $days)->max();
                if ($step !== null) {
                    $candidates[] = ['overdue', 'overdue:'.$step.':'.$overdue->min('due_date'), $days === 1 ? 'Tiene documentos vencidos desde ayer' : "Tiene documentos con {$days} días de atraso", []];
                }
            }
            if ($rules->upcoming_days !== null) {
                $upcoming = $open->where('overdue', false)->filter(fn (array $row): bool => $row['days_to_due'] >= 0 && $row['days_to_due'] <= (int) $rules->upcoming_days);
                if ($upcoming->isNotEmpty()) {
                    $candidates[] = ['upcoming', 'upcoming:'.$upcoming->min('due_date'), 'Tiene documentos que vencen el '.Carbon::parse((string) $upcoming->min('due_date'))->format('d/m/Y'), []];
                }
            }
            if ($rules->monthly_statement && $open->isNotEmpty()) {
                $month = $today->copy()->subMonthNoOverflow();
                $candidates[] = ['statement', 'statement:'.$month->format('Y-m'), 'Estado de cuenta de '.self::MONTHS[$month->month - 1].' '.$month->year,
                    ['from' => $month->copy()->startOfMonth()->toDateString(), 'to' => $month->copy()->endOfMonth()->toDateString()]];
            }
            foreach ($candidates as [$kind, $key, $reason, $range]) {
                if ($alreadySent->has($customerId.'|'.$key)) {
                    continue;
                }
                $steps[] = ['customer_id' => $customerId, 'customer' => (string) $customer->name, 'kind' => $kind, 'kind_label' => CollectionReminderService::KINDS[$kind], 'reason' => $reason, 'key' => $key, 'range' => $range];
                break;
            }
        }

        return $steps;
    }

    /** @return list<int> */
    private function integers(mixed $json): array
    {
        $values = is_string($json) ? json_decode($json, true) : $json;

        return is_array($values) ? array_values(array_map('intval', $values)) : [];
    }
}
