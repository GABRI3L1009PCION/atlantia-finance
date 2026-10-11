<?php

namespace App\Actions\Accounting;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use stdClass;

class ReverseJournalEntry
{
    public function __construct(private AccountingService $accounting) {}

    public function execute(Organization $organization, User $user, int $companyId, stdClass $entry, int $periodId, string $date, string $reason): AccountingPeriodActionResult
    {
        $reversalId = $this->accounting->reverse($organization, $user, $companyId, $entry, $periodId, $date, $reason);

        return AccountingPeriodActionResult::ok('Reverso registrado.', ['reversal_id' => $reversalId]);
    }
}
