<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Organization;
use App\Services\AccountingService;
use App\Services\WorkspaceContext;
use Illuminate\Http\Request;
use stdClass;

/** Resolves the company of an accounting screen and checks the plan feature and the user's permission on it. */
trait ScopesAccountingCompany
{
    /** @return array{0: Organization, 1: stdClass} */
    protected function scopeCompany(Request $request, string $company, string $permission, string $feature = 'basic_accounting'): array
    {
        $context = app(WorkspaceContext::class);
        $accounting = app(AccountingService::class);
        $organization = $context->organization($request->user());
        $context->requireFeature($organization, $feature);
        $isRead = str_ends_with($permission, '.view') || str_ends_with($permission, '.export');
        $record = $accounting->company($organization, $request->user(), $company, ! $isRead);
        $accounting->assertModule($organization, $record->id, $request->user(), $permission);

        return [$organization, $record];
    }

    protected function can(Request $request, Organization $organization, stdClass $company, string $permission): bool
    {
        return app(AccountingService::class)->canModule($organization, $company->id, $request->user(), $permission);
    }
}
