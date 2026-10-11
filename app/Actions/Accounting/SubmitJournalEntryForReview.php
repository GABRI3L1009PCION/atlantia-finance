<?php

namespace App\Actions\Accounting;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use stdClass;

class SubmitJournalEntryForReview
{
    public function __construct(private AccountingService $accounting) {}

    public function execute(Organization $organization, stdClass $entry, User $user): AccountingPeriodActionResult
    {
        $this->accounting->submitForReview($organization, $entry, $user);

        return AccountingPeriodActionResult::ok('Asiento enviado a revisión.');
    }
}
