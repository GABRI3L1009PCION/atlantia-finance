<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Platform\SignInRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * After a password change every other session of the account ends and "remember me" cookies stop working, so whoever
 * knew the old password loses access; the session that made the change (if any) is kept.
 */
class SignOutElsewhere
{
    public static function after(User $user, ?string $keepSessionId = null): void
    {
        $user->forceFill(['remember_token' => Str::random(60)])->save();

        DB::table('sessions')
            ->where('user_id', $user->id)
            ->when($keepSessionId !== null, fn ($query) => $query->where('id', '!=', $keepSessionId))
            ->delete();

        app(SignInRecorder::class)->security('password_changed', $user);
    }
}
