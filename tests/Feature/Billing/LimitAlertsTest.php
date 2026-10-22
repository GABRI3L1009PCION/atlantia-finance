<?php

use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\Platform\Billing\SubscriptionService;
use App\Services\Platform\LimitAlertService;
use App\Services\Tenancy\TenantDatabaseContext;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Cache::flush();
    $this->seed(PlanCatalogSeeder::class);
});

/** Firm on the Essential plan (1 office, 2 users): an owner, one more member and one office put it at 100 % of both. */
function limitAlertFirm(int $extraMembers = 1): Organization
{
    $owner = User::factory()->create();
    $organization = Organization::query()->create(['name' => 'Despacho Límites', 'status' => 'active']);
    $organization->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    for ($i = 0; $i < $extraMembers; $i++) {
        $member = User::factory()->create();
        $organization->users()->attach($member->id, ['role' => 'member', 'status' => 'active', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }
    $organization->offices()->create(['code' => 'MAIN', 'name' => 'Sede', 'status' => 'active', 'is_primary' => true]);
    $priceId = (int) PlanPrice::query()->whereHas('plan', fn ($query) => $query->where('code', 'essential'))->where('billing_period', 'monthly')->value('id');
    app(TenantDatabaseContext::class)->runAsSystem(fn () => app(SubscriptionService::class)->create(null, $organization->id, $priceId, false, 0, 'recurrente'));

    return $organization;
}

function limitAlertNotices(int $organizationId): int
{
    return app(TenantDatabaseContext::class)->runAsSystem(fn (): int => DB::table('work_notifications')->where('organization_id', $organizationId)->where('title', 'like', 'Tu firma usa el%')->count());
}

test('al llegar al límite el dueño recibe un aviso por recurso, una sola vez por período', function () {
    Mail::fake();
    $organization = limitAlertFirm();

    $sent = app(LimitAlertService::class)->check($organization->id);

    expect($sent)->toBe(2)->and(limitAlertNotices($organization->id))->toBe(2);
    $titles = app(TenantDatabaseContext::class)->runAsSystem(fn () => DB::table('work_notifications')->where('organization_id', $organization->id)->pluck('title')->all());
    expect(collect($titles)->contains(fn (string $title): bool => str_contains($title, '100% de oficinas')))->toBeTrue()
        ->and(collect($titles)->contains(fn (string $title): bool => str_contains($title, '100% de usuarios')))->toBeTrue();

    expect(app(LimitAlertService::class)->check($organization->id))->toBe(0)->and(limitAlertNotices($organization->id))->toBe(2);
});

test('una firma con holgura no recibe avisos', function () {
    Mail::fake();
    $organization = limitAlertFirm(extraMembers: 0);
    DB::table('offices')->where('organization_id', $organization->id)->update(['deleted_at' => now()]);

    expect(app(LimitAlertService::class)->check($organization->id))->toBe(0);
});

test('la tarea diaria recorre las firmas con plan vigente', function () {
    Mail::fake();
    $organization = limitAlertFirm();

    $this->artisan('plans:limit-alerts')->expectsOutputToContain('Avisos de límite enviados: 2')->assertSuccessful();

    expect(limitAlertNotices($organization->id))->toBe(2);
});
