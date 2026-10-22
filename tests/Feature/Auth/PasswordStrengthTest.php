<?php

use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

beforeEach(function () {
    $this->seed(PlanCatalogSeeder::class);
    // The tests keep the lenient default; outside them (local, staging, production) the strong rule applies.
    $this->app['env'] = 'local';
    // Outside the testing environment Laravel no longer skips the CSRF check on its own.
    $this->withoutMiddleware(PreventRequestForgery::class);
});

function registrationWith(string $password): array
{
    return ['name' => 'Persona Titular', 'organization_name' => 'Despacho de Prueba', 'email' => 'fuerte@example.com', 'password' => $password, 'password_confirmation' => $password,
        'plan' => 'professional', 'billing_period' => 'monthly', 'terms' => true];
}

test('una contraseña que no cumple los requisitos se rechaza', function (string $weak) {
    $this->post(route('register.store'), registrationWith($weak))->assertSessionHasErrors('password');

    $this->assertGuest();
})->with([
    'muy corta' => 'Abc123!x',
    'sin símbolo' => 'Abcdefghijkl1234',
    'sin número' => 'Abcdefghijkl!!!!',
    'sin mayúscula' => 'abcdefghijkl123!',
    'sin minúscula' => 'ABCDEFGHIJKL123!',
]);

test('una contraseña con 12 caracteres, mayúscula, minúscula, número y símbolo se acepta', function () {
    $this->post(route('register.store'), registrationWith('Despacho-2026!ok'))->assertSessionHasNoErrors();

    $this->assertAuthenticated();
});
