<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

test('crea el primer administrador del Centro de Control con contraseña temporal y verificación en dos pasos', function () {
    $this->artisan('platform:create-admin', ['email' => 'Dueno@Atlantia.test', 'name' => 'Dueño Atlantia'])
        ->expectsOutputToContain('Administrador creado: dueno@atlantia.test')
        ->assertSuccessful();

    $user = User::query()->where('email', 'dueno@atlantia.test')->firstOrFail();
    expect($user->must_change_password)->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('AtlantiaDemo!2026', $user->password))->toBeFalse();
    $staff = DB::table('platform_staff')->where('user_id', $user->id)->first();
    expect($staff->role)->toBe('saas_owner')->and((bool) $staff->require_two_factor)->toBeTrue();
});

test('no pisa a un usuario existente ni acepta datos inválidos', function () {
    User::factory()->create(['email' => 'ya@existe.test']);

    $this->artisan('platform:create-admin', ['email' => 'ya@existe.test', 'name' => 'Otro'])->assertFailed();
    $this->artisan('platform:create-admin', ['email' => 'no-es-correo', 'name' => 'Otro'])->assertFailed();

    expect(DB::table('platform_staff')->count())->toBe(0);
});
