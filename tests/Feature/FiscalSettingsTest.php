<?php

use App\Models\Office;
use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\AccountingService;
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
            // IVA (2), ISR (3), ISO and the ISR withholding of the factura especial.
            ->has('taxes', 7)
            ->where('taxes.0.code', 'IVA_GENERAL')
            ->where('taxes.0.current.rate_basis_points', 1200)
            ->where('canManage', true)
            ->where('overview.fel.configured', false)
            ->where('overview.obligation', null)
            ->has('overview.integrity.missing'));

    DB::table('company_fel_settings')->insert(['company_id' => $fixture['companyId'], 'legal_name' => 'Emisor', 'nit' => '1234567', 'address' => 'Ciudad',
        'provider' => 'sat_manual', 'certifier' => 'sat_manual', 'environment' => 'test', 'updated_by' => $fixture['owner']->id, 'created_at' => now(), 'updated_at' => now()]);
    $this->get(route('accounting.fiscalSettings', $fixture['uuid']))
        ->assertInertia(fn (Assert $page) => $page->where('overview.fel.mode', 'sat_manual')->where('overview.fel.configured', true));

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

test('owners create new taxes with a first dated version and extra tax types', function () {
    $fixture = fiscalSettingsFixture();
    $this->actingAs($fixture['owner'])->post(route('accounting.fiscalTaxStore', $fixture['uuid']), [
        'code' => 'TIMBRE_FISCAL', 'name' => 'Impuesto de timbres fiscales', 'tax_type' => 'stamp', 'regime' => 'general',
        'applies_to' => 'sales', 'calculation' => 'percentage', 'price_includes_tax' => false, 'recoverable' => false,
        'legal_basis' => 'Decreto 37-92', 'sat_form' => 'SAT-4031',
        'rate' => '3', 'valid_from' => '2026-01-01', 'legal_reference' => 'Decreto 37-92, art. 5',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $tax = DB::table('fiscal_taxes')->where('company_id', $fixture['companyId'])->where('code', 'TIMBRE_FISCAL')->first();
    expect($tax->tax_type)->toBe('stamp')->and($tax->is_system)->toBeFalse();
    $this->assertDatabaseHas('fiscal_tax_rules', ['fiscal_tax_id' => $tax->id, 'rate_basis_points' => 300, 'valid_from' => '2026-01-01', 'legal_reference' => 'Decreto 37-92, art. 5']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'fiscal.tax.created', 'auditable_id' => $tax->id]);
    expect(app(FiscalConfigurationService::class)->percentageTaxCents($fixture['companyId'], 'TIMBRE_FISCAL', 100000, '2026-05-01'))->toBe(3000);

    $this->post(route('accounting.fiscalTaxStore', $fixture['uuid']), [
        'code' => 'TIMBRE_FISCAL', 'name' => 'Duplicado', 'tax_type' => 'stamp', 'regime' => 'general', 'applies_to' => 'sales',
        'calculation' => 'percentage', 'price_includes_tax' => false, 'recoverable' => false, 'rate' => '3', 'valid_from' => '2026-02-01',
    ])->assertSessionHasErrors('code');
});

test('a decree change adds a version, closes the previous one and keeps history exact', function () {
    $fixture = fiscalSettingsFixture();
    $service = app(FiscalConfigurationService::class);
    $service->ensureDefaults($fixture['companyId'], $fixture['owner']->id);
    $vat = DB::table('fiscal_taxes')->where('company_id', $fixture['companyId'])->where('code', 'IVA_GENERAL')->first();

    $this->actingAs($fixture['owner'])->post(route('accounting.fiscalTaxVersionStore', [$fixture['uuid'], $vat->id]), [
        'rate' => '15', 'valid_from' => '2030-01-01', 'legal_reference' => 'Decreto 99-2029',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->assertDatabaseHas('fiscal_tax_rules', ['fiscal_tax_id' => $vat->id, 'valid_from' => '2013-01-01', 'valid_to' => '2029-12-31']);
    expect($service->percentageTaxCents($fixture['companyId'], 'IVA_GENERAL', 100000, '2029-12-31'))->toBe(12000)
        ->and($service->percentageTaxCents($fixture['companyId'], 'IVA_GENERAL', 100000, '2030-01-01'))->toBe(15000);

    $this->post(route('accounting.fiscalTaxVersionStore', [$fixture['uuid'], $vat->id]), ['rate' => '13', 'valid_from' => '2029-06-01'])
        ->assertSessionHasErrors('valid_from');

    $future = DB::table('fiscal_tax_rules')->where('fiscal_tax_id', $vat->id)->where('valid_from', '2030-01-01')->first();
    $current = DB::table('fiscal_tax_rules')->where('fiscal_tax_id', $vat->id)->where('valid_from', '2013-01-01')->first();
    $this->post(route('accounting.fiscalTaxVersionCancel', [$fixture['uuid'], $current->id]), ['reason' => 'Intento de corregir historia'])
        ->assertSessionHasErrors('tax_rule');
    $this->post(route('accounting.fiscalTaxVersionCancel', [$fixture['uuid'], $future->id]), ['reason' => 'Decreto derogado antes de entrar en vigor'])
        ->assertRedirect()->assertSessionHasNoErrors();

    $this->assertDatabaseHas('fiscal_tax_rules', ['id' => $future->id, 'is_active' => false]);
    $this->assertDatabaseHas('fiscal_tax_rules', ['id' => $current->id, 'valid_to' => null]);
    expect($service->percentageTaxCents($fixture['companyId'], 'IVA_GENERAL', 100000, '2031-01-01'))->toBe(12000);
    $this->assertDatabaseHas('audit_logs', ['action' => 'fiscal.tax_rule.cancelled', 'auditable_id' => $future->id]);
});

test('tax definitions keep their identity, use own accounts and cannot disable the regime tax in use', function () {
    $fixture = fiscalSettingsFixture();
    $other = fiscalSettingsFixture();
    $service = app(FiscalConfigurationService::class);
    $service->ensureDefaults($fixture['companyId'], $fixture['owner']->id);
    $vat = DB::table('fiscal_taxes')->where('company_id', $fixture['companyId'])->where('code', 'IVA_GENERAL')->first();
    $small = DB::table('fiscal_taxes')->where('company_id', $fixture['companyId'])->where('code', 'IVA_PEQUENO')->first();
    $ownAccount = DB::table('ledger_accounts')->insertGetId(['company_id' => $fixture['companyId'], 'code' => '1399', 'name' => 'IVA por recuperar', 'type' => 'asset']);
    $foreignAccount = DB::table('ledger_accounts')->insertGetId(['company_id' => $other['companyId'], 'code' => '1399', 'name' => 'Ajena', 'type' => 'asset']);
    $definition = ['name' => 'IVA general', 'applies_to' => 'both', 'price_includes_tax' => true, 'recoverable' => true, 'code' => 'CAMBIADO', 'tax_type' => 'other'];

    $this->actingAs($fixture['owner'])
        ->patch(route('accounting.fiscalTaxUpdate', [$fixture['uuid'], $vat->id]), [...$definition, 'recoverable_account_id' => $foreignAccount])
        ->assertSessionHasErrors('accounts');
    $this->patch(route('accounting.fiscalTaxUpdate', [$fixture['uuid'], $vat->id]), [...$definition, 'recoverable_account_id' => $ownAccount])
        ->assertRedirect()->assertSessionHasNoErrors();
    $this->assertDatabaseHas('fiscal_taxes', ['id' => $vat->id, 'code' => 'IVA_GENERAL', 'tax_type' => 'vat', 'name' => 'IVA general', 'recoverable_account_id' => $ownAccount]);

    $this->patch(route('accounting.fiscalTaxStatus', [$fixture['uuid'], $vat->id]), ['status' => 'inactive'])->assertSessionHasErrors('tax');
    $this->patch(route('accounting.fiscalTaxStatus', [$fixture['uuid'], $small->id]), ['status' => 'inactive'])->assertSessionHasNoErrors();
    expect(fn () => $service->activeRule($fixture['companyId'], 'IVA_PEQUENO', '2026-05-01'))->toThrow(ValidationException::class);
});

test('the VAT applied follows the company regime and links the starter chart accounts', function () {
    $fixture = fiscalSettingsFixture();
    $service = app(FiscalConfigurationService::class);
    app(AccountingService::class)->installDefaultChart($fixture['companyId']);
    $service->ensureDefaults($fixture['companyId'], $fixture['owner']->id);

    $general = $service->vatRule($fixture['companyId'], '2026-05-01');
    expect($general->code)->toBe('IVA_GENERAL')
        ->and((int) $general->rate_basis_points)->toBe(1200)
        ->and((bool) $general->price_includes_tax)->toBeTrue()
        ->and($general->recoverable_account_id)->toBe(DB::table('ledger_accounts')->where('company_id', $fixture['companyId'])->where('code', '1301')->value('id'))
        ->and($general->payable_account_id)->toBe(DB::table('ledger_accounts')->where('company_id', $fixture['companyId'])->where('code', '2201')->value('id'))
        ->and($service->includedTaxCents(11200, 1200))->toBe(1200)
        ->and($service->includedTaxCents(1000, 1200))->toBe(107);

    DB::table('company_fiscal_profiles')->where('company_id', $fixture['companyId'])->update(['vat_regime' => 'small_taxpayer']);
    expect($service->vatRule($fixture['companyId'], '2026-05-01')->code)->toBe('IVA_PEQUENO');
    DB::table('company_fiscal_profiles')->where('company_id', $fixture['companyId'])->update(['vat_regime' => 'exempt']);
    expect($service->vatRule($fixture['companyId'], '2026-05-01'))->toBeNull()
        ->and($service->purchaseVatRule($fixture['companyId'], '2026-05-01')->code)->toBe('IVA_GENERAL');
});

test('members without fiscal management cannot change taxes', function () {
    $fixture = fiscalSettingsFixture();
    app(FiscalConfigurationService::class)->ensureDefaults($fixture['companyId'], $fixture['owner']->id);
    $vat = DB::table('fiscal_taxes')->where('company_id', $fixture['companyId'])->where('code', 'IVA_GENERAL')->value('id');
    $member = User::factory()->create();
    $fixture['organization']->users()->attach($member->id, ['role' => 'member', 'status' => 'active']);
    $fixture['office']->users()->attach($member->id, ['role' => 'member', 'status' => 'active']);
    DB::table('company_user')->insert(['company_id' => $fixture['companyId'], 'user_id' => $member->id, 'permission' => 'editor']);

    $this->actingAs($member);
    $this->post(route('accounting.fiscalTaxStore', $fixture['uuid']), [])->assertForbidden();
    $this->patch(route('accounting.fiscalTaxUpdate', [$fixture['uuid'], $vat]), [])->assertForbidden();
    $this->post(route('accounting.fiscalTaxVersionStore', [$fixture['uuid'], $vat]), [])->assertForbidden();
    $this->patch(route('accounting.fiscalTaxStatus', [$fixture['uuid'], $vat]), [])->assertForbidden();
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
