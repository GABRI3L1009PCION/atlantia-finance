<?php

use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\AccountingAutomationService;
use App\Services\AccountingService;
use App\Services\FiscalConfigurationService;
use App\Services\WorkManagement\CompanyAssignmentService;
use App\Services\WorkManagement\WorkAuthorizationService;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(PlanCatalogSeeder::class);
});

/**
 * A Multi-oficina firm with two companies in one office and one user per role the case study cares about.
 *
 * @return array{owner: User, org: Organization, officeId: int, companies: array<int, array{id: int, uuid: string}>, users: array<string, User>}
 */
function officeWideFixture(): array
{
    $owner = User::factory()->create();
    $org = Organization::create(['name' => 'Firma Roles '.Str::random(4), 'status' => 'active']);
    $org->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    $price = PlanPrice::whereHas('plan', fn ($query) => $query->where('code', 'multi_office'))->where('billing_period', 'monthly')->firstOrFail();
    $org->subscriptions()->create(['status' => 'active'])->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    $office = $org->offices()->create(['code' => 'MAIN', 'name' => 'Sede', 'status' => 'active', 'is_primary' => true]);
    $auth = app(WorkAuthorizationService::class);
    $auth->ensureDefaults($org);
    $owner = $owner->fresh();
    $orgForOwner = $owner->organizations()->whereKey($org->id)->firstOrFail();

    $companies = [];
    foreach (['Uno, S.A.', 'Dos, S.A.'] as $name) {
        $uuid = (string) Str::uuid();
        $id = DB::table('companies')->insertGetId(['public_id' => $uuid, 'office_id' => $office->id, 'name' => $name, 'tax_id' => Str::upper(Str::random(8)), 'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);
        app(AccountingAutomationService::class)->ensureMapping($id);
        app(FiscalConfigurationService::class)->ensureDefaults($id, $owner->id);
        $companies[] = ['id' => $id, 'uuid' => $uuid];
    }

    $users = [];
    foreach (['general_accountant', 'accounting_manager', 'read_only', 'accounting_assistant'] as $role) {
        $user = User::factory()->create();
        DB::table('organization_user')->insert(['organization_id' => $org->id, 'user_id' => $user->id, 'role' => 'member', 'status' => 'active', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('office_user')->insert(['organization_id' => $org->id, 'office_id' => $office->id, 'user_id' => $user->id, 'role' => 'member', 'status' => 'active', 'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $auth->assignRole($orgForOwner, $owner, $user->id, $role, $office->id);
        $users[$role] = $user;
    }

    return ['owner' => $owner, 'org' => $orgForOwner, 'officeId' => $office->id, 'companies' => $companies, 'users' => $users];
}

test('the general accountant and the accounting manager open every company of their office without being assigned to each one', function () {
    $f = officeWideFixture();

    foreach (['general_accountant', 'accounting_manager'] as $role) {
        $this->actingAs($f['users'][$role]);
        foreach ($f['companies'] as $company) {
            $this->get(route('accounting.show', $company['uuid']))->assertOk();
        }
    }
});

test('an assistant without an assignment still reaches no company', function () {
    $f = officeWideFixture();
    $this->actingAs($f['users']['accounting_assistant'])->get(route('accounting.show', $f['companies'][0]['uuid']))->assertNotFound();
});

test('read-only access is granted company by company and never allows writing', function () {
    $f = officeWideFixture();
    $reader = $f['users']['read_only'];

    $this->actingAs($reader)->get(route('accounting.show', $f['companies'][0]['uuid']))->assertNotFound();

    app(CompanyAssignmentService::class)->assign($f['org'], $f['owner'], $f['officeId'], $f['companies'][0]['id'], $reader->id, 'read_only', false, [], true);

    $this->actingAs($reader)->get(route('accounting.show', $f['companies'][0]['uuid']))->assertOk();
    $this->get(route('accounting.show', $f['companies'][1]['uuid']))->assertNotFound();
    $periodId = DB::table('accounting_periods')->insertGetId(['company_id' => $f['companies'][0]['id'], 'name' => '2026-11', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30']);
    $accounts = DB::table('ledger_accounts')->where('company_id', $f['companies'][0]['id'])->pluck('id', 'code');
    $this->post(route('accounting.entry', $f['companies'][0]['uuid']), ['request_id' => (string) Str::uuid(), 'period_id' => $periodId, 'entry_date' => '2026-11-02', 'description' => 'No debe crearse', 'kind' => 'adjustment',
        'lines' => [['account_id' => $accounts['1101'], 'debit' => '1.00', 'credit' => '0'], ['account_id' => $accounts['4101'], 'debit' => '0', 'credit' => '1.00']]])->assertForbidden();
    expect(DB::table('journal_entries')->where('description', 'No debe crearse')->exists())->toBeFalse();
});

test('the accounting manager reviews but cannot create or post entries', function () {
    $f = officeWideFixture();
    $journal = app(AccountingService::class);
    $manager = $f['users']['accounting_manager'];
    $general = $f['users']['general_accountant'];

    expect($journal->canJournal($f['org'], $f['companies'][0]['id'], $manager, 'review'))->toBeTrue()
        ->and($journal->canJournal($f['org'], $f['companies'][0]['id'], $manager, 'post'))->toBeFalse()
        ->and($journal->canJournal($f['org'], $f['companies'][0]['id'], $manager, 'create'))->toBeFalse()
        ->and($journal->canJournal($f['org'], $f['companies'][0]['id'], $general, 'post'))->toBeTrue();
});
