<?php

use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\AccountingAutomationService;
use App\Services\FiscalConfigurationService;
use App\Services\WorkManagement\WorkAuthorizationService;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    Cache::flush();
    $this->seed(PlanCatalogSeeder::class);
});

/** A firm with one office, one company, its accountant in charge and a client contact. */
function cycleFixture(): array
{
    $owner = User::factory()->create();
    $organization = Organization::create(['name' => 'Firma Ciclo '.Str::random(4), 'status' => 'active']);
    $organization->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    $price = PlanPrice::whereHas('plan', fn ($query) => $query->where('code', 'enterprise'))->where('billing_period', 'monthly')->firstOrFail();
    $organization->subscriptions()->create(['status' => 'active'])->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    app(WorkAuthorizationService::class)->ensureDefaults($organization);
    $office = $organization->offices()->create(['code' => 'CIC', 'name' => 'Oficina Ciclo', 'status' => 'active', 'is_primary' => true]);
    $companyId = DB::table('companies')->insertGetId(['public_id' => (string) Str::uuid(), 'office_id' => $office->id, 'name' => 'Comercial Ceiba, S.A.', 'tax_id' => '7654321-0']);
    app(AccountingAutomationService::class)->ensureMapping($companyId);
    app(FiscalConfigurationService::class)->ensureDefaults($companyId, $owner->id);
    $accountant = User::factory()->create();
    $organization->users()->attach($accountant->id, ['role' => 'member', 'status' => 'active']);
    $office->users()->attach($accountant->id, ['role' => 'member', 'status' => 'active']);
    DB::table('company_assignments')->insert(['organization_id' => $organization->id, 'office_id' => $office->id, 'company_id' => $companyId, 'user_id' => $accountant->id,
        'assignment_role' => 'accountant_in_charge', 'is_primary' => true, 'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $client = User::factory()->create();
    $organization->users()->attach($client->id, ['role' => 'member', 'status' => 'active']);
    DB::table('user_role_assignments')->insert(['organization_id' => $organization->id, 'office_id' => $office->id, 'user_id' => $client->id,
        'workspace_role_id' => DB::table('workspace_roles')->where('organization_id', $organization->id)->where('code', 'client_user')->value('id'), 'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('client_company_access')->insert(['organization_id' => $organization->id, 'company_id' => $companyId, 'user_id' => $client->id, 'contact_role' => 'owner_contact', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

    return compact('owner', 'organization', 'office', 'companyId', 'accountant', 'client');
}

test('on its opening day the office opens last month with its tasks and asks the client for its documents, once', function () {
    $f = cycleFixture();
    $this->travelTo(Carbon::parse('2026-11-01 07:00', 'America/Guatemala'));
    $this->actingAs($f['owner'])->patch(route('office.offices.monthlyCycle', $f['office']->id), [
        'enabled' => true, 'open_day' => 2, 'close_package' => true,
        'recurring_requests' => [['title' => 'Estados de cuenta bancarios', 'type' => 'document', 'due_day' => 5], ['title' => 'Novedades de planilla', 'type' => 'payroll', 'due_day' => 3]],
    ])->assertSessionHasNoErrors();
    $this->actingAs($f['owner'])->get(route('office.offices.show', $f['office']->id))->assertInertia(fn (Assert $page) => $page
        ->where('admin.office.monthly_cycle.settings.enabled', true)->where('admin.office.monthly_cycle.settings.open_day', 2)->has('admin.office.monthly_cycle.settings.recurring_requests', 2));

    // Before the opening day nothing opens.
    $this->artisan('month:cycle')->assertSuccessful();
    expect(DB::table('monthly_cycle_runs')->where('company_id', $f['companyId'])->count())->toBe(0);

    $this->travelTo(Carbon::parse('2026-11-02 07:00', 'America/Guatemala'));
    $this->artisan('month:cycle')->assertSuccessful();
    $period = DB::table('accounting_periods')->where('company_id', $f['companyId'])->where('starts_on', '2026-10-01')->sole();
    expect($period->ends_on)->toBe('2026-10-31')
        ->and(DB::table('work_tasks')->where('company_id', $f['companyId'])->where('accounting_period_id', $period->id)->count())->toBeGreaterThan(0)
        ->and(DB::table('work_tasks')->where('company_id', $f['companyId'])->where('accounting_period_id', $period->id)->whereNotNull('assigned_to')->exists())->toBeTrue();
    $requests = DB::table('client_requests')->where('company_id', $f['companyId'])->orderBy('id')->get();
    expect($requests)->toHaveCount(2)
        ->and($requests[0]->title)->toBe('Estados de cuenta bancarios de octubre 2026')->and($requests[0]->due_date)->toBe('2026-11-05')
        ->and($requests[1]->type)->toBe('payroll')->and($requests[1]->due_date)->toBe('2026-11-03')
        ->and(DB::table('work_notifications')->where('user_id', $f['client']->id)->count())->toBeGreaterThanOrEqual(2);

    // The next days repeat nothing.
    $tasks = DB::table('work_tasks')->where('company_id', $f['companyId'])->count();
    $this->travelTo(Carbon::parse('2026-11-03 07:00', 'America/Guatemala'));
    $this->artisan('month:cycle')->assertSuccessful();
    expect(DB::table('work_tasks')->where('company_id', $f['companyId'])->count())->toBe($tasks)
        ->and(DB::table('client_requests')->where('company_id', $f['companyId'])->count())->toBe(2)
        ->and(DB::table('accounting_periods')->where('company_id', $f['companyId'])->count())->toBe(1);
});

test('a closed period gets its close package prepared and sent to review, never published by itself', function () {
    $f = cycleFixture();
    Storage::fake('local');
    $this->travelTo(Carbon::parse('2026-11-20 07:00', 'America/Guatemala'));
    $this->actingAs($f['owner'])->patch(route('office.offices.monthlyCycle', $f['office']->id), [
        'enabled' => true, 'open_day' => 25, 'close_package' => true, 'recurring_requests' => [],
    ])->assertSessionHasNoErrors();
    $periodId = DB::table('accounting_periods')->insertGetId(['company_id' => $f['companyId'], 'name' => 'Octubre 2026', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);
    DB::table('accounting_periods')->where('id', $periodId)->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $f['owner']->id]);

    $this->artisan('month:cycle')->assertSuccessful();
    $reports = DB::table('published_reports')->where('company_id', $f['companyId'])->get();
    expect($reports->pluck('report_type')->sort()->values()->all())->toBe(['balance_sheet', 'income_statement'])
        ->and($reports->pluck('status')->unique()->values()->all())->toBe(['in_review'])
        ->and(DB::table('work_notifications')->where('user_id', $f['accountant']->id)->where('title', 'like', 'Paquete de cierre listo%')->count())->toBe(1);

    $this->artisan('month:cycle')->assertSuccessful();
    expect(DB::table('published_reports')->where('company_id', $f['companyId'])->count())->toBe(2);
});

test('the monthly cycle validates its settings and only an office manager changes them', function () {
    $f = cycleFixture();
    $this->actingAs($f['owner'])->patch(route('office.offices.monthlyCycle', $f['office']->id), [
        'enabled' => true, 'open_day' => 31, 'close_package' => true, 'recurring_requests' => [['title' => '', 'type' => 'document', 'due_day' => 5]],
    ])->assertSessionHasErrors(['open_day', 'recurring_requests.0.title']);
    $this->actingAs($f['client'])->patch(route('office.offices.monthlyCycle', $f['office']->id), [
        'enabled' => false, 'open_day' => 1, 'close_package' => false, 'recurring_requests' => [],
    ])->assertForbidden();
});
