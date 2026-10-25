<?php

use App\Models\Organization;
use App\Services\Accounting\ChartOfAccountsCatalog;
use App\Services\AccountingService;
use App\Services\Tenancy\TenantDatabaseContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('the detailed catalog has 200 unique accounts that never reuse a starter or regional code', function () {
    $codes = array_column(ChartOfAccountsCatalog::ACCOUNTS, 'code');
    $starter = array_column((new ReflectionClassConstant(AccountingService::class, 'DEFAULT_CHART'))->getValue(), 'code');
    $regional = [];
    foreach (config('regions.templates') as $template) {
        foreach ($template['accounts'] as [$code, $name]) {
            $regional[$code][] = $name;
        }
    }

    expect($codes)->toHaveCount(200)
        ->and(array_unique($codes))->toHaveCount(200)
        ->and(array_intersect($codes, $starter))->toBe([])
        ->and(array_column(ChartOfAccountsCatalog::ACCOUNTS, 'type'))->each->toBeIn(['asset', 'liability', 'equity', 'income', 'expense']);
    // A regional account may only share a code with the catalog when it is the same account.
    foreach (ChartOfAccountsCatalog::ACCOUNTS as $account) {
        foreach ($regional[$account['code']] ?? [] as $name) {
            expect($name)->toBe($account['name']);
        }
    }
    expect(array_intersect(array_keys($regional), $starter))->toBe([]);
});

test('refreshing the chart adds the new accounts to existing companies without touching the ones they already have', function () {
    $organization = Organization::create(['name' => 'Firma Catálogo '.Str::random(4), 'status' => 'active']);
    $office = $organization->offices()->create(['code' => 'MAIN', 'name' => 'Sede', 'status' => 'active', 'is_primary' => true]);
    $companyId = DB::table('companies')->insertGetId(['public_id' => (string) Str::uuid(), 'office_id' => $office->id, 'name' => 'Empresa Antigua', 'tax_id' => Str::upper(Str::random(8)),
        'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('ledger_accounts')->insert(['company_id' => $companyId, 'code' => '5211', 'name' => 'Cuota IGSS de la empresa', 'type' => 'expense', 'created_at' => now(), 'updated_at' => now()]);

    $this->artisan('accounting:refresh-chart')->assertSuccessful();
    $this->artisan('accounting:refresh-chart')->assertSuccessful();

    app(TenantDatabaseContext::class)->runAsSystem(function () use ($companyId): void {
        expect(DB::table('ledger_accounts')->where('company_id', $companyId)->count())->toBe(227)
            ->and(DB::table('ledger_accounts')->where('company_id', $companyId)->where('code', '5211')->value('name'))->toBe('Cuota IGSS de la empresa')
            ->and(DB::table('ledger_accounts')->where('company_id', $companyId)->where('code', '5243')->value('name'))->toBe('Combustibles y lubricantes');
    });
});
