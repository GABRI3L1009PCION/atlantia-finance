<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Atlantia Systems staff (never tenant users). Assigning SAAS_OWNER requires an owner; the last active SAAS_OWNER can
 * never be demoted or deactivated; nobody can change their own SaaS role.
 */
class SaaSUserService
{
    public function __construct(private PlatformAuditService $audit, private PlatformAuthorization $authorization) {}

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return array_values(DB::table('platform_staff')->join('users', 'users.id', '=', 'platform_staff.user_id')->orderBy('users.name')
            ->get(['platform_staff.*', 'users.name', 'users.email', 'users.two_factor_confirmed_at'])
            ->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'user_id' => (int) $row->user_id, 'name' => (string) $row->name, 'email' => (string) $row->email,
                'role' => (string) $row->role, 'role_label' => PlatformAuthorization::ROLES[$row->role]['label'] ?? $row->role, 'status' => (string) $row->status,
                'two_factor' => $row->two_factor_confirmed_at !== null, 'require_two_factor' => (bool) $row->require_two_factor,
                'last_login_at' => $row->last_login_at === null ? null : Carbon::parse($row->last_login_at)->toIso8601String(),
                'sessions' => DB::table('sessions')->where('user_id', $row->user_id)->count()])->all());
    }

    /** Creates (or promotes an existing account to) platform staff and sends a password-set link to new accounts. */
    public function create(User $actor, string $name, string $email, string $role, string $reason): int
    {
        $this->assertRole($actor, $role);
        $email = Str::lower(trim($email));
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();
        if ($user !== null && DB::table('organization_user')->where('user_id', $user->id)->where('status', 'active')->exists()) {
            throw ValidationException::withMessages(['email' => 'Ese correo pertenece a un usuario de una organización cliente. El personal SaaS usa cuentas propias de Atlantia Systems.']);
        }
        if ($user !== null && DB::table('platform_staff')->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['email' => 'Ese usuario ya es personal SaaS.']);
        }

        return DB::transaction(function () use ($actor, $name, $email, $role, $reason, $user): int {
            $isNew = $user === null;
            $user ??= User::query()->create(['name' => $name, 'email' => $email, 'password' => Str::password(32)]);
            if ($isNew) {
                $user->markEmailAsVerified();
            }
            $id = DB::table('platform_staff')->insertGetId(['user_id' => $user->id, 'role' => $role, 'status' => 'active', 'require_two_factor' => true, 'invited_by' => $actor->id,
                'created_at' => now(), 'updated_at' => now()]);
            if ($isNew) {
                Password::broker()->sendResetLink(['email' => $email]);
            }
            $this->audit->record($actor, 'saas_user.created', 'platform_staff', $id, null, ['email' => $email, 'role' => $role], $reason, null, [], true);

            return $id;
        });
    }

    public function changeRole(User $actor, int $staffId, string $role, string $reason): void
    {
        $staff = DB::table('platform_staff')->where('id', $staffId)->first() ?? abort(404);
        if ((int) $staff->user_id === $actor->id) {
            throw ValidationException::withMessages(['role' => 'No puedes cambiar tu propio rol SaaS.']);
        }
        $this->assertRole($actor, $role);
        if ($staff->role === 'saas_owner' && $role !== 'saas_owner') {
            $this->assertNotLastOwner($staff);
        }
        DB::table('platform_staff')->where('id', $staffId)->update(['role' => $role, 'updated_at' => now()]);
        $this->authorization->forget((int) $staff->user_id);
        DB::table('sessions')->where('user_id', $staff->user_id)->delete();
        $this->audit->record($actor, 'saas_role.changed', 'platform_staff', $staffId, ['role' => $staff->role], ['role' => $role], $reason, null, [], true);
    }

    public function setStatus(User $actor, int $staffId, bool $active, string $reason): void
    {
        $staff = DB::table('platform_staff')->where('id', $staffId)->first() ?? abort(404);
        if ((int) $staff->user_id === $actor->id) {
            throw ValidationException::withMessages(['status' => 'No puedes desactivar tu propia cuenta.']);
        }
        if (! $active && $staff->role === 'saas_owner') {
            $this->assertNotLastOwner($staff);
        }
        if ($staff->role === 'saas_owner' && ! $this->authorization->can($actor, '*') && $this->authorization->role($actor) !== 'saas_owner') {
            throw ValidationException::withMessages(['status' => 'Solo un SaaS Owner puede activar o desactivar a otro SaaS Owner.']);
        }
        DB::table('platform_staff')->where('id', $staffId)->update(['status' => $active ? 'active' : 'inactive', 'deactivated_at' => $active ? null : now(), 'updated_at' => now()]);
        $this->authorization->forget((int) $staff->user_id);
        if (! $active) {
            DB::table('sessions')->where('user_id', $staff->user_id)->delete();
        }
        $this->audit->record($actor, $active ? 'saas_user.activated' : 'saas_user.deactivated', 'platform_staff', $staffId, ['status' => $staff->status], ['status' => $active ? 'active' : 'inactive'], $reason, null, [], true);
    }

    public function setTwoFactorRequirement(User $actor, int $staffId, bool $required, string $reason): void
    {
        $staff = DB::table('platform_staff')->where('id', $staffId)->first() ?? abort(404);
        DB::table('platform_staff')->where('id', $staffId)->update(['require_two_factor' => $required, 'updated_at' => now()]);
        $this->audit->record($actor, 'saas_user.two_factor_policy', 'platform_staff', $staffId, ['require_two_factor' => (bool) $staff->require_two_factor], ['require_two_factor' => $required], $reason, null, [], true);
    }

    public function revokeSessions(User $actor, int $staffId, string $reason): int
    {
        $staff = DB::table('platform_staff')->where('id', $staffId)->first() ?? abort(404);
        $count = DB::table('sessions')->where('user_id', $staff->user_id)->delete();
        $this->audit->record($actor, 'security.session_revoked', 'platform_staff', $staffId, null, ['revoked' => $count], $reason, null, [], true);

        return $count;
    }

    private function assertRole(User $actor, string $role): void
    {
        if (! isset(PlatformAuthorization::ROLES[$role])) {
            throw ValidationException::withMessages(['role' => 'Rol SaaS inválido.']);
        }
        if ($role === 'saas_owner' && $this->authorization->role($actor) !== 'saas_owner') {
            throw ValidationException::withMessages(['role' => 'Solo un SaaS Owner puede asignar el rol SaaS Owner.']);
        }
    }

    private function assertNotLastOwner(stdClass $staff): void
    {
        $owners = DB::table('platform_staff')->where('role', 'saas_owner')->where('status', 'active')->where('id', '!=', $staff->id)->count();
        if ($owners === 0) {
            throw ValidationException::withMessages(['role' => 'Es el último SaaS Owner activo: asigna otro Owner antes de cambiarlo o desactivarlo.']);
        }
    }
}
