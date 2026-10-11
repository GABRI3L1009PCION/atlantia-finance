<?php

declare(strict_types=1);

namespace App\Services\WorkManagement;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Delivery performance of the team for the director, from completed tasks: volume, punctuality, cycle time and where help is needed. */
class ProductivityOverviewService
{
    private const WEEKS = 8;

    public function __construct(private WorkDashboardService $dashboard) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function payload(Organization $organization, User $user, array $filters = []): array
    {
        $executive = $this->dashboard->dashboard($organization, $user, 'executive', $filters);
        $officeNames = collect($executive['offices'])->pluck('name', 'id');
        $people = collect($executive['workload'])->keyBy('user_id');
        $since = now()->subWeeks(self::WEEKS)->startOfWeek();

        $tasks = DB::table('work_tasks')->whereNull('deleted_at')->whereIn('office_id', $officeNames->keys()->all())
            ->where(fn ($query) => $query->whereNotNull('completed_at')->orWhereIn('status', ['pending', 'in_progress', 'ready_for_review', 'in_review', 'changes_requested', 'blocked']))
            ->get(['assigned_to', 'office_id', 'module', 'status', 'due_at', 'created_at', 'completed_at']);

        $done = $tasks->filter(fn (object $task): bool => $task->completed_at !== null && Carbon::parse($task->completed_at)->gte($since));
        $open = $tasks->filter(fn (object $task): bool => $task->completed_at === null);
        $onTime = fn (Collection $set): int => $set->isEmpty() ? 0 : (int) round($set->filter(fn (object $task): bool => $task->due_at === null || $task->completed_at <= $task->due_at)->count() / $set->count() * 100);
        $cycle = fn (Collection $set): float => $set->isEmpty() ? 0.0 : round((float) $set->avg(fn (object $task): float => max(0, Carbon::parse($task->created_at)->diffInHours(Carbon::parse($task->completed_at)) / 24)), 1);

        $weeks = collect(range(self::WEEKS - 1, 0))->map(function (int $back) use ($done, $onTime): array {
            $start = now()->subWeeks($back)->startOfWeek();
            $slice = $done->filter(fn (object $task): bool => Carbon::parse($task->completed_at)->between($start, $start->copy()->endOfWeek()));

            return ['label' => $start->format('d/m'), 'completed' => $slice->count(), 'on_time' => $onTime($slice)];
        })->values();

        $perPerson = $done->groupBy('assigned_to')->map(function (Collection $set, $userId) use ($people, $open, $onTime, $cycle): array {
            $person = $people->get((int) $userId);

            return [
                'user_id' => (int) $userId,
                'name' => $person['name'] ?? 'Sin asignar',
                'role' => $person['role_label'] ?? '—',
                'completed' => $set->count(),
                'on_time' => $onTime($set),
                'cycle_days' => $cycle($set),
                'open' => $open->where('assigned_to', $userId)->count(),
            ];
        })->sortByDesc('completed')->values();

        $support = $people->map(function (array $person) use ($open, $done, $onTime): array {
            $mine = $done->where('assigned_to', $person['user_id']);

            return [
                'name' => $person['name'],
                'role' => $person['role_label'] ?? '—',
                'open' => $open->where('assigned_to', $person['user_id'])->count(),
                'overdue' => (int) ($person['tasks']['overdue'] ?? 0),
                'on_time' => $mine->isEmpty() ? null : $onTime($mine),
            ];
        })->filter(fn (array $row): bool => $row['open'] > 0)->sortByDesc(fn (array $row): int => $row['overdue'] * 100 + $row['open'])->take(6)->values();

        $reviewed = $tasks->whereIn('status', ['approved', 'completed', 'changes_requested'])->count();
        $returned = $tasks->where('status', 'changes_requested')->count();

        return [
            'summary' => [
                'completed' => $done->count(),
                'cycle_days' => $cycle($done),
                'on_time' => $onTime($done),
                'open' => $open->count(),
                'returned_rate' => $reviewed >= 3 ? (int) round($returned / $reviewed * 100) : 0,
                'weeks' => self::WEEKS,
            ],
            'weeks' => $weeks->all(),
            'by_person' => $perPerson->take(6)->all(),
            'by_office' => $done->groupBy('office_id')->map(fn (Collection $set, $officeId): array => ['name' => $officeNames[$officeId] ?? 'Sin oficina', 'value' => $set->count()])->sortByDesc('value')->values()->all(),
            'by_role' => $done->groupBy(fn (object $task): string => (string) ($people->get((int) $task->assigned_to)['role_label'] ?? 'Sin asignar'))->map(fn (Collection $set, string $role): array => ['name' => $role, 'value' => $set->count()])->sortByDesc('value')->values()->all(),
            'by_module' => $done->groupBy('module')->map(fn (Collection $set, $module): array => ['name' => ucfirst(str_replace('_', ' ', (string) $module)), 'value' => $set->count(), 'on_time' => $onTime($set)])->sortByDesc('value')->values()->all(),
            'top' => $perPerson->take(5)->all(),
            'support' => $support->all(),
            'recommendations' => $this->recommendations($perPerson, $support, $onTime($done), $done->count()),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $perPerson
     * @param  Collection<int, array<string, mixed>>  $support
     * @return list<array{tone: string, title: string, detail: string}>
     */
    private function recommendations(Collection $perPerson, Collection $support, int $onTime, int $completed): array
    {
        $items = [];
        $needs = $support->filter(fn (array $row): bool => $row['overdue'] > 0)->take(2);

        if ($needs->isNotEmpty()) {
            $items[] = ['tone' => 'red', 'title' => 'Apoyar a quienes tienen atrasos', 'detail' => $needs->pluck('name')->implode(' y ').' acumulan tareas vencidas. Considera redistribuir carga o brindar apoyo.'];
        }

        if ($completed > 0 && $onTime < 80) {
            $items[] = ['tone' => 'amber', 'title' => 'Mejorar la puntualidad', 'detail' => 'Solo '.$onTime.'% de las tareas se terminó a tiempo. Revisa fechas límite y prioridades con el equipo.'];
        }

        $best = $perPerson->filter(fn (array $row): bool => $row['on_time'] >= 90)->take(2);

        if ($best->isNotEmpty()) {
            $items[] = ['tone' => 'green', 'title' => 'Potenciar mejores prácticas', 'detail' => $best->pluck('name')->implode(' y ').' entregan a tiempo de forma consistente. Documenta cómo trabajan para replicarlo.'];
        }

        if ($items === []) {
            $items[] = ['tone' => 'blue', 'title' => 'Aún sin suficiente historial', 'detail' => 'Cuando el equipo termine más tareas aparecerán recomendaciones basadas en su desempeño.'];
        }

        return $items;
    }
}
