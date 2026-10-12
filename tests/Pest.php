<?php

use App\Models\User;
use App\Services\Platform\GlobalConfigurationService;
use App\Services\Platform\OrganizationManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/** Active Atlantia Control Center staff member with the given SaaS role. */
function ccStaff(string $role = 'saas_owner'): User
{
    $user = User::factory()->create();
    DB::table('platform_staff')->insert(['user_id' => $user->id, 'role' => $role, 'status' => 'active', 'require_two_factor' => false, 'created_at' => now(), 'updated_at' => now()]);

    return $user;
}

/** Turns off the staff two-factor policy so tests can reach the Control Center without a TOTP device. */
function ccRelaxTwoFactor(): void
{
    DB::table('platform_settings')->updateOrInsert(['key' => 'security.require_two_factor_for_staff'],
        ['group' => 'security', 'value' => json_encode(false), 'sensitive' => true, 'created_at' => now(), 'updated_at' => now()]);
    app()->forgetInstance(GlobalConfigurationService::class);
}

function ccOwner(): User
{
    ccRelaxTwoFactor();

    return ccStaff('saas_owner');
}

/**
 * Re-authentication payload of a sensitive Control Center action (factory password).
 *
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function ccConfirm(array $extra = []): array
{
    return ['password' => 'password', 'reason' => 'Motivo de la prueba', ...$extra];
}

function ccPrice(string $planCode, string $period = 'monthly'): int
{
    return (int) DB::table('plan_prices')->join('plans', 'plans.id', '=', 'plan_prices.plan_id')
        ->where('plans.code', $planCode)->where('plan_prices.billing_period', $period)->value('plan_prices.id');
}

/** Client organization onboarded through the Control Center service; returns its id. */
function ccOrganization(User $actor, string $planCode = 'professional', bool $trial = false, string $name = 'Firma Cliente'): int
{
    return app(OrganizationManagementService::class)->onboard($actor, ['name' => $name, 'plan_price_id' => ccPrice($planCode), 'trial' => $trial,
        'owner_name' => 'Dueño '.$name, 'owner_email' => Str::slug($name).'@cliente.test', 'office_name' => 'Central'])['organization_id'];
}
