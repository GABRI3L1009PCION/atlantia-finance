<?php

use App\Models\User;
use App\Support\ServiceHours;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['service_hours.enabled' => true, 'service_hours.force_closed' => false, 'service_hours.timezone' => 'America/Guatemala', 'service_hours.opens_at' => '06:00', 'service_hours.closes_at' => '23:00']);
});

function guatemalaAt(string $time): void
{
    test()->travelTo(CarbonImmutable::parse('2026-10-12 '.$time, 'America/Guatemala'));
}

test('the platform is open between the opening and closing time in the Guatemala time zone', function () {
    guatemalaAt('05:59');
    expect(ServiceHours::isOpen())->toBeFalse();

    guatemalaAt('06:00');
    expect(ServiceHours::isOpen())->toBeTrue();

    guatemalaAt('22:59');
    expect(ServiceHours::isOpen())->toBeTrue();

    guatemalaAt('23:00');
    expect(ServiceHours::isOpen())->toBeFalse();
});

test('out of hours visitors get the after-hours page with the schedule and a retry hint', function () {
    guatemalaAt('23:30');

    $this->get('/')->assertStatus(503)->assertHeader('Retry-After')
        ->assertInertia(fn (Assert $page) => $page->component('service-hours')
            ->where('hours.opens_label', '6:00 AM')->where('hours.closes_label', '11:00 PM')
            ->where('hours.next_opening_label', '6:00 AM')->where('hours.next_opening_day', 'mañana')
            ->where('hours.timezone_label', 'Hora de Guatemala (UTC-6)'));

    guatemalaAt('04:00');
    $this->get('/')->assertStatus(503)->assertInertia(fn (Assert $page) => $page->where('hours.next_opening_day', 'hoy'));
});

test('during service hours everything works as usual', function () {
    guatemalaAt('12:00');

    $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->component('welcome'));
});

test('the page is never shown when service hours are disabled', function () {
    config(['service_hours.enabled' => false]);
    guatemalaAt('23:30');

    $this->get('/')->assertOk();
});

test('a manual shutdown closes the platform at any hour', function () {
    config(['service_hours.force_closed' => true]);
    guatemalaAt('12:00');

    $this->get('/')->assertStatus(503)->assertInertia(fn (Assert $page) => $page->component('service-hours'));
});

test('health checks, the sign-in flow, the API and webhooks stay reachable out of hours', function () {
    guatemalaAt('23:30');

    $this->get('/up')->assertOk();
    $this->get('/login')->assertOk();
    $this->getJson('/api/v1/companies')->assertStatus(401);
    expect($this->post('/webhooks/recurrente', [])->status())->not->toBe(503);
});

test('Inertia visits are sent to a full page load', function () {
    guatemalaAt('23:30');

    $this->actingAs(User::factory()->create())->withHeader('X-Inertia', 'true')->get('/dashboard')->assertStatus(409)->assertHeader('X-Inertia-Location');
});

test('JSON clients get a 503 answer with the opening time', function () {
    guatemalaAt('23:30');

    $this->actingAs(User::factory()->create())->getJson('/dashboard')->assertStatus(503)->assertJsonStructure(['message', 'opens_at']);
});

test('Atlantia staff can keep working out of hours', function () {
    guatemalaAt('23:30');

    $this->actingAs(ccOwner())->get('/control-center')->assertOk();
});
