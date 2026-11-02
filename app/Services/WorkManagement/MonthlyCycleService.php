<?php

declare(strict_types=1);

namespace App\Services\WorkManagement;

use App\Models\Organization;
use App\Models\User;
use App\Services\ClientPortal\ClientRequestService;
use App\Services\ClientPortal\PublishedReportService;
use App\Services\Fiscal\FiscalObligationGenerationService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use stdClass;
use Throwable;

/**
 * The month an accounting office works for each client, run by itself:
 *  - every day the fiscal calendar is brought up to date (obligations of the coming months);
 *  - on the day the office chooses, the previous month opens for each of its companies: its accounting period exists
 *    and the month's tasks are created from the office's templates;
 *  - the same day the client is asked for its recurring documents (bank statements, payroll, etc.), with a due date;
 *  - once a period is closed, its close package (income statement and balance sheet) is prepared and sent to review,
 *    so a second accountant approves and publishes it to the client. The automation never approves its own work.
 * Every step is logged per company and month, so running it every day never repeats one.
 *
 * @phpstan-type RecurringRequest array{title: string, type: string, due_day: int}
 * @phpstan-type MonthlyCycleSettings array{enabled: bool, open_day: int, recurring_requests: list<RecurringRequest>, close_package: bool}
 */
class MonthlyCycleService
{
    public const REQUEST_TYPES = ['document' => 'Documento', 'information' => 'Información', 'fiscal' => 'Fiscal', 'payroll' => 'Nómina', 'other' => 'Otro'];

    /** Requests suggested until the office writes its own. */
    public const DEFAULT_REQUESTS = [
        ['title' => 'Estados de cuenta bancarios', 'type' => 'document', 'due_day' => 5],
        ['title' => 'Facturas de compra y gastos sin factura', 'type' => 'document', 'due_day' => 5],
        ['title' => 'Novedades de planilla (altas, bajas, horas extra)', 'type' => 'payroll', 'due_day' => 3],
    ];

    private const CLOSE_REPORTS = ['income_statement', 'balance_sheet'];

    public function __construct(
        private TaskTemplateService $templates,
        private ClientRequestService $requests,
        private PublishedReportService $reports,
        private FiscalObligationGenerationService $obligations,
        private WorkNotificationService $notifications,
    ) {}

    /** @return MonthlyCycleSettings */
    public function settings(stdClass $office): array
    {
        $stored = is_string($office->monthly_cycle ?? null) ? json_decode($office->monthly_cycle, true) : null;
        $stored = is_array($stored) ? $stored : [];

        return [
            'enabled' => (bool) ($stored['enabled'] ?? false),
            'open_day' => max(1, min(28, (int) ($stored['open_day'] ?? 1))),
            'recurring_requests' => $this->recurring($stored['recurring_requests'] ?? self::DEFAULT_REQUESTS),
            'close_package' => (bool) ($stored['close_package'] ?? true),
        ];
    }

