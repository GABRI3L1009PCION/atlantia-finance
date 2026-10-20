<?php

declare(strict_types=1);

namespace App\Services\Luzma;

use App\Services\Fiscal\FiscalObligationChecklistService;
use App\Services\WorkManagement\TaskDependencyService;
use App\Services\WorkManagement\TaskSourceService;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * What Luzma knows about the tasks of the user, read from the same records the workflow uses: the state, the real
 * readiness, the waits, the dependencies and the observations. Luzma explains and prioritizes; it never moves a task,
 * approves, presents or closes anything, and it never states a state the records do not show.
 *
 * @phpstan-type ToolResult array{facts: list<string>, data: array<string, mixed>, references: list<array{label: string, href: string}>}
 */
class LuzmaTaskTools
{
    private const LABELS = [
        'pending' => 'Pendiente', 'in_progress' => 'En proceso', 'waiting_client' => 'Esperando al cliente', 'waiting_external' => 'Esperando a un tercero', 'ready_for_review' => 'Lista para revisión',
        'in_review' => 'En revisión', 'changes_requested' => 'Cambios solicitados', 'approved' => 'Aprobada', 'blocked' => 'Bloqueada',
    ];

    private const OPEN = ['pending', 'in_progress', 'waiting_client', 'waiting_external', 'ready_for_review', 'in_review', 'changes_requested', 'approved', 'blocked'];

    public function __construct(
        private TaskSourceService $sources,
        private TaskDependencyService $dependencies,
        private FiscalObligationChecklistService $checklist,
    ) {}

    /** @return ToolResult */
    public function myTasks(LuzmaContext $c): array
    {
        $tasks = $this->visible($c)->get();
        $now = now($c->timezone);
        $mine = $tasks->filter(fn (stdClass $task): bool => (int) $task->assigned_to === $c->user->id);
        $toReview = $tasks->filter(fn (stdClass $task): bool => (int) $task->reviewer_id === $c->user->id && in_array($task->status, ['ready_for_review', 'in_review'], true));
        $overdue = $mine->filter(fn (stdClass $task): bool => $this->isOverdue($task, $now));
        $facts = [$mine->count().' tarea(s) a tu cargo: '.collect(self::LABELS)->map(fn (string $label, string $status): ?string => ($n = $mine->where('status', $status)->count()) > 0 ? mb_strtolower($label).' '.$n : null)->filter()->implode(', ').'.'];
        $facts[] = $overdue->count().' vencida(s) (siguen en su estado real; el atraso se cuenta aparte) y '.$toReview->count().' para que tú revises.';
        foreach ($overdue->sortBy('due_at')->take(5) as $task) {
            $facts[] = 'Vencida hace '.$this->daysLate($task, $now).' día(s): '.$task->title.($task->company ? ' · '.$task->company : '').' ('.mb_strtolower(self::LABELS[$task->status] ?? $task->status).')';
        }

        return [
            'facts' => $mine->isEmpty() && $toReview->isEmpty() ? ['No tienes tareas abiertas.'] : $facts,
            'data' => ['mine' => $mine->count(), 'overdue' => $overdue->count(), 'to_review' => $toReview->count(), 'by_status' => $mine->groupBy('status')->map->count()->all()],
            'references' => array_values($mine->take(3)->map(fn (stdClass $task): array => ['label' => 'Ver tarea: '.mb_strimwidth((string) $task->title, 0, 50, '…'), 'href' => '/essential/tasks/'.$task->id])->all()),
        ];
    }

