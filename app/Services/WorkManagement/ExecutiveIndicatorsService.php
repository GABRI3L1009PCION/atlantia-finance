<?php

declare(strict_types=1);

namespace App\Services\WorkManagement;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Operational indicators of the firm for the director: compliance, workload, growth and productivity. Counts and rates only. */
class ExecutiveIndicatorsService
{
    private const MONTHS = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

    private const TASK_COLUMNS = ['pending' => 'Pendientes', 'progress' => 'En proceso', 'review' => 'En revisión', 'overdue' => 'Vencidas', 'done' => 'Completadas'];

    public function __construct(private WorkDashboardService $dashboard) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function payload(Organization $organization, User $user, array $filters = []): array
    {
        $executive = $this->dashboard->dashboard($organization, $user, 'executive', $filters);
        $offices = collect($executive['offices']);
        $companies = collect($executive['companies']);
        $workload = collect($executive['workload']);
        $productivity = collect($executive['productivity'] ?? [])->filter(fn (array $row): bool => $row['completed'] > 0);
        $months = collect(range(5, 0))->map(fn (int $back) => now()->startOfMonth()->subMonths($back));
        $officeIds = $offices->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $createdBefore = fn ($moment): int => (int) DB::table('companies')
            ->whereIn('office_id', $officeIds)->where('created_at', '<=', $moment)->count();
        $totalCompanies = $createdBefore(now());
        $quarterAgo = $createdBefore(now()->subMonths(3));
        $growth = $quarterAgo > 0 ? (int) round(($totalCompanies - $quarterAgo) / $quarterAgo * 100) : 0;

        $loads = $workload->pluck('utilization_percentage')->filter(fn ($value): bool => $value !== null);
        $closedThisPeriod = (int) ($executive['summary']['completed_closings'] ?? 0);
        $activeCompanies = (int) ($executive['summary']['active_companies'] ?? 0);

        return [
            'kpis' => [
                'compliance' => $offices->isEmpty() ? 0 : (int) round($offices->avg('progress')),
                'closing_rate' => $activeCompanies > 0 ? (int) round($closedThisPeriod / $activeCompanies * 100) : 0,
                'companies' => $totalCompanies,
                'average_load' => $loads->isEmpty() ? 0 : (int) round($loads->avg()),
                'on_time' => $productivity->isEmpty() ? null : (int) round($productivity->avg('on_time_rate')),
                'overdue_tasks' => (int) ($executive['summary']['overdue_tasks'] ?? 0),
                'quarter_growth' => $growth,
                'quarter_new' => $totalCompanies - $quarterAgo,
            ],
            'months' => $months->map(fn ($month): string => self::MONTHS[$month->month - 1])->values()->all(),
            'firm_trend' => $executive['firm']['trend'] ?? [],
            'office_trend' => $offices->map(fn (array $office): array => [
                'name' => $office['name'],
                'values' => $months->map(fn ($month): int => $this->closedShare((int) $office['id'], $month))->values()->all(),
            ])->values()->all(),
            'office_mix' => $offices->map(fn (array $office): array => ['name' => $office['name'], 'value' => (int) $office['companies']])->values()->all(),
            'task_columns' => array_values(self::TASK_COLUMNS),
            'task_heatmap' => $this->taskHeatmap($offices->all()),
            'office_table' => $offices->map(fn (array $office): array => [
                'name' => $office['name'],
                'companies' => (int) $office['companies'],
                'closed' => (int) ($office['completed_companies'] ?? 0),
                'progress' => (int) $office['progress'],
                'overdue' => (int) $office['overdue'],
                'load' => $this->officeLoad((int) $office['id'], $workload->all()),
                'users' => (int) ($office['users'] ?? 0),
            ])->values()->all(),
            'team' => $productivity->sortByDesc('on_time_rate')->take(6)->map(fn (array $row): array => [
                'name' => $row['name'],
                'completed' => (int) $row['completed'],
                'on_time_rate' => (int) $row['on_time_rate'],
            ])->values()->all(),
            'insights' => $this->insights($offices->all(), $companies->all(), $workload->all(), $growth, $totalCompanies - $quarterAgo),
        ];
    }

