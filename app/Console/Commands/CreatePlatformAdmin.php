<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Auth\TemporaryPassword;
use App\Services\Tenancy\TenantDatabaseContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CreatePlatformAdmin extends Command
{
    protected $signature = 'platform:create-admin {email : Correo del administrador} {name : Nombre completo}';

    protected $description = 'Crea el primer administrador del Centro de Control con una contraseña temporal. Debe activar la verificación en dos pasos al entrar.';

    public function handle(TenantDatabaseContext $tenancy): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $name = trim((string) $this->argument('name'));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || $name === '') {
            $this->error('Indica un correo válido y un nombre.');

            return self::FAILURE;
        }

        $password = $tenancy->runAsSystem(function () use ($email, $name): ?string {
            if (User::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
                return null;
            }
            $password = TemporaryPassword::generate();
            $user = User::query()->create(['name' => $name, 'email' => $email, 'password' => $password]);
            $user->forceFill([
                'email_verified_at' => now(),
                'must_change_password' => true,
                'temporary_password_expires_at' => now()->addDays(TemporaryPassword::DAYS),
            ])->save();
            DB::table('platform_staff')->insert(['user_id' => $user->id, 'role' => 'saas_owner', 'status' => 'active', 'require_two_factor' => true, 'created_at' => now(), 'updated_at' => now()]);

            return $password;
        });

        if ($password === null) {
            $this->error('Ya existe un usuario con ese correo; no se creó nada.');

            return self::FAILURE;
        }

        $this->info("Administrador creado: {$email}");
        $this->line('Contraseña temporal (se muestra una sola vez, vence en '.TemporaryPassword::DAYS." días): {$password}");
        $this->line('Al entrar deberá cambiarla y activar la verificación en dos pasos.');

        return self::SUCCESS;
    }
}
