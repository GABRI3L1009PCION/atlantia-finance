<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Tenancy\TenantDatabaseContext;
use Illuminate\Support\Facades\DB;

/**
 * The internal organization that holds Atlantia's own issuer company (`php artisan fel:setup-platform`): Atlantia uses it
 * to invoice its subscriptions, so it is not a client and stays out of client lists, metrics, alerts and notices.
 */
final class PlatformIssuer
{
    /** Id of that organization, or 0 while the issuer is not configured (so `<> 0` excludes nothing). */
    public static function organizationId(): int
    {
        return (int) app(TenantDatabaseContext::class)->runAsSystem(fn (): mixed => DB::table('platform_fel_issuer')
            ->join('companies', 'companies.id', '=', 'platform_fel_issuer.company_id')->join('offices', 'offices.id', '=', 'companies.office_id')
            ->where('platform_fel_issuer.id', 1)->value('offices.organization_id'));
    }
}
