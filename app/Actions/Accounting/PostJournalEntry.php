<?php

namespace App\Actions\Accounting;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use stdClass;

class PostJournalEntry
{
    public function __construct(private AccountingService $accounting) {}

    public function execute(Organization $organization, int $companyId, stdClass $entry, User $user): AccountingPeriodActionResult
    {
        $this->accounting->post($companyId, $entry, $user);
        $this->accounting->audit($organization, $user, 'journal_entry.posted', 'journal_entries', $entry->id);

        return AccountingPeriodActionResult::ok('Asiento contabilizado.');
    }
}
