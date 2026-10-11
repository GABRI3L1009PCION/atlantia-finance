<?php

declare(strict_types=1);

namespace App\Services\WorkManagement;

use App\Services\Accounting\AccountingPeriodCloseChecklistService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Single source of truth for where each client company stands in an accounting period. The executive, office
 * administration and operations panels all read it, so they never disagree.
 *
 * Status: not_started, in_progress, ready_for_review, in_review, changes_requested, fiscal_pending, approved (fiscal approved,
 * close checklist pending), ready_to_close, closed.
 * Flags: overdue (deadline passed with work incomplete), blocked (blocked task or blocking incident), critical.
 * Progress: approved/completed tasks over non-cancelled tasks, split by stage; stages without tasks do not apply.
 *
 * @phpstan-type Stage array{key: string, label: string, total: int, done: int, progress: int}
 * @phpstan-type CompanyStatus array{company_id: int, period_id: int|null, period_name: string|null, starts_on: string|null, ends_on: string|null, due_at: string|null, status: string, review_status: string|null, fiscal_status: string|null, review_id: int|null, progress: int, stages: list<Stage>, open_tasks: int, overdue_tasks: int, blocked_tasks: int, open_incidents: int, overdue: bool, blocked: bool, critical: bool}
 */
class CompanyPeriodStatusService
{
    /** Days after the period ends before a company counts as late (monthly returns are due the following month). */
    public const DEADLINE_DAYS = 20;

    public const STAGES = [
        'documents' => 'Documentos',
        'sales' => 'Ventas y CxC',
        'receivables' => 'Ventas y CxC',
        'purchases' => 'Compras y CxP',
        'payables' => 'Compras y CxP',
        'banking' => 'Bancos',
        'tax' => 'Fiscal',
        'payroll' => 'Nómina',
        'accounting' => 'Contabilidad',
        'operations' => 'Operación',
        'closing' => 'Cierre',
    ];

    public function __construct(private AccountingPeriodCloseChecklistService $checklist) {}

    public static function deadlineFor(string $endsOn): Carbon
    {
        return Carbon::parse($endsOn)->addDays(self::DEADLINE_DAYS)->endOfDay();
    }

