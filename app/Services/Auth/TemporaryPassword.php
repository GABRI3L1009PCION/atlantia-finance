<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\Organization;
use App\Models\User;
use App\Services\Tenancy\TenantDatabaseContext;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Temporary passwords handed out by an office administrator: readable, random, valid for a few days, replaced at sign-in. */
class TemporaryPassword
{
    public const DAYS = 7;

    /** Twelve readable characters (no look-alikes) with upper case, lower case and digits, grouped as XXXX-XXXX-XXXX. */
    public static function generate(): string
    {
        $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnpqrstuvwxyz', '23456789'];
        $chars = array_map(fn (string $set): string => $set[random_int(0, strlen($set) - 1)], $sets);
        $all = implode('', $sets);
        while (count($chars) < 12) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }
        shuffle($chars);

        return implode('-', str_split(implode('', $chars), 4));
    }

    /**
     * Seals credentials before they are flashed to the session so the password never sits in clear text in the session
     * store; the administrator's next page opens them once.
     *
     * @param  array<string, string>  $credentials
     */
    public static function seal(array $credentials): string
    {
        return Crypt::encryptString(json_encode($credentials, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, string>|null */
    public static function open(mixed $sealed): ?array
    {
        if (! is_string($sealed) || $sealed === '') {
            return null;
        }
        try {
            $data = json_decode(Crypt::decryptString($sealed), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * A user is one global account: resetting its password from one firm would also open its other firms. Only accounts
     * that live in this single firm (and are not platform staff) may be reset by that firm's administrators.
     */
    public static function assertIssuableBy(Organization $organization, User $target): void
    {
        // Memberships in other firms are invisible from inside one firm (row-level security), so read them as the system.
        [$firms, $isStaff] = app(TenantDatabaseContext::class)->runAsSystem(fn (): array => [
            DB::table('organization_user')->where('user_id', $target->id)->where('status', 'active')->pluck('organization_id')->map(fn ($id): int => (int) $id)->all(),
            DB::table('platform_staff')->where('user_id', $target->id)->exists(),
        ]);
        abort_if($isStaff || $firms !== [(int) $organization->id], 422, 'Esta cuenta pertenece a más de una firma o al personal de la plataforma; su contraseña no se puede restablecer desde aquí.');
    }

    /** Replaces the user's password with a new temporary one, closes their open sessions and forces the change at sign-in. */
    public static function issue(User $user): string
    {
        $password = self::generate();
        $user->forceFill(['password' => $password, 'must_change_password' => true, 'temporary_password_expires_at' => now()->addDays(self::DAYS)])->save();
        DB::table('sessions')->where('user_id', $user->id)->delete();

        return $password;
    }
}