    private function closedShare(int $officeId, $month): int
    {
        $total = (int) DB::table('companies')->where('office_id', $officeId)->count();

        if ($total === 0) {
            return 0;
        }

        $closed = (int) DB::table('accounting_periods')
            ->join('companies', 'companies.id', '=', 'accounting_periods.company_id')
            ->where('companies.office_id', $officeId)
            ->whereNotNull('accounting_periods.closed_at')
            ->whereBetween('accounting_periods.starts_on', [$month->toDateString(), $month->copy()->endOfMonth()->toDateString()])
            ->distinct()->count('accounting_periods.company_id');

        return (int) round($closed / $total * 100);
    }

    /**
     * @param  list<array<string, mixed>>  $offices
     * @return list<array{name: string, values: list<int>}>
     */
    private function taskHeatmap(array $offices): array
    {
        $now = now();
        $rows = DB::table('work_tasks')->whereNull('deleted_at')->whereIn('office_id', array_column($offices, 'id'))
            ->get(['office_id', 'status', 'due_at']);

        return array_map(function (array $office) use ($rows, $now): array {
            $tasks = $rows->where('office_id', $office['id']);
            $done = $tasks->whereIn('status', ['completed', 'approved']);
            $open = $tasks->whereNotIn('status', ['completed', 'approved', 'cancelled']);
            $overdue = $open->filter(fn (object $task): bool => $task->due_at !== null && $task->due_at < $now);

            return ['name' => $office['name'], 'values' => [
                $open->where('status', 'pending')->count(),
                $open->where('status', 'in_progress')->count(),
                $open->whereIn('status', ['ready_for_review', 'in_review', 'changes_requested'])->count(),
                $overdue->count(),
                $done->count(),
            ]];
        }, $offices);
    }

    /** @param  list<array<string, mixed>>  $workload */
    private function officeLoad(int $officeId, array $workload): int
    {
        $members = DB::table('office_user')->where('office_id', $officeId)->where('status', 'active')->pluck('user_id')->all();
        $loads = collect($workload)->whereIn('user_id', $members)->pluck('utilization_percentage')->filter(fn ($value): bool => $value !== null);

        return $loads->isEmpty() ? 0 : (int) round($loads->avg());
    }

    /**
     * Findings written from the numbers above; nothing is invented.
     *
     * @param  list<array<string, mixed>>  $offices
     * @param  list<array<string, mixed>>  $companies
     * @param  list<array<string, mixed>>  $workload
     * @return list<array{tone: string, title: string, detail: string}>
     */
    private function insights(array $offices, array $companies, array $workload, int $growth, int $newCompanies): array
    {
        $insights = [];
        $overloaded = collect($workload)->filter(fn (array $row): bool => ($row['utilization_percentage'] ?? 0) >= 90);
        $late = collect($offices)->filter(fn (array $office): bool => $office['overdue'] > 0);
        $noAccountant = collect($companies)->filter(fn (array $company): bool => $company['accountant'] === null)->count();

        if ($late->isNotEmpty()) {
            $insights[] = ['tone' => 'red', 'title' => 'Atención en cumplimiento', 'detail' => $late->count().' oficina(s) tienen empresas atrasadas: '.$late->pluck('name')->take(3)->implode(', ').'. Conviene priorizar su seguimiento.'];
        }

        if ($overloaded->isNotEmpty()) {
            $insights[] = ['tone' => 'amber', 'title' => 'Oportunidad en carga de trabajo', 'detail' => $overloaded->count().' persona(s) superan el 90% de carga ('.$overloaded->pluck('name')->take(3)->implode(', ').'). Se recomienda redistribuir asignaciones.'];
        }

        if ($noAccountant > 0) {
            $insights[] = ['tone' => 'amber', 'title' => 'Empresas sin contador encargado', 'detail' => $noAccountant.' empresa(s) no tienen responsable contable asignado. Asígnalas para evitar atrasos.'];
        }

        if ($newCompanies > 0) {
            $insights[] = ['tone' => 'green', 'title' => 'Crecimiento de la cartera', 'detail' => 'La firma sumó '.$newCompanies.' empresa(s) en el último trimestre ('.($growth >= 0 ? '+' : '').$growth.'%).'];
        }

        if ($insights === []) {
            $insights[] = ['tone' => 'green', 'title' => 'Operación estable', 'detail' => 'No se detectan alertas relevantes en el período seleccionado.'];
        }

        return array_slice($insights, 0, 4);
    }
}
