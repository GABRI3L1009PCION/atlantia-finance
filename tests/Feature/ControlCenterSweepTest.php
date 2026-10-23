<?php

use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(PlanCatalogSeeder::class);
    Notification::fake();
});

test('every Control Center screen opens for the SaaS owner without errors', function () {
    $owner = ccOwner();
    $organization = ccOrganization($owner, 'professional', true);
    $subscription = (int) DB::table('subscriptions')->where('organization_id', $organization)->value('id');
    $this->actingAs($owner);
    $failures = [];

    $paths = ['/control-center', '/control-center/organizations', '/control-center/organizations/'.$organization, '/control-center/offices', '/control-center/plans', '/control-center/subscriptions',
        '/control-center/subscriptions/'.$subscription.'/preview', '/control-center/billing', '/control-center/payments', '/control-center/usage', '/control-center/features', '/control-center/feature-flags',
        '/control-center/incidents', '/control-center/support/tickets', '/control-center/support-access', '/control-center/security', '/control-center/saas-users', '/control-center/integrations',
        '/control-center/jobs', '/control-center/services', '/control-center/logs', '/control-center/audit', '/control-center/settings', '/control-center/luzma', '/control-center/luzma/tools', '/control-center/luzma/usage', '/control-center/luzma/neural', '/control-center/legal'];
    foreach ($paths as $path) {
        $status = $this->get($path)->getStatusCode();
        if (! in_array($status, [200, 302], true)) {
            $failures[$path] = $status;
        }
    }

    expect($failures)->toBe([]);
});