    /**
     * What is missing for the task to go to review, from the real readiness (never "ready" when it cannot be validated).
     *
     * @param  array<string, mixed>  $arguments
     * @return ToolResult
     */
    public function readiness(LuzmaContext $c, array $arguments): array
    {
        $task = $this->find($c, (string) ($arguments['task'] ?? ''));
        if ($task === null) {
            return ['facts' => ['No encontré esa tarea entre las tuyas. Dime su nombre o abre la tarea y pregúntame desde ahí.'], 'data' => [], 'references' => []];
        }
        [$state, $pending, $blockers, $error, $ready] = $this->picture($task);
        $facts = ['«'.$task->title.'»: '.mb_strtolower(self::LABELS[$task->status] ?? $task->status).($state === null ? '' : ', avance '.$state.'%').'.'];
        if ($error !== null) {
            $facts[] = 'No fue posible validar la preparación. No asumas que está lista.';
        } elseif ($ready) {
            $facts[] = 'Está lista para enviarse a revisión: todos los requisitos obligatorios están cumplidos y no hay bloqueos.';
        } else {
            $facts[] = 'Faltan '.count($pending).' requisito(s): '.implode('; ', array_slice($pending, 0, 8)).'.';
        }
        foreach (array_slice($blockers, 0, 4) as $blocker) {
            $facts[] = 'Bloquea: '.$blocker;
        }

        return ['facts' => $facts, 'data' => ['task' => (int) $task->id, 'progress' => $state, 'ready' => $ready, 'pending' => $pending, 'blockers' => $blockers, 'error' => $error], 'references' => [['label' => 'Abrir la tarea', 'href' => '/essential/tasks/'.$task->id]]];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return ToolResult
     */
    public function blockers(LuzmaContext $c, array $arguments): array
    {
        $text = trim((string) ($arguments['task'] ?? ''));
        $tasks = $text === '' ? $this->visible($c)->whereIn('status', ['blocked', 'waiting_client', 'waiting_external'])->get() : collect(array_filter([$this->find($c, $text)]));
        $facts = [];
        foreach ($tasks->take(8) as $task) {
            [, , $blockers] = $this->picture($task);
            $waits = DB::table('work_task_waits')->where('work_task_id', $task->id)->where('status', 'open')->get(['kind', 'reason', 'expected_on']);
            $line = '«'.$task->title.'» ('.mb_strtolower(self::LABELS[$task->status] ?? $task->status).')';
            foreach ($waits as $wait) {
                $line .= ' · '.($wait->kind === 'client' ? 'espera al cliente' : 'espera a un tercero').': '.$wait->reason.($wait->expected_on ? ' (esperado '.$wait->expected_on.')' : '');
            }
            if ($task->blocked_reason) {
                $line .= ' · bloqueo: '.$task->blocked_reason;
            }
            foreach ($blockers as $blocker) {
                $line .= ' · '.$blocker;
            }
            $facts[] = $line;
        }

        return ['facts' => $facts === [] ? ['No hay tareas bloqueadas ni en espera.'] : $facts, 'data' => ['tasks' => $tasks->count()], 'references' => []];
    }

    /** @return ToolResult */
    public function observations(LuzmaContext $c): array
    {
        $rows = DB::table('review_observations')->leftJoin('users as author', 'author.id', '=', 'review_observations.created_by')->leftJoin('companies', 'companies.id', '=', 'review_observations.company_id')
            ->where('review_observations.organization_id', $c->organization->id)->where('review_observations.assigned_to', $c->user->id)->whereIn('review_observations.status', ['open', 'reopened', 'in_progress'])
            ->orderBy('review_observations.id')->limit(15)->get(['review_observations.id', 'review_observations.message', 'review_observations.related_label', 'review_observations.origin_type', 'review_observations.origin_id', 'author.name as author', 'companies.name as company']);
        $facts = [$rows->count().' observación(es) de revisión por corregir.'];
        foreach ($rows->take(6) as $row) {
            $facts[] = 'OBS-'.str_pad((string) $row->id, 4, '0', STR_PAD_LEFT).' · '.$row->company.': '.$row->message.($row->related_label ? ' (registro: '.$row->related_label.')' : '').' — '.$row->author;
        }

        return ['facts' => $facts, 'data' => ['open' => $rows->count()], 'references' => array_values($rows->filter(fn (stdClass $row): bool => $row->origin_type === 'fiscal_obligations')->take(3)->map(fn (stdClass $row): array => ['label' => 'Corregir en la obligación', 'href' => '/essential/obligations/'.$row->origin_id])->all())];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return ToolResult
     */
    public function dueTasks(LuzmaContext $c, array $arguments): array
    {
        $days = max(1, min(60, (int) ($arguments['days'] ?? 7)));
        $now = now($c->timezone);
        $tasks = $this->visible($c)->where('due_at', '<=', $now->copy()->addDays($days)->endOfDay())->orderBy('due_at')->limit(20)->get()
            ->filter(fn (stdClass $task): bool => (int) $task->assigned_to === $c->user->id);
        $facts = [$tasks->count().' tarea(s) vencidas o que vencen en los próximos '.$days.' día(s).'];
        foreach ($tasks->take(8) as $task) {
            $late = $this->isOverdue($task, $now);
            $facts[] = ($late ? 'Vencida hace '.$this->daysLate($task, $now).' día(s)' : 'Vence '.Carbon::parse((string) $task->due_at)->format('d/m/Y')).': '.$task->title.($task->company ? ' · '.$task->company : '').' ('.mb_strtolower(self::LABELS[$task->status] ?? $task->status).')';
        }

        return ['facts' => $facts, 'data' => ['count' => $tasks->count(), 'days' => $days], 'references' => []];
    }

    /** @return array{0: int|null, 1: list<string>, 2: list<string>, 3: string|null, 4: bool} progress, pending, blockers, error, ready */
    private function picture(stdClass $task): array
    {
        if ($task->source_type === 'fiscal_obligations') {
            $obligation = DB::table('fiscal_obligations')->where('id', $task->source_id)->first();
            if ($obligation === null) {
                return [null, [], [], 'El origen de la tarea ya no existe.', false];
            }
            $summary = $this->checklist->summary($obligation);

            return [$summary['progress'], $summary['pending'], array_map(fn (array $blocker): string => $blocker['label'], $summary['blockers']), $summary['error'], $summary['ready']];
        }
        $state = $this->sources->state($task);
        if ($state !== null) {
            return [$state['progress'], $state['pending'], $state['blockers'], $state['error'], $state['ready']];
        }
        $unmet = $this->dependencies->unmet((int) $task->id);
        $notes = DB::table('task_comments')->where('work_task_id', $task->id)->where('type', 'comment')->count();
        $pending = [...($notes > 0 ? [] : ['Registrar al menos una nota de avance']), ...array_map(fn (string $title): string => 'Terminar antes: '.$title, $unmet)];

        return [null, $pending, [], null, $pending === []];
    }

    /** @return Builder */
    private function visible(LuzmaContext $c)
    {
        return DB::table('work_tasks')->leftJoin('companies', 'companies.id', '=', 'work_tasks.company_id')->where('work_tasks.organization_id', $c->organization->id)->whereNull('work_tasks.deleted_at')
            ->whereIn('work_tasks.status', self::OPEN)
            ->when(! $c->can('tasks.view_all'), fn ($query) => $query->where(fn ($mine) => $mine->where('work_tasks.assigned_to', $c->user->id)->orWhere('work_tasks.reviewer_id', $c->user->id)))
            ->select('work_tasks.*', 'companies.name as company');
    }

    private function find(LuzmaContext $c, string $text): ?stdClass
    {
        $text = trim($text);
        $query = $this->visible($c);
        if ($text === '') {
            return (clone $query)->where('work_tasks.assigned_to', $c->user->id)->orderBy('work_tasks.due_at')->first();
        }
        if (ctype_digit($text)) {
            return (clone $query)->where('work_tasks.id', (int) $text)->first();
        }

        return (clone $query)->where('work_tasks.title', 'ilike', '%'.str_replace(['%', '_'], ['\%', '\_'], $text).'%')->orderBy('work_tasks.due_at')->first();
    }

    private function isOverdue(stdClass $task, CarbonInterface $now): bool
    {
        return $task->due_at !== null && Carbon::parse((string) $task->due_at)->lt($now) && ! in_array($task->status, ['completed', 'approved', 'cancelled'], true);
    }

    private function daysLate(stdClass $task, CarbonInterface $now): int
    {
        return (int) Carbon::parse((string) $task->due_at)->startOfDay()->diffInDays($now->copy()->startOfDay());
    }
}
