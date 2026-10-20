<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Services\Tenancy\TenantDatabaseContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Local demo owner of the Atlantia Control Center. It belongs to no client organization and must enable two-factor
 * on first sign-in, exactly like real platform staff.
 */
class PlatformStaffDemoSeeder extends Seeder
{
    use RefusesProduction;

    public function run(): void
    {
        $this->refuseInProduction();

        app(TenantDatabaseContext::class)->runAsSystem(function (): void {
            $user = User::query()->updateOrCreate(
                ['email' => 'superadmin@atlantia.test'],
                ['name' => 'Administrador Atlantia', 'password' => config('demo.password')],
            );
            $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();

            DB::table('platform_staff')->updateOrInsert(
                ['user_id' => $user->id],
                ['role' => 'saas_owner', 'status' => 'active', 'require_two_factor' => true, 'created_at' => now(), 'updated_at' => now()],
            );
        });
    }
}