    /** @param  array{enabled: bool, open_day: int|string, recurring_requests: list<array{title: string, type: string, due_day: int|string}>, close_package: bool}  $data */
    public function save(int $organizationId, int $officeId, array $data): void
    {
        $settings = ['enabled' => $data['enabled'], 'open_day' => max(1, min(28, (int) $data['open_day'])),
            'recurring_requests' => $this->recurring($data['recurring_requests']), 'close_package' => $data['close_package']];
        DB::table('offices')->where('organization_id', $organizationId)->where('id', $officeId)
            ->update(['monthly_cycle' => json_encode($settings, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
    }

    /**
     * What the office screen shows: the settings and the latest months opened.
     *
     * @return array{settings: MonthlyCycleSettings, request_types: array<string, string>, recent: list<array{company: string, period: string, step: string, items: int, at: string}>}
     */
    public function overview(stdClass $office): array
    {
        $recent = DB::table('monthly_cycle_runs as r')->join('companies', 'companies.id', '=', 'r.company_id')->where('r.organization_id', $office->organization_id)
            ->where('companies.office_id', $office->id)->orderByDesc('r.id')->limit(15)->get(['companies.name', 'r.period', 'r.step', 'r.items', 'r.created_at']);

        return ['settings' => $this->settings($office), 'request_types' => self::REQUEST_TYPES, 'recent' => array_values($recent->map(fn (stdClass $row): array => [
            'company' => (string) $row->name, 'period' => (string) $row->period, 'step' => (string) $row->step, 'items' => (int) $row->items,
            'at' => CarbonImmutable::parse((string) $row->created_at)->toIso8601String(),
        ])->all())];
    }

    /**
     * Runs today's steps of the organization. Returns what was done.
     *
     * @return array{obligations: int, opened: int, tasks: int, requests: int, packages: int}
     */
    public function sweep(Organization $organization, ?CarbonInterface $today = null): array
    {
        $today = CarbonImmutable::parse(($today ?? now('America/Guatemala'))->toDateString());
        $totals = ['obligations' => 0, 'opened' => 0, 'tasks' => 0, 'requests' => 0, 'packages' => 0];
        $companyIds = DB::table('companies')->where('organization_id', $organization->id)->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
        if ($companyIds === []) {
            return $totals;
        }
        try {
            $totals['obligations'] = $this->obligations->generate($organization, null, $companyIds)['created'];
        } catch (Throwable $exception) {
            Log::warning('Monthly cycle: fiscal calendar failed', ['organization_id' => $organization->id, 'error' => $exception->getMessage()]);
        }
        $owner = $this->owner($organization);
        if ($owner === null) {
            return $totals;
        }
        $offices = DB::table('offices')->where('organization_id', $organization->id)->whereNull('deleted_at')->where('status', 'active')->whereNotNull('monthly_cycle')->get();
        foreach ($offices as $office) {
            $settings = $this->settings($office);
            if (! $settings['enabled']) {
                continue;
            }
            $companies = DB::table('companies')->where('organization_id', $organization->id)->where('office_id', $office->id)->get(['id', 'name']);
            foreach ($companies as $company) {
                if ($today->day >= $settings['open_day']) {
                    $this->open($organization, $owner, $company, $settings, $today, $totals);
                }
                if ($settings['close_package']) {
                    $totals['packages'] += $this->closePackage($organization, $owner, $company, $today);
                }
            }
        }

        return $totals;
    }

    /**
     * Opens the previous month of a company: its period and tasks, then the recurring requests to the client.
     *
     * @param  MonthlyCycleSettings  $settings
     * @param  array{obligations: int, opened: int, tasks: int, requests: int, packages: int}  $totals
     */
    private function open(Organization $organization, User $owner, stdClass $company, array $settings, CarbonImmutable $today, array &$totals): void
    {
        $month = $today->startOfMonth()->subMonthNoOverflow();
        $period = $month->format('Y-m');
        $label = $month->locale('es')->translatedFormat('F Y');
        if ($this->claim($organization, (int) $company->id, $period, 'opened')) {
            try {
                $created = DB::transaction(function () use ($organization, $owner, $company, $month): int {
                    $periodId = $this->accountingPeriod((int) $company->id, $month);

                    return $periodId === null ? 0 : count($this->templates->generateForPeriod($organization, $owner, (int) $company->id, $periodId));
                });
                $this->logItems((int) $company->id, $period, 'opened', $created);
                $totals['opened']++;
                $totals['tasks'] += $created;
            } catch (Throwable $exception) {
                $this->release((int) $company->id, $period, 'opened');
                Log::warning('Monthly cycle: opening failed', ['company_id' => $company->id, 'period' => $period, 'error' => $exception->getMessage()]);
            }
        }
        if ($settings['recurring_requests'] !== [] && $this->claim($organization, (int) $company->id, $period, 'requests')) {
            $created = 0;
            foreach ($settings['recurring_requests'] as $request) {
                try {
                    DB::transaction(fn (): int => $this->requests->create($organization, $owner, [
                        'company_id' => (int) $company->id, 'type' => $request['type'], 'title' => mb_substr($request['title'].' de '.$label, 0, 180),
                        'description' => 'Solicitud mensual de tu oficina contable para trabajar '.$label.'.', 'period_label' => ucfirst($label),
                        'due_date' => $today->startOfMonth()->addDays(max(1, $request['due_day']) - 1)->max($today)->toDateString(),
                    ]));
                    $created++;
                } catch (Throwable $exception) {
                    Log::warning('Monthly cycle: request failed', ['company_id' => $company->id, 'period' => $period, 'error' => $exception->getMessage()]);
                }
            }
            $this->logItems((int) $company->id, $period, 'requests', $created);
            $totals['requests'] += $created;
        }
    }

    /**
     * Prepares the close package of the company's periods closed in the last 45 days and sends it to review.
     * Returns how many packages were prepared.
     */
    private function closePackage(Organization $organization, User $owner, stdClass $company, CarbonImmutable $today): int
    {
        $periods = DB::table('accounting_periods')->where('company_id', $company->id)->where('status', 'closed')
            ->where('closed_at', '>=', $today->subDays(45))->orderBy('starts_on')->get(['id', 'name', 'starts_on', 'ends_on', 'closed_by']);
        $prepared = 0;
        foreach ($periods as $period) {
            $key = substr((string) $period->starts_on, 0, 7);
            if (! $this->claim($organization, (int) $company->id, $key, 'close_package')) {
                continue;
            }
            $actor = $period->closed_by === null ? $owner : (User::query()->find($period->closed_by) ?? $owner);
            $reports = 0;
            foreach (self::CLOSE_REPORTS as $type) {
                try {
                    DB::transaction(function () use ($organization, $actor, $company, $type, $period): void {
                        $id = $this->reports->prepare($organization, $actor, (int) $company->id, $type, (string) $period->starts_on, (string) $period->ends_on, null);
                        $this->reports->action($organization, $actor, $id, 'submit', null);
                    });
                    $reports++;
                } catch (Throwable $exception) {
                    Log::warning('Monthly cycle: close report failed', ['company_id' => $company->id, 'period' => $key, 'type' => $type, 'error' => $exception->getMessage()]);
                }
            }
            $this->logItems((int) $company->id, $key, 'close_package', $reports);
            if ($reports > 0) {
                $prepared++;
                foreach ($this->reviewers($organization->id, (int) $company->id, (int) $actor->id) as $reviewerId) {
                    $this->notifications->notify($organization->id, $reviewerId, null, 'client_portal', 'Paquete de cierre listo para revisar: '.$company->name.' · '.$period->name,
                        'Revisa, aprueba y publica los estados financieros del período para que el cliente los reciba.', null, null, ['company_id' => (int) $company->id]);
                }
            }
        }

        return $prepared;
    }

    /** The company's accounting period that starts the month, created when the company has none covering it. */
    private function accountingPeriod(int $companyId, CarbonImmutable $month): ?int
    {
        $start = $month->startOfMonth()->toDateString();
        $end = $month->endOfMonth()->toDateString();
        $existing = DB::table('accounting_periods')->where('company_id', $companyId)->where('starts_on', '<=', $start)->where('ends_on', '>=', $start)->value('id');
        if ($existing !== null) {
            return (int) $existing;
        }
        if (DB::table('accounting_periods')->where('company_id', $companyId)->where('starts_on', '<=', $end)->where('ends_on', '>=', $start)->exists()) {
            return null;
        }

        return (int) DB::table('accounting_periods')->insertGetId(['company_id' => $companyId, 'name' => ucfirst($month->locale('es')->translatedFormat('F Y')),
            'starts_on' => $start, 'ends_on' => $end, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * People who can approve the package: the company's assigned team, never its preparer.
     *
     * @return list<int>
     */
    private function reviewers(int $organizationId, int $companyId, int $preparerId): array
    {
        return DB::table('company_assignments')->where('organization_id', $organizationId)->where('company_id', $companyId)->where('status', 'active')->whereNull('deleted_at')
            ->where('user_id', '<>', $preparerId)->pluck('user_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
    }

    private function owner(Organization $organization): ?User
    {
        $id = DB::table('organization_user')->where('organization_id', $organization->id)->where('role', 'owner')->where('status', 'active')->orderBy('id')->value('user_id');

        return $id === null ? null : User::query()->find($id);
    }

    private function claim(Organization $organization, int $companyId, string $period, string $step): bool
    {
        return DB::table('monthly_cycle_runs')->insertOrIgnore(['organization_id' => $organization->id, 'company_id' => $companyId, 'period' => $period, 'step' => $step,
            'created_at' => now(), 'updated_at' => now()]) === 1;
    }

    private function logItems(int $companyId, string $period, string $step, int $items): void
    {
        DB::table('monthly_cycle_runs')->where('company_id', $companyId)->where('period', $period)->where('step', $step)->update(['items' => $items, 'updated_at' => now()]);
    }

    private function release(int $companyId, string $period, string $step): void
    {
        DB::table('monthly_cycle_runs')->where('company_id', $companyId)->where('period', $period)->where('step', $step)->delete();
    }

    /**
     * @param  mixed  $value  the requests the office typed
     * @return list<RecurringRequest>
     */
    private function recurring(mixed $value): array
    {
        $requests = [];
        foreach (is_array($value) ? $value : [] as $request) {
            $title = is_array($request) ? trim((string) ($request['title'] ?? '')) : '';
            if ($title === '') {
                continue;
            }
            $type = (string) ($request['type'] ?? 'document');
            $requests[] = ['title' => mb_substr($title, 0, 150), 'type' => isset(self::REQUEST_TYPES[$type]) ? $type : 'document', 'due_day' => max(1, min(28, (int) ($request['due_day'] ?? 5)))];
        }

        return array_slice($requests, 0, 10);
    }
}
