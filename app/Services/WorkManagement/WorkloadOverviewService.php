<?php

declare(strict_types=1);

namespace App\Services\WorkManagement;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Task distribution of the team for the director: who is overloaded, who has room, and what could move. Read-only. */
class WorkloadOverviewService
{
    private const OPEN = ['pending', 'in_progress', 'ready_for_review', 'in_review', 'changes_requested', 'blocked'];

    public function __construct(private WorkDashboardService $dashboard) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function payload(Organization $organization, User $user, array $filters = []): array
    {
        $executive = $this->dashboard->executiveSnapshot($organization, $user, $filters);
        $people = collect($executive['workload']);
        $officeIds = collect($executive['offices'])->pluck('id')->all();
        $now = now();

        $open = DB::table('work_tasks')->whereNull('deleted_at')->whereIn('status', self::OPEN)
            ->whereIn('office_id', $officeIds)
            ->get(['id', 'office_id', 'company_id', 'assigned_to', 'title', 'due_at', 'created_at']);
        $companies = DB::table('companies')->whereIn('id', $open->pluck('company_id')->filter()->unique()->all())->pluck('name', 'id');
        $officeNames = collect($executive['offices'])->pluck('name', 'id');
        $roleByUser = $people->pluck('role_label', 'user_id');
        $byUser = $open->groupBy('assigned_to');

        $rows = $people->map(function (array $person) use ($byUser, $now): array {
            $mine = $byUser->get($person['user_id'], collect());
            $dueToday = $mine->filter(fn (object $task): bool => $task->due_at !== null && substr((string) $task->due_at, 0, 10) === $now->toDateString())->count();
            $dueWeek = $mine->filter(fn (object $task): bool => $task->due_at !== null && $task->due_at >= $now && $task->due_at <= $now->copy()->addDays(7))->count();

            return [
                'user_id' => (int) $person['user_id'],
                'name' => $person['name'],
                'role' => $person['role_label'] ?? $person['organization_role'],
                'companies' => (int) $person['assigned_companies'],
                'pending' => $mine->count(),
                'overdue' => (int) ($person['tasks']['overdue'] ?? 0),
                'due_today' => $dueToday,
                'due_week' => $dueWeek,
                'load' => (int) ($person['utilization_percentage'] ?? 0),
            ];
        })->sortByDesc('load')->values();

        $overloaded = $rows->where('load', '>=', 90);
        $spare = $rows->where('load', '<', 60)->sortBy('load');
        $averageLoad = $rows->isEmpty() ? 0 : (int) round($rows->avg('load'));
        $overdueTasks = $open->filter(fn (object $task): bool => $task->due_at !== null && $task->due_at < $now);

        $byOffice = $open->groupBy('office_id')->map(fn ($tasks, $officeId): array => [
            'name' => $officeNames[$officeId] ?? 'Sin oficina',
            'total' => $tasks->count(),
            'overdue' => $tasks->filter(fn (object $task): bool => $task->due_at !== null && $task->due_at < $now)->count(),
        ])->sortByDesc('total')->values();

        $byRole = $open->groupBy(fn (object $task): string => (string) ($roleByUser[$task->assigned_to] ?? 'Sin asignar'))
            ->map(fn ($tasks, string $role): array => ['role' => $role, 'tasks' => $tasks->count()])
            ->sortByDesc('tasks')->values();

        $reassign = $open->filter(fn (object $task): bool => $task->due_at !== null && $task->due_at <= $now->copy()->addDays(2) && $overloaded->pluck('user_id')->contains($task->assigned_to))
            ->sortBy('due_at')->take(8)->map(fn (object $task): array => [
                'title' => $task->title,
                'company' => $companies[$task->company_id] ?? null,
                'assigned' => $rows->firstWhere('user_id', $task->assigned_to)['name'] ?? 'Sin asignar',
                'due_at' => $task->due_at,
                'overdue' => $task->due_at < $now,
            ])->values();

        return [
            'summary' => [
                'active' => $open->count(),
                'overdue' => $overdueTasks->count(),
                'overloaded' => $overloaded->count(),
                'overloaded_rate' => $rows->isEmpty() ? 0 : (int) round($overloaded->count() / $rows->count() * 100),
                'capacity' => max(0, 100 - $averageLoad),
                'average_age' => $open->isEmpty() ? 0 : (int) round($open->avg(fn (object $task): float => max(0, Carbon::parse($task->created_at)->diffInDays($now)))),
            ],
            'people' => $rows->all(),
            'roles' => $rows->pluck('role')->unique()->sort()->values()->all(),
            'by_office' => $byOffice->all(),
            'by_role' => $byRole->all(),
            'recommendations' => $this->recommendations($overloaded, $spare, $rows->sum('due_today')),
            'reassign' => $reassign->all(),
        ];
    }

    /**
     * @param  Collection<int, covariant array<string, mixed>>  $overloaded
     * @param  Collection<int, covariant array<string, mixed>>  $spare
     * @return list<array{tone: string, title: string, detail: string}>
     */
    private function recommendations($overloaded, $spare, int $dueToday): array
    {
        $items = [];

        foreach ($overloaded->take(2) as $person) {
            $items[] = ['tone' => 'red', 'title' => 'Redistribuir tareas de '.$person['name'], 'detail' => $person['name'].' tiene '.$person['load'].'% de utilización y '.$person['pending'].' tareas abiertas. Se recomienda reasignar parte de su trabajo.'];
        }

        if ($spare->isNotEmpty()) {
            $items[] = ['tone' => 'amber', 'title' => 'Aprovechar capacidad disponible', 'detail' => $spare->take(2)->pluck('name')->implode(' y ').' tienen menos del 60% de utilización y pueden recibir más tareas.'];
        }

        if ($dueToday > 0) {
            $items[] = ['tone' => 'blue', 'title' => 'Revisar fechas de vencimiento', 'detail' => $dueToday.' tarea(s) vencen hoy. Confirma que sus responsables las están trabajando.'];
        }

        if ($items === []) {
            $items[] = ['tone' => 'green', 'title' => 'Carga balanceada', 'detail' => 'No se detectan sobrecargas ni vencimientos urgentes.'];
        }

        return $items;
    }
}
