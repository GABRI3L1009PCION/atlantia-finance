<?php

use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\AccountingAutomationService;
use App\Services\FiscalConfigurationService;
use App\Services\WorkManagement\CompanyAssignmentService;
use App\Services\WorkManagement\ProfitabilityService;
use App\Services\WorkManagement\TimeTrackingService;
use App\Services\WorkManagement\WorkAuthorizationService;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(PlanCatalogSeeder::class);
});

/**
 * The three clients of the case study, with their agreed fees, in one office of a Multi-oficina firm.
 *
 * @return array{owner: User, org: Organization, officeId: int, companies: array<string, int>, uuids: array<string, string>, assistant: User}
 */
function profitabilityFixture(): array
{
    $owner = User::factory()->create();
    $org = Organization::create(['name' => 'Firma Rentabilidad '.Str::random(4), 'status' => 'active']);
    $org->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    $price = PlanPrice::whereHas('plan', fn ($query) => $query->where('code', 'multi_office'))->where('billing_period', 'monthly')->firstOrFail();
    $org->subscriptions()->create(['status' => 'active'])->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    $office = $org->offices()->create(['code' => 'MAIN', 'name' => 'Sede', 'status' => 'active', 'is_primary' => true]);
    $auth = app(WorkAuthorizationService::class);
    $auth->ensureDefaults($org);
    $owner = $owner->fresh();
    $org = $owner->organizations()->whereKey($org->id)->firstOrFail();

    $assistant = User::factory()->create(['name' => 'José Martínez']);
    DB::table('organization_user')->insert(['organization_id' => $org->id, 'user_id' => $assistant->id, 'role' => 'member', 'status' => 'active', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('office_user')->insert(['organization_id' => $org->id, 'office_id' => $office->id, 'user_id' => $assistant->id, 'role' => 'member', 'status' => 'active', 'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $auth->assignRole($org, $owner, $assistant->id, 'accounting_assistant', $office->id);

    $companies = [];
    $uuids = [];
    foreach (['El Faro' => [1500, 900, 15], 'Repuestos' => [2200, 1500, 0], 'Bahía' => [1800, 2000, 0]] as $name => [$fee]) {
        $uuid = (string) Str::uuid();
        $id = DB::table('companies')->insertGetId(['public_id' => $uuid, 'office_id' => $office->id, 'name' => $name.', S.A.', 'tax_id' => Str::upper(Str::random(8)), 'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);
        app(AccountingAutomationService::class)->ensureMapping($id);
        app(FiscalConfigurationService::class)->ensureDefaults($id, $owner->id);
        DB::table('crm_contracts')->insert(['organization_id' => $org->id, 'company_id' => $id, 'services' => 'Contabilidad mensual', 'monthly_fee_cents' => $fee * 100, 'starts_on' => '2026-01-01', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $companies[$name] = $id;
        $uuids[$name] = $uuid;
    }
    app(Kernel::class);

    return ['owner' => $owner, 'org' => $org, 'officeId' => $office->id, 'companies' => $companies, 'uuids' => $uuids, 'assistant' => $assistant];
}

function profitabilityHours(array $f, string $company, int $hours, string $date): void
{
    for ($day = 0; $hours > 0; $day++) {
        $chunk = min(8, $hours);
        DB::table('time_entries')->insert(['organization_id' => $f['org']->id, 'office_id' => $f['officeId'], 'company_id' => $f['companies'][$company], 'user_id' => $f['owner']->id, 'work_date' => Carbon::parse($date)->addDays($day)->toDateString(),
            'minutes' => $chunk * 60, 'cost_per_hour_cents' => 5000, 'created_at' => now(), 'updated_at' => now()]);
        $hours -= $chunk;
    }
}

test('the case numbers: fees 5,500, 88 hours, direct cost 4,400 and margin 1,100, with the overrun and the loss flagged', function () {
    $f = profitabilityFixture();
    app(ProfitabilityService::class)->setBudget($f['org'], $f['owner'], $f['companies']['El Faro'], '15');
    profitabilityHours($f, 'El Faro', 18, '2026-11-10');
    profitabilityHours($f, 'Repuestos', 30, '2026-11-12');
    profitabilityHours($f, 'Bahía', 40, '2026-11-20');

    $report = app(ProfitabilityService::class)->forMonth($f['org'], $f['owner'], '2026-11');
    $rows = collect($report['rows'])->keyBy('name');

    expect($report['totals'])->toMatchArray(['fee_cents' => 550000, 'minutes' => 88 * 60, 'cost_cents' => 440000, 'margin_cents' => 110000])
        ->and($rows['El Faro, S.A.'])->toMatchArray(['margin_cents' => 60000, 'deviation_minutes' => 180, 'deviation_pct' => 20.0, 'flag' => 'over_budget'])
        ->and($rows['Repuestos, S.A.']['margin_cents'])->toBe(70000)
        ->and($rows['Bahía, S.A.'])->toMatchArray(['margin_cents' => -20000, 'flag' => 'loss']);
});

test('a person records their own hours, with the cost per hour of the office copied into the record', function () {
    $f = profitabilityFixture();
    DB::table('offices')->where('id', $f['officeId'])->update(['hourly_cost_cents' => 6000]);
    $company = (object) ['id' => $f['companies']['El Faro'], 'office_id' => $f['officeId']];

    $id = app(TimeTrackingService::class)->record($f['org'], $f['owner'], $company, ['date' => now()->toDateString(), 'hours' => '1.5', 'activity' => 'registro']);

    $entry = DB::table('time_entries')->find($id);
    expect($entry->minutes)->toBe(90)->and($entry->cost_per_hour_cents)->toBe(6000);
    DB::table('offices')->where('id', $f['officeId'])->update(['hourly_cost_cents' => 9000]);
    expect(DB::table('time_entries')->find($id)->cost_per_hour_cents)->toBe(6000);
});

test('hours are validated: no future dates, no more than 24 hours a day, no stale dates', function () {
    $f = profitabilityFixture();
    $service = app(TimeTrackingService::class);
    $company = (object) ['id' => $f['companies']['El Faro'], 'office_id' => $f['officeId']];

    expect(fn () => $service->record($f['org'], $f['owner'], $company, ['date' => now()->addDay()->toDateString(), 'hours' => '1']))->toThrow(ValidationException::class)
        ->and(fn () => $service->record($f['org'], $f['owner'], $company, ['date' => now()->subDays(60)->toDateString(), 'hours' => '1']))->toThrow(ValidationException::class)
        ->and(fn () => $service->record($f['org'], $f['owner'], $company, ['date' => now()->toDateString(), 'hours' => 'abc']))->toThrow(ValidationException::class);
    $service->record($f['org'], $f['owner'], $company, ['date' => now()->toDateString(), 'hours' => '16']);
    $service->record($f['org'], $f['owner'], $company, ['date' => now()->toDateString(), 'hours' => '8']);
    expect(fn () => $service->record($f['org'], $f['owner'], $company, ['date' => now()->toDateString(), 'hours' => '1']))->toThrow(ValidationException::class);
});

test('only the owner of a record can delete it', function () {
    $f = profitabilityFixture();
    $service = app(TimeTrackingService::class);
    $company = (object) ['id' => $f['companies']['El Faro'], 'office_id' => $f['officeId']];
    $id = $service->record($f['org'], $f['owner'], $company, ['date' => now()->toDateString(), 'hours' => '1']);

    expect(fn () => $service->remove($f['assistant'], $id))->toThrow(NotFoundHttpException::class);
    $service->remove($f['owner'], $id);
    expect(DB::table('time_entries')->where('id', $id)->exists())->toBeFalse();
});

test('the director sees the profitability screen and an assistant does not, but the assistant records hours on their companies', function () {
    $f = profitabilityFixture();

    $this->actingAs($f['owner'])->get('/office/profitability?month=2026-11')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('work/profitability')->has('rows', 3)->where('canManage', true));

    $this->actingAs($f['assistant'])->get('/office/profitability')->assertForbidden();
    $this->actingAs($f['assistant'])->get('/work/time')->assertOk()->assertInertia(fn (Assert $page) => $page->component('work/time')->has('companies', 0));

    app(CompanyAssignmentService::class)->assign($f['org'], $f['owner'], $f['officeId'], $f['companies']['El Faro'], $f['assistant']->id, 'accounting_assistant', false, [], true);
    $this->actingAs($f['assistant'])->post('/work/time', ['company_id' => $f['companies']['El Faro'], 'date' => now()->toDateString(), 'hours' => '2', 'activity' => 'registro'])->assertSessionHasNoErrors();
    expect((int) DB::table('time_entries')->where('user_id', $f['assistant']->id)->sum('minutes'))->toBe(120);

    $this->actingAs($f['assistant'])->post('/work/time', ['company_id' => $f['companies']['Bahía'], 'date' => now()->toDateString(), 'hours' => '2']);
    expect(DB::table('time_entries')->where('user_id', $f['assistant']->id)->where('company_id', $f['companies']['Bahía'])->exists())->toBeFalse();
});

test('a budget needs an active contract and the cost per hour is edited by whoever manages clients', function () {
    $f = profitabilityFixture();
    $service = app(ProfitabilityService::class);

    DB::table('crm_contracts')->where('company_id', $f['companies']['Bahía'])->update(['status' => 'cancelled']);
    expect(fn () => $service->setBudget($f['org'], $f['owner'], $f['companies']['Bahía'], '20'))->toThrow(ValidationException::class);

    $service->setHourlyCost($f['org'], $f['owner'], $f['officeId'], '75.50');
    expect((int) DB::table('offices')->where('id', $f['officeId'])->value('hourly_cost_cents'))->toBe(7550);
    $this->actingAs($f['assistant'])->patch('/office/profitability/cost', ['office_id' => $f['officeId'], 'cost' => '1'])->assertForbidden();
});
