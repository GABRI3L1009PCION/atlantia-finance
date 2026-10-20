<?php

use App\Services\Platform\ErrorPageResponder;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(PlanCatalogSeeder::class);
    $this->withoutMiddleware(ThrottleRequests::class);
});

/**
 * Every address a signed-in user could type without parameters.
 *
 * @return list<string>
 */
function typableAddresses(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (LaravelRoute $route) => in_array('GET', $route->methods(), true) && ! str_contains($route->uri(), '{') && in_array('auth', $route->gatherMiddleware(), true))
        ->map(fn (LaravelRoute $route) => '/'.ltrim($route->uri(), '/'))
        ->unique()->values()->all();
}

test('a client portal contact can only open the client portal and their own settings', function () {
    $f = clientFixture();
    $allowed = ['/client', '/settings', '/dashboard', '/logout', '/verify-email', '/email/verify', '/confirm-password', '/two-factor', '/user', '/luzma', '/communication', '/work/notifications', '/billing'];
    $leaks = [];

    foreach (typableAddresses() as $path) {
        if (collect($allowed)->contains(fn (string $prefix) => $path === $prefix || str_starts_with($path, $prefix.'/'))) {
            continue;
        }
        $status = $this->actingAs($f['client'])->get($path)->getStatusCode();
        if ($status === 200) {
            $leaks[] = $path;
        }
    }

    expect($leaks)->toBe([]);
})->skip(! class_exists(ErrorPageResponder::class));

test('every screen of the client portal opens for a client contact without errors', function () {
    $f = clientFixture();
    $this->actingAs($f['client']);
    $failures = [];

    foreach (['/client', '/client/pending', '/client/documents', '/client/document-requests', '/client/approvals', '/client/messages', '/client/calendar', '/client/obligations', '/client/reports',
        '/client/fel', '/client/company', '/client/history', '/client/preferences'] as $path) {
        $status = $this->get($path)->getStatusCode();
        if ($status !== 200) {
            $failures[$path] = $status;
        }
    }

    expect($failures)->toBe([]);
});

test('an office accountant cannot open the platform control center, the firm administration or the supervisor and assistant panels', function () {
    $f = clientFixture();
    $leaks = [];

    foreach (typableAddresses() as $path) {
        if (! preg_match('#^/(control-center|office|supervisor|operations|assistant|work/audit)(/|$)#', $path) || $path === '/office/search') {
            continue;
        }
        if ($this->actingAs($f['accountant'])->get($path)->getStatusCode() === 200) {
            $leaks[] = $path;
        }
    }

    expect($leaks)->toBe([]);
});
