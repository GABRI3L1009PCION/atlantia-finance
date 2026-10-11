<?php

use App\Models\Organization;
use App\Models\User;
use App\Services\WorkManagement\WorkAuthorizationService;
use Illuminate\Support\Facades\DB;

test('role presets are written once and only rewritten when the persisted state drifts', function () {
    $org = Organization::create(['name' => 'Firma Permisos', 'status' => 'active']);
    $owner = User::factory()->create();
    $org->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    app(WorkAuthorizationService::class)->ensureDefaults($org);
    $roleId = (int) DB::table('workspace_roles')->where('organization_id', $org->id)->where('code', 'auditor')->value('id');
    $grants = DB::table('workspace_role_permissions')->where('workspace_role_id', $roleId)->count();
    expect($grants)->toBeGreaterThan(0);

    // Nothing changed: a new request only reads the fingerprint.
    DB::flushQueryLog();
    DB::enableQueryLog();
    app(WorkAuthorizationService::class)->ensureDefaults($org);
    $writes = collect(DB::getQueryLog())->filter(fn (array $query): bool => preg_match('/^\s*(insert|update|delete)/i', $query['query']) === 1)->count();
    expect($writes)->toBe(0)->and(count(DB::getQueryLog()))->toBeLessThan(20);

    // A removed preset permission is restored on the next request.
    DB::table('workspace_role_permissions')->where('workspace_role_id', $roleId)->limit(1)->delete();
    app(WorkAuthorizationService::class)->ensureDefaults($org);
    expect(DB::table('workspace_role_permissions')->where('workspace_role_id', $roleId)->count())->toBe($grants);

    // A new owner receives the director role.
    $partner = User::factory()->create();
    $org->users()->attach($partner->id, ['role' => 'owner', 'status' => 'active']);
    app(WorkAuthorizationService::class)->ensureDefaults($org);
    expect(DB::table('user_role_assignments')->where('organization_id', $org->id)->where('user_id', $partner->id)->where('status', 'active')->exists())->toBeTrue();
});
