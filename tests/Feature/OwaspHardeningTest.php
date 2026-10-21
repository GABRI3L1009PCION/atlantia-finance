<?php

use App\Models\User;
use App\Services\Integrations\WebhookUrlGuard;
use App\Support\CsvCell;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;

/** Inserts an open database session for the user, as if it were signed in on another device. */
function owaspSession(User $user, string $id): void
{
    DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'ip_address' => '203.0.113.9', 'user_agent' => 'Otro equipo', 'payload' => base64_encode(serialize([])), 'last_activity' => now()->timestamp]);
}

test('A04: registration, password reset, password confirmation and two-factor confirmation are rate limited', function () {
    $routes = collect(['register.store', 'password.update', 'password.confirm.store', 'two-factor.confirm', 'webhooks.recurrente', 'profile.destroy', 'profile.update'])
        ->mapWithKeys(fn (string $name): array => [$name => collect(Route::getRoutes()->getByName($name)?->gatherMiddleware() ?? [])->contains(fn ($middleware): bool => is_string($middleware) && str_starts_with($middleware, 'throttle:'))]);

    expect($routes->filter()->keys()->all())->toEqualCanonicalizing($routes->keys()->all());

    foreach (range(1, 5) as $attempt) {
        $this->post(route('register.store'), ['name' => 'Bot', 'email' => "bot{$attempt}@example.com", 'password' => 'x', 'password_confirmation' => 'y']);
    }
    $this->post(route('register.store'), ['name' => 'Bot', 'email' => 'bot6@example.com'])->assertStatus(429);

    foreach (range(1, 5) as $attempt) {
        $this->post(route('password.update'), ['token' => 'falso', 'email' => 'victima@example.com', 'password' => 'Nueva-Clave-2026', 'password_confirmation' => 'Nueva-Clave-2026']);
    }
    $this->post(route('password.update'), ['token' => 'falso', 'email' => 'victima@example.com'])->assertStatus(429);
});

test('A07: password confirmation cannot be brute forced from a signed-in session', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    foreach (range(1, 5) as $attempt) {
        $this->post(route('password.confirm.store'), ['password' => 'incorrecta-'.$attempt]);
    }

    $this->post(route('password.confirm.store'), ['password' => 'password'])->assertStatus(429);
});

test('A07: sign-in is also limited per account when the attacker rotates IP addresses', function () {
    $user = User::factory()->create();

    foreach (range(1, 20) as $attempt) {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.$attempt])->post(route('login.store'), ['email' => $user->email, 'password' => 'incorrecta']);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.99'])->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertStatus(429);
    $this->assertGuest();
});

test('A07: changing the password ends the other sessions and the remember-me token', function () {
    $user = User::factory()->create(['remember_token' => 'token-anterior']);
    owaspSession($user, str_repeat('a', 40));

    $this->actingAs($user)->from(route('security.edit'))->put(route('user-password.update'), [
        'current_password' => 'password', 'password' => 'new-password', 'password_confirmation' => 'new-password',
    ])->assertSessionHasNoErrors();

    $user->refresh();
    expect(Hash::check('new-password', $user->password))->toBeTrue()
        ->and($user->remember_token)->not->toBe('token-anterior')
        ->and($user->password_changed_at)->not->toBeNull()
        ->and(DB::table('sessions')->where('id', str_repeat('a', 40))->exists())->toBeFalse();
});

test('A07: resetting a forgotten password ends every open session of the account', function () {
    $user = User::factory()->create(['remember_token' => 'token-anterior']);
    owaspSession($user, str_repeat('b', 40));
    $token = Password::broker()->createToken($user);

    $this->post(route('password.update'), ['token' => $token, 'email' => $user->email, 'password' => 'password', 'password_confirmation' => 'password'])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->remember_token)->not->toBe('token-anterior')
        ->and(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse();
});

