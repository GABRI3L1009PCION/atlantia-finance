<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
});

test('a signed-in user who types a URL outside their role sees the friendly unauthorized page and the attempt is logged', function () {
    Log::spy();
    $user = User::factory()->create();

    $this->actingAs($user)->get('/control-center')
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page->component('error')->where('status', 403)->where('detail', 'Solo el personal de Atlantia Systems puede entrar al Control Center.')->has('reference'));

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $message === 'security.access_denied' && $context['user_id'] === $user->id && $context['path'] === '/control-center')->once();
});

test('unknown addresses show a friendly not found page without leaking internals', function () {
    $this->actingAs(User::factory()->create())->get('/esta-ruta-no-existe')
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page->component('error')->where('status', 404)->where('detail', null));
});

test('JSON clients keep receiving JSON errors instead of the page', function () {
    $this->actingAs(User::factory()->create())->getJson('/control-center')->assertForbidden()->assertJsonStructure(['message']);
    $this->getJson('/api/v1/no-existe')->assertNotFound();
});

test('Inertia visits that hit a forbidden route are sent back to the user panel with a notice', function () {
    $this->actingAs(User::factory()->create())
        ->get('/control-center', ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()), 'X-Requested-With' => 'XMLHttpRequest'])
        ->assertRedirect('/dashboard')
        ->assertSessionHas('security_notice', 'Solo el personal de Atlantia Systems puede entrar al Control Center.');
});

test('typing an address the user cannot use in the browser sends them back to their panel instead of an error page', function () {
    Log::spy();
    $user = User::factory()->create();

    $this->actingAs($user)->get('/control-center', ['Sec-Fetch-Dest' => 'document'])
        ->assertRedirect('/dashboard')
        ->assertSessionHas('security_notice');
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $message === 'security.access_denied' && $context['user_id'] === $user->id)->once();

    // A second refusal right after shows the error page, so a panel that is itself refused can never loop.
    $this->actingAs($user)->get('/control-center', ['Sec-Fetch-Dest' => 'document'])->assertForbidden();
});

test('unknown addresses typed in the browser also go back to the panel, and visitors without a session keep the error page', function () {
    $this->actingAs(User::factory()->create())->get('/esta-ruta-no-existe', ['Sec-Fetch-Dest' => 'document'])->assertRedirect('/dashboard');
    $this->get('/esta-ruta-no-existe-tampoco', ['Sec-Fetch-Dest' => 'document'])->assertNotFound();
});
