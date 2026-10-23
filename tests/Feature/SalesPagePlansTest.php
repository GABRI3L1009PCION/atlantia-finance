<?php

use App\Services\Platform\PlanService;
use App\Services\Platform\PublicPlanCatalog;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(PlanCatalogSeeder::class);
});

test('the sales page shows the real catalog: prices, capacity of the published version and the configured recommendation', function () {
    $this->get(route('home'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('welcome')
        ->has('plans', 5)
        ->where('plans.0.code', 'essential')
        ->where('plans.0.monthly_price', fn ($value) => (float) $value === 399.0)
        ->where('plans.0.annual_price', fn ($value) => (float) $value === 3990.0)
        ->where('plans.0.months_included', 2)
        ->where('plans.0.recommended', false)
        ->where('plans.0.features.0.text', '1 oficina')
        ->where('plans.0.features.1.text', '2 usuarios y 150 empresas cliente')
        ->where('plans.0.features.2.text', '2 GB de almacenamiento')
        ->where('plans.0.features.3.text', '20 consultas de Luzma al mes')
        ->where('plans.0.features.3.hint', fn ($hint) => is_string($hint) && $hint !== '')
        ->where('plans.1.code', 'professional')
        ->where('plans.1.recommended', true)
        ->where('plans.2.features.0.text', 'Hasta 5 oficinas')
        ->where('plans.4.code', 'enterprise')
        ->where('plans.4.price_prefix', 'Desde')
        ->where('plans.4.cta_label', 'Solicitar cotización')
        ->where('plans.4.features.0.text', 'Oficinas a medida'));
});

test('a plan without payroll says so and names the plan that has it', function () {
    $catalog = collect(app(PublicPlanCatalog::class)->plans())->keyBy('code');
    $texts = fn (string $code): array => collect($catalog[$code]['features'])->pluck('text')->all();

    expect($texts('multi_office'))->toContain('Nómina no incluida (disponible desde el plan Corporativo)')
        ->and($texts('essential'))->toContain('Nómina no incluida (disponible desde el plan Corporativo)')
        ->and(collect($texts('corporate'))->filter(fn (string $text): bool => str_starts_with($text, 'Nómina no incluida')))->toBeEmpty()
        ->and(collect($texts('enterprise'))->filter(fn (string $text): bool => str_starts_with($text, 'Nómina no incluida')))->toBeEmpty();
});

test('every plan includes electronic invoicing (FEL)', function () {
    foreach (DB::table('plans')->pluck('id', 'code') as $code => $planId) {
        $entitlements = json_decode((string) DB::table('plan_versions')->where('plan_id', $planId)->where('status', 'published')->value('entitlements'), true);
        expect($entitlements)->toContain('basic_invoicing')->toContain('fel');
    }
});

test('catalogs loaded before FEL was in every plan are brought up to date by the migration', function () {
    $plan = DB::table('plans')->where('code', 'essential')->first();
    $version = DB::table('plan_versions')->where('plan_id', $plan->id)->where('status', 'published')->first();
    DB::table('plan_versions')->where('id', $version->id)->update(['entitlements' => json_encode(array_values(array_diff(json_decode($version->entitlements, true), ['fel'])))]);
    DB::table('feature_plan')->where('plan_id', $plan->id)->where('feature_id', DB::table('features')->where('code', 'fel')->value('id'))->delete();
    DB::table('plans')->where('id', $plan->id)->update(['highlights' => json_encode(['Contabilidad y facturación básica', 'Otra línea'])]);

    (require database_path('migrations/2026_10_23_193110_include_fel_in_every_plan.php'))->up();

    expect(json_decode((string) DB::table('plan_versions')->where('id', $version->id)->value('entitlements'), true))->toContain('fel')
        ->and(DB::table('feature_plan')->where('plan_id', $plan->id)->where('feature_id', DB::table('features')->where('code', 'fel')->value('id'))->exists())->toBeTrue()
        ->and(json_decode((string) DB::table('plans')->where('id', $plan->id)->value('highlights'), true))->toBe(['Contabilidad y facturación electrónica (FEL)', 'Otra línea']);
});

test('a plan without the VAT books says so and names the plan that has them', function () {
    $catalog = collect(app(PublicPlanCatalog::class)->plans())->keyBy('code');
    $texts = fn (string $code): array => collect($catalog[$code]['features'])->pluck('text')->all();

    expect($texts('essential'))->toContain('Libros de IVA y reportes avanzados no incluidos (disponibles desde el plan Profesional)')
        ->and(collect($texts('professional'))->filter(fn (string $text): bool => str_starts_with($text, 'Libros de IVA')))->toBeEmpty();
});

test('a newly published version changes what the sales page shows and retired plans disappear', function () {
    $owner = ccOwner();
    $plan = DB::table('plans')->where('code', 'essential')->first();
    $current = DB::table('plan_versions')->where('plan_id', $plan->id)->where('status', 'published')->first();
    $limits = json_decode($current->limits, true);
    $limits['users']['value'] = 3;
    $plans = app(PlanService::class);
    $draft = $plans->draftVersion($owner, $plan->id, ['name' => $current->name, 'monthly_price' => 449, 'annual_price' => 4490, 'entitlements' => json_decode($current->entitlements, true), 'limits' => $limits], 'Un usuario más');
    $plans->publish($owner, $draft, 'Nueva versión');
    DB::table('plans')->where('code', 'corporate')->update(['is_active' => false]);

    $catalog = app(PublicPlanCatalog::class)->plans();
    $essential = collect($catalog)->firstWhere('code', 'essential');
    expect(collect($catalog)->pluck('code')->all())->toBe(['essential', 'professional', 'multi_office', 'enterprise'])
        ->and($essential['monthly_price'])->toBe(449.0)
        ->and($essential['annual_savings'])->toBe(898.0)
        ->and($essential['features'][1]['text'])->toBe('3 usuarios y 150 empresas cliente');
});

test('the presentation is edited from the control center with a single recommended plan and an audit trail', function () {
    $owner = ccOwner();
    $multiOffice = DB::table('plans')->where('code', 'multi_office')->value('id');

    $this->actingAs($owner)->post('/control-center/plans/'.$multiOffice.'/presentation', ['tagline' => 'Para crecer en sedes', 'highlights' => ['Panel consolidado', ''],
        'is_recommended' => true])->assertSessionHasErrors('reason');
    $this->actingAs($owner)->post('/control-center/plans/'.$multiOffice.'/presentation', ['tagline' => 'Para crecer en sedes', 'highlights' => ['Panel consolidado', ''],
        'is_recommended' => true, 'reason' => 'Campaña de sedes'])->assertSessionHasNoErrors();

    expect(DB::table('plans')->where('is_recommended', true)->pluck('code')->all())->toBe(['multi_office'])
        ->and(DB::table('platform_audit_events')->where('action', 'plan.presentation_updated')->exists())->toBeTrue();
    $shown = collect(app(PublicPlanCatalog::class)->plans())->firstWhere('code', 'multi_office');
    expect($shown['tagline'])->toBe('Para crecer en sedes')->and($shown['recommended'])->toBeTrue()
        ->and(collect($shown['features'])->pluck('text')->last())->toBe('Panel consolidado');

    // Re-running the catalog seeder never overwrites what the Control Center edited.
    $this->seed(PlanCatalogSeeder::class);
    expect(DB::table('plans')->where('code', 'multi_office')->value('tagline'))->toBe('Para crecer en sedes')
        ->and(DB::table('plans')->where('is_recommended', true)->count())->toBe(1);
});

test('the sales page says the plans could not be loaded instead of showing zero prices', function () {
    $this->mock(PublicPlanCatalog::class, fn ($mock) => $mock->shouldReceive('plans')->andThrow(new RuntimeException('database down')));

    $this->get(route('home'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('welcome')->where('plans', null));
});
