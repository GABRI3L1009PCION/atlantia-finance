<?php

use App\Models\Office;
use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\FiscalConfigurationService;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(PlanCatalogSeeder::class);
});

test('owner manages tenant scoped Guatemala fiscal settings with immutable vigencies', function () {
    $fixture = fiscalSettingsFixture();
    $this->actingAs($fixture['owner'])
        ->get(route('accounting.fiscalSettings', $fixture['uuid']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('accounting/fiscal-settings')
            ->where('profile.country_code', 'GT')
            ->where('profile.base_currency', 'GTQ')
            ->has('rules', 5)
            ->where('canManage', true));

    $this->patch(route('accounting.fiscalSettingsUpdate', $fixture['uuid']), [
        'country_code' => 'GT',
        'base_currency' => 'USD',
        'timezone' => 'America/Guatemala',
        'vat_regime' => 'small_taxpayer',
        'income_tax_regime' => 'optional_simplified',
        'accounting_template' => 'guatemala_services',
        'exchange_rate_source' => 'BANGUAT',
        'fiscal_year_starts_in' => 1,
        'company_id' => 999,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->assertDatabaseHas('company_fiscal_profiles', [
        'company_id' => $fixture['companyId'],
        'country_code' => 'GT',
        'base_currency' => 'USD',
        'vat_regime' => 'small_taxpayer',
    ]);
    $this->assertDatabaseHas('companies', ['id' => $fixture['companyId'], 'currency' => 'USD']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'fiscal.profile.updated', 'auditable_id' => $fixture['companyId']]);

    $this->post(route('accounting.fiscalTaxRuleStore', $fixture['uuid']), [
        'code' => 'RETENCION_SERVICIOS',
        'name' => 'Retención de servicios configurada',
        'tax_type' => 'withholding',
        'regime' => 'general',
        'rate' => '5.25',
        'threshold' => '2500.01',
        'recoverable' => false,
        'valid_from' => '2026-09-01',
        'valid_to' => '',
        'is_active' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->assertDatabaseHas('fiscal_tax_rules', [
        'company_id' => $fixture['companyId'],
        'code' => 'RETENCION_SERVICIOS',
        'rate_basis_points' => 525,
        'threshold_cents' => 250001,
    ]);
});

test('fiscal settings deny other tenants and non owner mutations', function () {
    $fixture = fiscalSettingsFixture();
    $other = fiscalSettingsFixture();
    $member = User::factory()->create();
    $fixture['organization']->users()->attach($member->id, ['role' => 'member', 'status' => 'active']);
    $fixture['office']->users()->attach($member->id, ['role' => 'member', 'status' => 'active']);
    DB::table('company_user')->insert(['company_id' => $fixture['companyId'], 'user_id' => $member->id, 'permission' => 'editor']);

    $this->actingAs($other['owner'])->get(route('accounting.fiscalSettings', $fixture['uuid']))->assertNotFound();
    $this->actingAs($member)->get(route('accounting.fiscalSettings', $fixture['uuid']))->assertOk();
    $this->patch(route('accounting.fiscalSettingsUpdate', $fixture['uuid']), [])->assertForbidden();
    $this->post(route('accounting.fiscalTaxRuleStore', $fixture['uuid']), [])->assertForbidden();
    $this->post(route('accounting.fiscalExchangeRateStore', $fixture['uuid']), [])->assertForbidden();
});

test('Guatemala tax and exchange calculations use exact active dated rules', function () {
    $fixture = fiscalSettingsFixture();
    $service = app(FiscalConfigurationService::class);
    $service->ensureDefaults($fixture['companyId'], $fixture['owner']->id);

    expect($service->percentageTaxCents($fixture['companyId'], 'IVA_GENERAL', 100000, '2026-09-01'))->toBe(12000)
        ->and($service->optionalSimplifiedIncomeTaxCents($fixture['companyId'], 4000000, '2026-09-01'))->toBe(220000);

    DB::table('company_exchange_rates')->insert([
        'company_id' => $fixture['companyId'], 'currency' => 'USD', 'quote_currency' => 'GTQ',
        'rate' => '7.65000000', 'source' => 'BANGUAT', 'effective_on' => '2026-09-01',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    expect($service->convertToQuetzales($fixture['companyId'], 10000, 'USD', '2026-09-05'))->toBe(76500);

    DB::table('fiscal_tax_rules')->where('company_id', $fixture['companyId'])->where('code', 'IVA_GENERAL')->update(['valid_to' => '2026-08-31']);
    expect(fn () => $service->percentageTaxCents($fixture['companyId'], 'IVA_GENERAL', 100000, '2026-09-01'))->toThrow(ValidationException::class);
});

/** @return array{owner: User, organization: Organization, office: Office, uuid: string, companyId: int} */
function fiscalSettingsFixture(): array
{
    $owner = User::factory()->create();
    $organization = Organization::create(['name' => 'Oficina fiscal '.Str::random(6), 'status' => 'active']);
    $organization->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    $price = PlanPrice::whereHas('plan', fn ($query) => $query->where('code', 'essential'))->where('billing_period', 'monthly')->firstOrFail();
    $subscription = $organization->subscriptions()->create(['status' => 'active']);
    $subscription->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    $office = $organization->offices()->create(['code' => 'MAIN', 'name' => 'Sede fiscal', 'status' => 'active', 'is_primary' => true]);
    $uuid = (string) Str::uuid();
    $companyId = DB::table('companies')->insertGetId([
        'public_id' => $uuid, 'office_id' => $office->id, 'name' => 'Empresa fiscal',
        'tax_id' => Str::upper(Str::random(8)), 'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return compact('owner', 'organization', 'office', 'uuid', 'companyId');
}
