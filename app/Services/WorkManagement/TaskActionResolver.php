<?php

declare(strict_types=1);

namespace App\Services\WorkManagement;

use stdClass;

/**
 * Decides the one main button of a task from facts the backend knows: the workflow state, who is looking, what they
 * may do, and whether the work is really ready. The screen only draws what this returns; it never infers actions from
 * the state. "Start" only starts: it never leads to "send to review", which appears once the real work is complete.
 *
 * @phpstan-type PrimaryAction array{key: string, label: string, destination: string|null, enabled: bool, disabled_reason: string|null, method: string}
 */
class TaskActionResolver
{
    /**
     * A task whose work is a fiscal obligation: the obligation is where the work, its review and its filing happen.
     *
     * @param  array{start: bool, submit: bool, approve: bool, changes: bool, complete: bool, recalculate: bool}  $can
     * @param  array{ready: bool, pending: list<string>, error: string|null}|null  $readiness  null when the obligation is not being prepared
     * @return PrimaryAction
     */
    public function forObligation(?stdClass $obligation, array $can, ?array $readiness, bool $mayFinishOwn, string $workspace, bool $isPreparer): array
    {
        if ($obligation === null) {
            return $this->action('unavailable', 'No disponible', null, false, 'El origen de la tarea ya no existe o no tienes acceso.');
        }

        return match ($obligation->status) {
            'pending' => $can['start']
                ? $this->action('start', 'Iniciar', null, true, null, 'POST')
                : $this->action('view', 'Ver', $workspace, true, null),
            'changes_requested' => $isPreparer ? $this->action('correct', 'Corregir', $workspace, true, null) : $this->action('view', 'Ver', $workspace, true, null),
            'in_progress' => $this->inProgress($can, $readiness, $mayFinishOwn, $workspace, $isPreparer),
            'ready_for_review' => $can['approve'] || $can['changes']
                ? $this->action('review', 'Revisar', $workspace, true, null)
                : $this->action('view_review', 'Ver revisión', $workspace, true, null),
            'approved' => $can['complete']
                ? $this->action('present', 'Registrar presentación', $workspace, true, null)
                : $this->action('view', 'Ver', $workspace, true, null),
            'completed' => $this->action('view_result', 'Ver resultado', $workspace, true, null),
            default => $this->action('view', 'Ver', $workspace, true, null),
        };
    }

    /**
     * A task whose work lives in another module (collections, payables, payroll, bank, closing, client requests): Continue opens
     * that module, and the task closes when the module shows the result.
     *
     * @param  array{destination: string, error: string|null}|null  $state
     * @return PrimaryAction
     */
    public function forSource(stdClass $task, ?array $state, bool $isAssignee, string $detail): array
    {
        if ($state === null || $state['error'] !== null) {
            return $this->action('unavailable', 'No disponible', $detail, false, $state['error'] ?? 'El origen de la tarea ya no existe o no tienes acceso.');
        }

        return match ($task->status) {
            'pending' => $isAssignee ? $this->action('start', 'Iniciar', null, true, null, 'POST') : $this->action('view', 'Ver', $state['destination'], true, null),
            'in_progress', 'approved' => $this->action('continue', 'Continuar', $state['destination'], true, null),
            'changes_requested' => $this->action('correct', 'Corregir', $state['destination'], true, null),
            'ready_for_review', 'in_review' => $this->action('view_review', 'Ver revisión', $state['destination'], true, null),
            'waiting_client', 'waiting_external' => $this->action('view_wait', 'Ver espera', $detail, true, null),
            'blocked' => $this->action('view_block', 'Ver bloqueo', $detail, true, null),
            'completed' => $this->action('view_result', 'Ver resultado', $detail, true, null),
            default => $this->action('view', 'Ver', $detail, true, null),
        };
    }

    /**
     * A task with no business object behind it (follow-up, investigation, administration): its own page is where it is
     * worked, and the person who works it decides when it is ready.
     *
     * @return PrimaryAction
     */
    public function forManual(stdClass $task, bool $isAssignee, bool $isReviewer, string $detail): array
    {
        return match ($task->status) {
            'pending' => $isAssignee ? $this->action('start', 'Iniciar', null, true, null, 'POST') : $this->action('view', 'Ver', $detail, true, null),
            'in_progress' => $isAssignee ? $this->action('continue', 'Continuar', $detail, true, null) : $this->action('view', 'Ver', $detail, true, null),
            'changes_requested' => $isAssignee ? $this->action('correct', 'Corregir', $detail, true, null) : $this->action('view', 'Ver', $detail, true, null),
            'ready_for_review', 'in_review' => $isReviewer ? $this->action('review', 'Revisar', $detail, true, null) : $this->action('view_review', 'Ver revisión', $detail, true, null),
            'approved' => $this->action('complete', 'Completar', $detail, true, null),
            'blocked' => $this->action('view_block', 'Ver bloqueo', $detail, true, null),
            'waiting_client', 'waiting_external' => $this->action('view_wait', 'Ver espera', $detail, true, null),
            'completed' => $this->action('view_result', 'Ver resultado', $detail, true, null),
            default => $this->action('view', 'Ver', $detail, true, null),
        };
    }

    /**
     * @param  array{start: bool, submit: bool, approve: bool, changes: bool, complete: bool, recalculate: bool}  $can
     * @param  array{ready: bool, pending: list<string>, error: string|null}|null  $readiness
     * @return PrimaryAction
     */
    private function inProgress(array $can, ?array $readiness, bool $mayFinishOwn, string $workspace, bool $isPreparer): array
    {
        if (! $isPreparer && ! $can['submit']) {
            return $this->action('view', 'Ver', $workspace, true, null);
        }
        if ($readiness === null || $readiness['error'] !== null || ! $readiness['ready']) {
            return $this->action('continue', 'Continuar', $workspace, true, null);
        }

        return $mayFinishOwn
            ? $this->action('finalize', 'Finalizar preparación', $workspace.'?confirm=finalize', true, null)
            : $this->action('submit', 'Enviar a revisión', $workspace.'?confirm=submit', true, null);
    }

    /**
     * A task on hold for the client or a third party: its page shows what is awaited and lets the office register the delivery.
     *
     * @return PrimaryAction
     */
    public function forWaiting(string $detail): array
    {
        return $this->action('view_wait', 'Ver espera', $detail, true, null);
    }

    /** @return PrimaryAction */
    private function action(string $key, string $label, ?string $destination, bool $enabled, ?string $reason, string $method = 'GET'): array
    {
        return ['key' => $key, 'label' => $label, 'destination' => $destination, 'enabled' => $enabled, 'disabled_reason' => $reason, 'method' => $method];
    }
}
