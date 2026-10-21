<?php

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\LegalDocumentSeeder;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    Cache::flush();
    $this->seed(PlanCatalogSeeder::class);
    $this->seed(LegalDocumentSeeder::class);
});

function legalFirmUser(string $role): User
{
    $user = User::factory()->create();
    $organization = Organization::create(['name' => 'Firma Legal '.$user->id, 'status' => 'active']);
    $organization->users()->attach($user->id, ['role' => $role, 'status' => 'active']);

    return $user;
}

test('el seeder carga los tres documentos y las páginas públicas los muestran sin iniciar sesión', function () {
    expect(DB::table('legal_documents')->where('status', 'active')->pluck('slug')->sort()->values()->all())->toBe(['data', 'privacy', 'terms']);

    $this->get('/terminos')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('legal/show')
        ->where('document.label', 'Términos y Condiciones')
        ->where('document.version', 1)
        ->where('document.body', fn ($body) => str_contains((string) $body, 'Gabriel Antonio Picon Escalante') && str_contains((string) $body, '## 21. Disposiciones generales y contacto')));
    $this->get('/privacidad')->assertOk()->assertInertia(fn (Assert $page) => $page->component('legal/show')->where('document.slug', 'privacy'));
    $this->get('/tratamiento-de-datos')->assertOk()->assertInertia(fn (Assert $page) => $page->component('legal/show')->where('document.slug', 'data'));
});

test('correr el seeder otra vez no pisa una versión editada', function () {
    $staff = ccOwner();
    $this->actingAs($staff)->post('/control-center/legal/terms', ['title' => 'Términos', 'body' => str_repeat('Texto nuevo. ', 10)])->assertRedirect();

    $this->seed(LegalDocumentSeeder::class);

    expect(DB::table('legal_documents')->where('slug', 'terms')->count())->toBe(2)
        ->and(DB::table('legal_documents')->where('slug', 'terms')->where('status', 'active')->value('version'))->toBe(2);
});

test('el registro guarda la aceptación de cada documento con versión e IP', function () {
    $this->post(route('register.store'), ['name' => 'Titular Legal', 'email' => 'legal@example.com', 'password' => 'una-clave-segura-2026', 'password_confirmation' => 'una-clave-segura-2026',
        'plan' => 'professional', 'billing_period' => 'monthly', 'terms' => true])->assertRedirect();

    $user = User::where('email', 'legal@example.com')->firstOrFail();
    $rows = DB::table('legal_acceptances')->where('user_id', $user->id)->get();
    expect($rows)->toHaveCount(3)
        ->and($rows->pluck('version')->unique()->all())->toBe([1])
        ->and($rows->pluck('context')->unique()->all())->toBe(['register'])
        ->and($rows->first()->ip)->not->toBeNull();
});

test('el dueño de una firma debe aceptar los documentos y después sigue normal', function () {
    $owner = legalFirmUser('owner');

    $this->actingAs($owner)->get(route('dashboard'))->assertRedirect(route('legal.accept'));
    $this->get(route('legal.accept'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('legal/accept')->has('documents', 3));
    $this->post(route('legal.store'), [])->assertSessionHasErrors('accepted');
    $this->get('/terminos')->assertOk();

    $this->post(route('legal.store'), ['accepted' => true])->assertRedirect();
    expect(DB::table('legal_acceptances')->where('user_id', $owner->id)->where('context', 'login')->count())->toBe(3);
    $this->get(route('legal.accept'))->assertRedirect(route('dashboard'));
});

test('un colaborador que no es dueño no queda bloqueado', function () {
    $member = legalFirmUser('member');

    $this->actingAs($member)->get(route('legal.accept'))->assertOk();
    $response = $this->get(route('dashboard'));
    expect($response->headers->get('Location'))->not->toContain('legal/aceptar');
});

test('publicar una versión nueva desde el Centro de Control vuelve a pedir la aceptación', function () {
    $owner = legalFirmUser('owner');
    $this->actingAs($owner)->post(route('legal.store'), ['accepted' => true])->assertRedirect();
    $this->get(route('legal.accept'))->assertRedirect(route('dashboard'));

    $staff = ccOwner();
    $this->actingAs($staff)->get('/control-center/legal')->assertOk()->assertInertia(fn (Assert $page) => $page->component('control-center/legal')->has('documents', 3));
    $this->post('/control-center/legal/privacy', ['title' => 'Política de Privacidad', 'body' => str_repeat('Texto actualizado de la política. ', 5)])->assertRedirect();

    expect(DB::table('legal_documents')->where('slug', 'privacy')->where('status', 'superseded')->value('version'))->toBe(1)
        ->and(DB::table('platform_audit_events')->where('action', 'legal.published')->exists())->toBeTrue();

    $this->actingAs($owner)->get(route('dashboard'))->assertRedirect(route('legal.accept'));
    $this->get(route('legal.accept'))->assertInertia(fn (Assert $page) => $page->has('documents', 1)->where('documents.0.slug', 'privacy')->where('documents.0.version', 2));
});

test('una firma cliente no puede editar los documentos legales', function () {
    $owner = legalFirmUser('owner');
    $this->actingAs($owner)->post(route('legal.store'), ['accepted' => true]);

    expect($this->post('/control-center/legal/terms', ['title' => 'X', 'body' => str_repeat('Hackeado. ', 10)])->status())->toBeIn([403, 404]);
    expect(DB::table('legal_documents')->where('slug', 'terms')->count())->toBe(1);
});
