<?php

use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(PlanCatalogSeeder::class);
    Notification::fake();
});

test('every screen of the firm administration opens for the firm owner without errors', function () {
    [$owner] = multiOfficeFirm($this);
    $this->actingAs($owner);
    $failures = [];

    foreach (['/office/dashboard', '/office/executive-dashboard', '/office/setup', '/office/offices', '/office/companies', '/office/team', '/office/assignments', '/office/users', '/office/roles', '/office/users-roles',
        '/office/invitations', '/office/team-workload', '/office/workload', '/office/training', '/office/crm', '/office/firm-profile', '/office/audit', '/office/security', '/office/integrations',
        '/office/settings', '/office/subscription', '/office/indicators', '/office/compliance', '/office/risk-map', '/office/productivity', '/office/growth', '/office/projections', '/office/reports',
        '/office/revenue', '/office/unassigned-tasks', '/office/company-assignments'] as $path) {
        $response = $this->get($path);
        if (! in_array($response->getStatusCode(), [200, 302], true)) {
            $failures[$path] = $response->getStatusCode();
        }
    }

    expect($failures)->toBe([]);
});
