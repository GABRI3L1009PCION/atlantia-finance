<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use App\Services\Platform\OrganizationManagementService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

/**
 * Local-only test account of a firm on the Multi-oficina plan, created through the same onboarding the platform uses.
 * The invitation e-mail is never sent from here; in production the firm is onboarded from the Control Center and the
 * owner receives a formal invitation and chooses their own password.
 */
class MultiOfficePlanDemoSeeder extends Seeder
{
    use RefusesProduction;

    private const EMAIL = 'contadoresyconsultoresdeizabal@gmail.com';

    public function run(): void
    {
        $this->refuseInProduction();

        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Esta cuenta de prueba solo se crea en local.');
        }
        $this->call(PlanCatalogSeeder::class);
        if (User::query()->where('email', self::EMAIL)->exists()) {
            return;
        }
        $staffId = DB::table('platform_staff')->orderBy('id')->value('user_id');
        $actor = $staffId === null ? null : User::query()->find((int) $staffId);
        if ($actor === null) {
            $this->call(PlatformStaffDemoSeeder::class);
            $actor = User::query()->findOrFail((int) DB::table('platform_staff')->orderBy('id')->value('user_id'));
        }
        $priceId = (int) DB::table('plan_prices')->join('plans', 'plans.id', '=', 'plan_prices.plan_id')->where('plans.code', 'multi_office')->where('plan_prices.billing_period', 'monthly')->value('plan_prices.id');

        Notification::fake();
        $result = app(OrganizationManagementService::class)->onboard($actor, ['name' => 'Contadores y Consultores de Izabal', 'plan_price_id' => $priceId, 'trial' => true,
            'owner_name' => 'Contadores y Consultores de Izabal', 'owner_email' => self::EMAIL, 'office_name' => 'Oficina Central', 'office_city' => 'Puerto Barrios']);

        // The owner "accepts" the invitation the way the signed link would, with the shared demo password for local testing.
        $organization = Organization::query()->findOrFail($result['organization_id']);
        $user = User::query()->create(['name' => 'Contadores y Consultores de Izabal', 'email' => self::EMAIL, 'password' => config('demo.password')]);
        $user->markEmailAsVerified();
        $organization->users()->attach($user->id, ['role' => 'owner', 'status' => 'active', 'joined_at' => now(), 'invited_by' => $actor->id]);
        foreach ($organization->offices()->get() as $office) {
            $office->users()->syncWithoutDetaching([$user->id => ['role' => 'manager', 'status' => 'active', 'assigned_at' => now()]]);
        }
        DB::table('invitations')->where('id', $result['invitation_id'])->update(['status' => 'accepted', 'accepted_at' => now()]);
        $user->forceFill(['current_organization_id' => $organization->id])->save();
    }
}
