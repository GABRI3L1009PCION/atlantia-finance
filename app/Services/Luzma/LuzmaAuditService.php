<?php

declare(strict_types=1);

namespace App\Services\Luzma;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;

/**
 * Audit trail of Luzma: queries, tools, actions and reminders are recorded with who and how (initiated_via), but
 * never the full question text or the data returned, so sensitive content does not end up in the log.
 */
class LuzmaAuditService
{
    public function __construct(private AccountingService $accounting) {}

    /** @param array<string, mixed> $details */
    public function record(Organization $organization, User $user, string $action, string $table, int $id, array $details = []): void
    {
        $this->accounting->audit($organization, $user, $action, $table, $id, [...$details, 'initiated_via' => $details['initiated_via'] ?? 'luzma']);
    }
}
