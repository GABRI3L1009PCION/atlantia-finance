<?php

declare(strict_types=1);

namespace App\Services\WorkManagement;

use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Twelve-month work plan of the firm and whether its goal is being met. For each month the plan is the work that falls
 * due in it (tasks with a due date, fiscal obligations and accounting periods that end) and the result is how much of
 * it was finished. A month meets the goal when at least GOAL_RATE of its planned work is done.
 */
class ProjectionService
{
    public const GOAL_RATE = 90;

    private const MONTHS = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

    public const VIEWS = ['all' => 'Todo el trabajo', 'tasks' => 'Tareas', 'obligations' => 'Obligaciones fiscales', 'closings' => 'Cierres de período'];

    public function __construct(private WorkDashboardService $dashboard) {}

    /** @return array<string, mixed> */
    public function payload(Organization $organization, User $user, int $year, string $view): array
    {
        $view = array_key_exists($view, self::VIEWS) ? $view : 'all';
        $executive = $this->dashboard->dashboard($organization, $user, 'executive');
        $companyIds = collect($executive['companies'])->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $officeIds = collect($executive['offices'])->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $now = CarbonImmutable::now();
        $from = CarbonImmutable::create($year, 1, 1)->startOfDay();
        $to = CarbonImmutable::create($year, 12, 31)->endOfDay();

        $tasks = DB::table('work_tasks')->whereNull('deleted_at')->whereNot('status', 'cancelled')->whereIn('office_id', $officeIds)
            ->whereBetween('due_at', [$from, $to])->get(['due_at', 'completed_at']);
        $obligations = DB::table('fiscal_obligations')->whereIn('company_id', $companyIds)->whereBetween('due_on', [$from->toDateString(), $to->toDateString()])
            ->get(['due_on', 'filed_on', 'completed_at']);
        $periods = DB::table('accounting_periods')->whereIn('company_id', $companyIds)->whereBetween('ends_on', [$from->toDateString(), $to->toDateString()])
            ->get(['ends_on', 'closed_at']);

        $months = [];

        for ($month = 1; $month <= 12; $month++) {
            $key = sprintf('%d-%02d', $year, $month);
            $slices = [
                'tasks' => [$tasks->filter(fn (object $row): bool => substr((string) $row->due_at, 0, 7) === $key), fn (object $row): bool => $row->completed_at !== null],
                'obligations' => [$obligations->filter(fn (object $row): bool => substr((string) $row->due_on, 0, 7) === $key), fn (object $row): bool => $row->filed_on !== null || $row->completed_at !== null],
                'closings' => [$periods->filter(fn (object $row): bool => substr((string) $row->ends_on, 0, 7) === $key), fn (object $row): bool => $row->closed_at !== null],
            ];
            $chosen = $view === 'all' ? $slices : [$view => $slices[$view]];
            $planned = array_sum(array_map(fn (array $slice): int => $slice[0]->count(), $chosen));
            $done = array_sum(array_map(fn (array $slice): int => $slice[0]->filter($slice[1])->count(), $chosen));
            $monthStart = CarbonImmutable::create($year, $month, 1);
            $past = $monthStart->endOfMonth()->lt($now->startOfDay());
            $current = $monthStart->format('Y-m') === $now->format('Y-m');

            $months[] = [
                'name' => self::MONTHS[$month - 1],
                'planned' => $planned,
                'done' => $done,
                'pending' => $planned - $done,
                'rate' => $planned > 0 ? (int) round($done / $planned * 100) : null,
                'phase' => $past ? 'past' : ($current ? 'current' : 'future'),
            ];
        }

        $average = collect($months)->avg('planned') ?: 0;

        foreach ($months as &$entry) {
            $entry['status'] = match (true) {
                $entry['planned'] === 0 => 'empty',
                $entry['phase'] === 'past' => $entry['rate'] >= self::GOAL_RATE ? 'met' : 'missed',
                $entry['phase'] === 'current' => $entry['rate'] >= self::GOAL_RATE ? 'met' : 'in_progress',
                $entry['planned'] >= max(10, $average * 1.5) => 'heavy',
                default => 'planned',
            };
        }
        unset($entry);

        $plannedTotal = array_sum(array_column($months, 'planned'));
        $doneTotal = array_sum(array_column($months, 'done'));
        $evaluated = array_values(array_filter($months, fn (array $entry): bool => in_array($entry['phase'], ['past', 'current'], true) && $entry['planned'] > 0));
        $evaluatedPlanned = array_sum(array_column($evaluated, 'planned'));
        $evaluatedDone = array_sum(array_column($evaluated, 'done'));
        $rate = $evaluatedPlanned > 0 ? (int) round($evaluatedDone / $evaluatedPlanned * 100) : null;
        $metMonths = count(array_filter($months, fn (array $entry): bool => $entry['status'] === 'met'));
        $missedMonths = array_values(array_map(fn (array $entry): string => $entry['name'], array_filter($months, fn (array $entry): bool => $entry['status'] === 'missed')));
        $heavyMonths = array_values(array_map(fn (array $entry): string => $entry['name'], array_filter($months, fn (array $entry): bool => $entry['status'] === 'heavy')));
        $behind = collect($months)->where('phase', '!=', 'future')->sum('pending');

        foreach ($months as $index => &$entry) {
            $entry['share'] = $plannedTotal > 0 ? (int) round($entry['planned'] / $plannedTotal * 100) : 0;
        }
        unset($entry);

        return [
            'year' => $year,
            'years' => [$now->year - 1, $now->year, $now->year + 1],
            'view' => $view,
            'views' => collect(self::VIEWS)->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label])->values()->all(),
            'goal' => self::GOAL_RATE,
            'months' => $months,
            'summary' => [
                'rate' => $rate,
                'goal_met' => $rate !== null && $rate >= self::GOAL_RATE,
                'planned' => $plannedTotal,
                'done' => $doneTotal,
                'pending_to_date' => $behind,
                'met_months' => $metMonths,
                'risk' => $missedMonths !== [] || $heavyMonths !== [] ? ($missedMonths !== [] && $behind > 0 ? 'high' : 'medium') : 'low',
                'risk_note' => $missedMonths !== [] ? 'Meta no alcanzada en '.implode(', ', array_slice($missedMonths, 0, 3)).'.' : ($heavyMonths !== [] ? 'Carga alta prevista en '.implode(', ', array_slice($heavyMonths, 0, 3)).'.' : 'Sin meses en riesgo.'),
            ],
            'findings' => array_values(array_filter([
                $rate === null ? 'Todavía no hay trabajo vencido para medir el cumplimiento de la meta.' : 'El trabajo vencido hasta hoy se cumplió en '.$rate.'% (meta '.self::GOAL_RATE.'%).',
                $metMonths > 0 ? $metMonths.' mes(es) alcanzaron la meta.' : null,
                $missedMonths !== [] ? 'No se alcanzó la meta en: '.implode(', ', $missedMonths).'.' : null,
                $heavyMonths !== [] ? 'Meses con carga planificada alta: '.implode(', ', $heavyMonths).'.' : null,
                $this->busiest($months),
            ])),
            'recommendations' => array_values(array_filter([
                $behind > 0 ? 'Poner al día las '.$behind.' pieza(s) de trabajo pendientes de meses anteriores y del mes en curso.' : null,
                $heavyMonths !== [] ? 'Reforzar el equipo o redistribuir carga antes de '.$heavyMonths[0].'.' : null,
                $missedMonths !== [] ? 'Analizar por qué no se cumplió la meta en '.$missedMonths[0].' y corregir el proceso.' : null,
                'Revisar la proyección cada mes: el trabajo planificado cambia con cada tarea, obligación y período nuevo.',
            ])),
        ];
    }

    /** @param list<array<string, mixed>> $months */
    private function busiest(array $months): ?string
    {
        /** @var Collection<int, array<string, mixed>> $sorted */
        $sorted = collect($months)->sortByDesc('planned');
        $top = $sorted->first();

        return $top !== null && $top['planned'] > 0 ? 'El mes con más trabajo planificado es '.$top['name'].' ('.$top['planned'].' piezas).' : null;
    }
}