    /**
     * @param  list<int>  $companyIds
     * @param  array{name: string, starts_on: string, ends_on: string}|null  $period  null = each company's latest open period
     * @return array<int, CompanyStatus>
     */
    public function forCompanies(int $organizationId, array $companyIds, ?array $period, bool $checkCloseReadiness = true): array
    {
        if ($companyIds === []) {
            return [];
        }

        $periods = $this->periods($companyIds, $period);
        $periodIds = $periods->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $tasks = DB::table('work_tasks')
            ->where('work_tasks.organization_id', $organizationId)
            ->whereIn('company_id', $companyIds)
            ->whereNull('deleted_at')
            ->where('status', '!=', 'cancelled')
            ->where(fn ($query) => $query->whereIn('accounting_period_id', $periodIds === [] ? [-1] : $periodIds)->orWhereNull('accounting_period_id'))
            ->get(['company_id', 'accounting_period_id', 'module', 'status', 'due_at'])
            ->groupBy('company_id');
        $reviews = DB::table('company_period_reviews')
            ->where('company_period_reviews.organization_id', $organizationId)
            ->whereIn('accounting_period_id', $periodIds === [] ? [-1] : $periodIds)
            ->whereNull('deleted_at')
            ->get(['id', 'company_id', 'accounting_period_id', 'status', 'fiscal_status'])
            ->keyBy('accounting_period_id');
        $workStatuses = DB::table('company_period_work_statuses')
            ->whereIn('accounting_period_id', $periodIds === [] ? [-1] : $periodIds)
            ->get(['accounting_period_id', 'status', 'progress'])
            ->keyBy('accounting_period_id');
        $incidents = DB::table('operational_incidents')
            ->where('operational_incidents.organization_id', $organizationId)
            ->whereIn('company_id', $companyIds)
            ->whereIn('status', ['open', 'in_progress', 'blocked'])
            ->get(['company_id', 'severity', 'blocks_period'])
            ->groupBy('company_id');

        $result = [];
        foreach ($companyIds as $companyId) {
            $periodRow = $periods->firstWhere('company_id', $companyId);
            $periodId = $periodRow === null ? null : (int) $periodRow->id;
            $companyTasks = collect($tasks->get($companyId, collect()))
                ->filter(fn (stdClass $task): bool => $task->accounting_period_id === null ? $periodId === null : (int) $task->accounting_period_id === $periodId)
                ->values();
            $review = $periodId === null ? null : $reviews->get($periodId);
            $work = $periodId === null ? null : $workStatuses->get($periodId);
            $companyIncidents = collect($incidents->get($companyId, collect()));
            $open = $companyTasks->whereNotIn('status', ['approved', 'completed']);
            $overdueTasks = $open->filter(fn (stdClass $task): bool => $task->due_at !== null && Carbon::parse($task->due_at)->isPast())->count();
            $blockedTasks = $open->where('status', 'blocked')->count();
            $stages = $this->stages($companyTasks);
            $total = $companyTasks->count();
            $done = $companyTasks->whereIn('status', ['approved', 'completed'])->count();
            $isClosed = $periodRow !== null && ($periodRow->closed_at !== null || $periodRow->status === 'closed');
            $reviewStatus = $review === null ? null : (string) $review->status;
            $fiscalStatus = $review === null || $review->fiscal_status === null ? null : (string) $review->fiscal_status;
            $workStatus = $work === null ? null : (string) $work->status;
            $blocked = $blockedTasks > 0 || $companyIncidents->contains(fn (stdClass $incident): bool => (bool) $incident->blocks_period || $incident->severity === 'critical');

            $status = match (true) {
                $isClosed => 'closed',
                $reviewStatus === 'approved' && $fiscalStatus !== 'approved' => 'fiscal_pending',
                $reviewStatus === 'approved' => $checkCloseReadiness && ! $blocked && ! $this->checklist->hasBlockingChecks($companyId, $periodId) ? 'ready_to_close' : 'approved',
                in_array($reviewStatus, ['ready_for_review', 'in_review', 'changes_requested', 'first_level_approved'], true) => (string) $reviewStatus,
                in_array($workStatus, ['ready_for_review', 'in_review', 'changes_requested', 'approved', 'ready_to_close'], true) => (string) $workStatus,
                $periodRow === null || ($total === 0 && $workStatus === null) => 'not_started',
                default => 'in_progress',
            };
            $progress = $isClosed ? 100 : ($total > 0 ? (int) round(($done / $total) * 100) : (int) ($work->progress ?? 0));
            $dueAt = $periodRow === null ? null : self::deadlineFor((string) $periodRow->ends_on);

            $result[$companyId] = [
                'company_id' => $companyId,
                'period_id' => $periodId,
                'period_name' => $periodRow === null ? null : (string) $periodRow->name,
                'starts_on' => $periodRow === null ? null : (string) $periodRow->starts_on,
                'ends_on' => $periodRow === null ? null : (string) $periodRow->ends_on,
                'due_at' => $dueAt?->toIso8601String(),
                'status' => $status,
                'review_status' => $reviewStatus,
                'fiscal_status' => $fiscalStatus,
                'review_id' => $review === null ? null : (int) $review->id,
                'progress' => $progress,
                'stages' => $stages,
                'open_tasks' => $open->count(),
                'overdue_tasks' => $overdueTasks,
                'blocked_tasks' => $blockedTasks,
                'open_incidents' => $companyIncidents->count(),
                'overdue' => ! $isClosed && $dueAt !== null && $dueAt->isPast(),
                'blocked' => ! $isClosed && $blocked,
                'critical' => ! $isClosed && $companyIncidents->contains('severity', 'critical'),
            ];
        }

        return $result;
    }

    /**
     * @param  Collection<int, stdClass>  $tasks
     * @return list<Stage>
     */
    private function stages(Collection $tasks): array
    {
        $stages = [];
        foreach ($tasks as $task) {
            $label = self::STAGES[$task->module] ?? 'Operación';
            $stages[$label] ??= ['key' => (string) $task->module, 'label' => $label, 'total' => 0, 'done' => 0, 'progress' => 0];
            $stages[$label]['total']++;
            if (in_array($task->status, ['approved', 'completed'], true)) {
                $stages[$label]['done']++;
            }
        }

        return array_values(array_map(fn (array $stage): array => [...$stage, 'progress' => (int) round(($stage['done'] / max(1, $stage['total'])) * 100)], $stages));
    }

    /**
     * @param  list<int>  $companyIds
     * @param  array{name: string, starts_on: string, ends_on: string}|null  $period
     * @return Collection<int, stdClass>
     */
    private function periods(array $companyIds, ?array $period): Collection
    {
        if ($period !== null) {
            return DB::table('accounting_periods')
                ->whereIn('company_id', $companyIds)
                ->where('name', $period['name'])
                ->where('starts_on', $period['starts_on'])
                ->where('ends_on', $period['ends_on'])
                ->get(['id', 'company_id', 'name', 'starts_on', 'ends_on', 'closed_at', 'status']);
        }

        return DB::table('accounting_periods')
            ->whereIn('company_id', $companyIds)
            ->whereNull('closed_at')
            ->orderByDesc('starts_on')
            ->get(['id', 'company_id', 'name', 'starts_on', 'ends_on', 'closed_at', 'status'])
            ->unique('company_id')
            ->values();
    }
}
