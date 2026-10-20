<?php

declare(strict_types=1);

namespace App\Services\WorkManagement;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Relations between tasks. REQUIRES: the task cannot be ready for review until the other one is done (it may be started
 * and partly prepared meanwhile). BLOCKS: the other direction of the same idea. RELATED_TO is informational.
 */
class TaskDependencyService
{
    public const TYPES = ['REQUIRES' => 'Requiere', 'BLOCKS' => 'Bloquea a', 'RELATED_TO' => 'Relacionada con'];

    private const FINISHED = ['completed', 'cancelled', 'not_applicable'];

    public function __construct(private AccountingService $accounting) {}

    public function add(Organization $organization, User $actor, int $taskId, int $otherId, string $type): void
    {
        if (! isset(self::TYPES[$type])) {
            throw ValidationException::withMessages(['type' => 'Tipo de relación inválido.']);
        }
        if ($taskId === $otherId) {
            throw ValidationException::withMessages(['depends_on' => 'Una tarea no puede depender de sí misma.']);
        }
        $tasks = DB::table('work_tasks')->where('work_tasks.organization_id', $organization->id)->whereNull('deleted_at')->whereIn('id', [$taskId, $otherId])->get()->keyBy('id');
        if ($tasks->count() !== 2) {
            throw ValidationException::withMessages(['depends_on' => 'La otra tarea no existe en tu oficina.']);
        }
        if ($type === 'REQUIRES' && $this->requiresTransitively($organization->id, $otherId, $taskId)) {
            throw ValidationException::withMessages(['depends_on' => 'Esa relación crearía un ciclo entre las tareas.']);
        }

        DB::transaction(function () use ($organization, $actor, $taskId, $otherId, $type, $tasks): void {
            DB::table('work_task_dependencies')->updateOrInsert(['work_task_id' => $taskId, 'depends_on_task_id' => $otherId, 'type' => $type],
                ['organization_id' => $organization->id, 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->accounting->audit($organization, $actor, 'task.dependency_added', 'work_tasks', $taskId, ['depends_on' => $otherId, 'type' => $type], (int) $tasks[$taskId]->office_id);
        });
    }

    public function remove(Organization $organization, User $actor, int $taskId, int $dependencyId): void
    {
        DB::transaction(function () use ($organization, $actor, $taskId, $dependencyId): void {
            $deleted = DB::table('work_task_dependencies')->where('work_task_dependencies.organization_id', $organization->id)->where('id', $dependencyId)->where('work_task_id', $taskId)->delete();
            abort_if($deleted === 0, 404);
            $this->accounting->audit($organization, $actor, 'task.dependency_removed', 'work_tasks', $taskId, ['dependency' => $dependencyId]);
        });
    }

    /**
     * @return list<array{id: int, type: string, type_label: string, direction: string, task_id: int, title: string, status: string, finished: bool, blocks_readiness: bool}>
     */
    public function relations(int $taskId): array
    {
        $outgoing = DB::table('work_task_dependencies as d')->join('work_tasks as t', 't.id', '=', 'd.depends_on_task_id')->where('d.work_task_id', $taskId)
            ->get(['d.id', 'd.type', 't.id as task_id', 't.title', 't.status'])->map(fn (stdClass $row): array => $this->row($row, 'outgoing'));
        $incoming = DB::table('work_task_dependencies as d')->join('work_tasks as t', 't.id', '=', 'd.work_task_id')->where('d.depends_on_task_id', $taskId)->where('d.type', 'BLOCKS')
            ->get(['d.id', 'd.type', 't.id as task_id', 't.title', 't.status'])->map(fn (stdClass $row): array => $this->row($row, 'incoming'));

        return array_values($outgoing->concat($incoming)->all());
    }

    /**
     * Titles of the tasks that still have to be finished before this one can go to review.
     *
     * @return list<string>
     */
    public function unmet(int $taskId): array
    {
        return array_values(array_map(fn (array $relation): string => $relation['title'], array_filter($this->relations($taskId), fn (array $relation): bool => $relation['blocks_readiness'])));
    }

    /** @return array{id: int, type: string, type_label: string, direction: string, task_id: int, title: string, status: string, finished: bool, blocks_readiness: bool} */
    private function row(stdClass $row, string $direction): array
    {
        $finished = in_array($row->status, self::FINISHED, true);
        // Outgoing REQUIRES (I need it) and incoming BLOCKS (it blocks me) both hold this task until the other is finished.
        $holds = ($direction === 'outgoing' && $row->type === 'REQUIRES') || ($direction === 'incoming' && $row->type === 'BLOCKS');

        return ['id' => (int) $row->id, 'type' => (string) $row->type, 'type_label' => $direction === 'incoming' ? 'Bloqueada por' : self::TYPES[$row->type], 'direction' => $direction,
            'task_id' => (int) $row->task_id, 'title' => (string) $row->title, 'status' => (string) $row->status, 'finished' => $finished, 'blocks_readiness' => $holds && ! $finished];
    }

    private function requiresTransitively(int $organizationId, int $from, int $target): bool
    {
        $seen = [];
        $queue = [$from];
        while ($queue !== []) {
            $current = array_shift($queue);
            if ($current === $target) {
                return true;
            }
            if (isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;
            foreach (DB::table('work_task_dependencies')->where('work_task_dependencies.organization_id', $organizationId)->where('work_task_id', $current)->where('type', 'REQUIRES')->pluck('depends_on_task_id') as $next) {
                $queue[] = (int) $next;
            }
        }

        return false;
    }
}
