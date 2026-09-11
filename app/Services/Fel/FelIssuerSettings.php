<?php

declare(strict_types=1);

namespace App\Services\Fel;

use Illuminate\Support\Facades\DB;
use stdClass;

class FelIssuerSettings
{
    public function platformCompanyUuid(): ?string
    {
        return config('fel.subscription_issuer') ?: DB::table('platform_fel_issuer')
            ->join('companies', 'companies.id', '=', 'platform_fel_issuer.company_id')
            ->where('platform_fel_issuer.id', 1)->value('companies.public_id');
    }

    /** @return array{name: string, nit: string, address: string, logo_data_uri: ?string, provider: string, environment: string} */
    public function forCompany(stdClass $company): array
    {
        $settings = DB::table('company_fel_settings')->where('company_id', $company->id)->first();

        return [
            'name' => $settings->legal_name ?? $company->name,
            'nit' => $settings->nit ?? $company->tax_id,
            'address' => $settings->address ?? '',
            'logo_data_uri' => $settings->logo_data_uri ?? null,
            'provider' => $settings->provider ?? config('fel.provider'),
            'environment' => $settings->environment ?? config('fel.environment'),
        ];
    }
}
