<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\User;
use App\Services\Tenancy\TenantDatabaseContext;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records sign-ins for the security center (never the password) and the last sign-in of platform staff.
 */
class SignInRecorder
{
    public function __construct(private TenantDatabaseContext $tenancy, private PlatformAuthorization $authorization) {}

    public function succeeded(Login $event): void
    {
        $user = $event->user instanceof User ? $event->user : null;
        $this->write(['email' => $user?->email, 'user_id' => $user?->id, 'succeeded' => true]);
        if ($user !== null && $this->authorization->isStaff($user)) {
            $this->tenancy->runAsSystem(fn () => DB::table('platform_staff')->where('user_id', $user->id)->update(['last_login_at' => now(), 'updated_at' => now()]));
        }
    }

    public function failed(Failed $event): void
    {
        $email = (string) ($event->credentials['email'] ?? '');
        $this->write(['email' => $email === '' ? null : mb_substr($email, 0, 255), 'user_id' => $event->user instanceof User ? $event->user->id : null, 'succeeded' => false]);
    }

    /**
     * Security-relevant account events go to the application log with who, from where and what, never secrets, codes
     * or passwords.
     */
    public function security(string $event, ?User $user, ?string $email = null): void
    {
        Log::notice('security.'.$event, [
            'user_id' => $user?->id,
            'email' => $user->email ?? ($email === null || $email === '' ? null : mb_substr($email, 0, 255)),
            'ip' => request()->ip(),
            'user_agent' => mb_substr((string) request()->userAgent(), 0, 255),
        ]);
    }

    /** @param array<string, mixed> $values */
    private function write(array $values): void
    {
        try {
            $this->tenancy->runAsSystem(fn () => DB::table('login_attempts')->insert([...$values, 'ip' => request()->ip(), 'user_agent' => mb_substr((string) request()->userAgent(), 0, 255), 'created_at' => now()]));
        } catch (Throwable $exception) {
            Log::debug('Sign-in not recorded', ['error' => $exception->getMessage()]);
        }
    }
}
