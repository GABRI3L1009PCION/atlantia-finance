<?php

declare(strict_types=1);

namespace App\Events\Work;

/**
 * Something that happened in the workflow of a task or in the business object behind it. Listeners keep every view
 * (tasks, panels, reminders, Luzma) aligned with the real state instead of each module copying it.
 */
abstract class WorkEvent
{
    /** @param  array<string, mixed>  $payload */
    public function __construct(
        public readonly int $organizationId,
        public readonly ?int $actorId = null,
        public readonly ?int $taskId = null,
        public readonly ?string $sourceType = null,
        public readonly ?int $sourceId = null,
        public readonly array $payload = [],
    ) {}
}