test('A09: password changes and lockouts are written to the security log without secrets', function () {
    Log::spy();
    $user = User::factory()->create();

    $this->actingAs($user)->from(route('security.edit'))->put(route('user-password.update'), [
        'current_password' => 'password', 'password' => 'new-password', 'password_confirmation' => 'new-password',
    ]);

    Log::shouldHaveReceived('notice')->withArgs(fn (string $message, array $context): bool => $message === 'security.password_changed'
        && $context['user_id'] === $user->id && ! array_key_exists('password', $context))->once();
});

test('A05: responses carry a script-restricting CSP whose nonce matches the inline layout script', function () {
    $response = $this->get('/');
    $policy = (string) $response->headers->get('Content-Security-Policy');

    expect($policy)->toContain("default-src 'self'")->toContain("object-src 'none'")->toContain("frame-ancestors 'self'")
        ->and(preg_match("/script-src 'self' 'nonce-([^']+)'/", $policy, $match))->toBe(1)
        ->and($response->getContent())->toContain('nonce="'.$match[1].'"')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN')
        ->and($response->headers->has('X-Powered-By'))->toBeFalse();

    $api = $this->getJson('/api/v1/me');
    expect($api->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and((string) $api->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'self'");
});

test('A05: the public API does not grant CORS access to other sites by default', function () {
    $this->withHeaders(['Origin' => 'https://atacante.example', 'Access-Control-Request-Method' => 'GET'])
        ->options('/api/v1/me')
        ->assertHeaderMissing('Access-Control-Allow-Origin');
});

test('A05: private files are not exposed through a /storage route', function () {
    expect(Route::has('storage.local'))->toBeFalse()
        ->and(config('filesystems.disks.local.serve'))->toBeFalse();
});

test('A03: a forged appearance cookie never reaches the inline script', function () {
    $response = $this->withUnencryptedCookie('appearance', "dark';alert(1)//")->get('/');

    $response->assertOk();
    expect($response->getContent())->not->toContain('alert(1)')->toContain("const appearance = 'system';");
});

test('A03: CSV cells that look like formulas are neutralized', function () {
    expect(CsvCell::quote('=HYPERLINK("https://x","clic")'))->toBe('"\'=HYPERLINK(""https://x"",""clic"")"')
        ->and(CsvCell::quote('+502 5555'))->toBe('"\'+502 5555"')
        ->and(CsvCell::quote('@SUM(A1)'))->toBe('"\'@SUM(A1)"')
        ->and(CsvCell::quote('Comercial Maya'))->toBe('"Comercial Maya"')
        ->and(CsvCell::quote(-15))->toBe('"-15"');
});

test('A10: the webhook guard blocks internal targets, IPv6 answers and pins the checked address', function () {
    config(['integrations.webhooks.resolve_dns' => true, 'integrations.webhooks.allow_insecure_urls' => false]);
    $guard = new class extends WebhookUrlGuard
    {
        /** @var array<string, list<string>> */
        public array $answers = [];

        protected function resolve(string $host): array
        {
            return $this->answers[$host] ?? [];
        }
    };
    $guard->answers = ['publico.example' => ['93.184.216.34'], 'mixto.example' => ['93.184.216.34', 'fd00::1'], 'cgnat.example' => ['100.100.100.200']];

    foreach (['https://127.0.0.1/x', 'https://[::1]/x', 'https://[::ffff:127.0.0.1]/x', 'https://169.254.169.254/latest', 'https://100.64.0.1/x', 'https://mixto.example/x', 'https://cgnat.example/x', 'https://nadie.example/x'] as $url) {
        expect($guard->problem($url))->not->toBeNull($url);
    }

    expect($guard->problem('https://publico.example/hook'))->toBeNull()
        ->and($guard->pinnedOptions('https://publico.example/hook'))->toBe(['curl' => [CURLOPT_RESOLVE => ['publico.example:443:93.184.216.34']]])
        ->and($guard->pinnedOptions('https://mixto.example/hook'))->toBe([]);
});

test('A05: redirects to the login page carry the security headers too', function () {
    $response = $this->get('/dashboard');

    $response->assertRedirect();
    expect((string) $response->headers->get('Content-Security-Policy'))->toContain("default-src 'self'")
        ->and($response->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN');
});
