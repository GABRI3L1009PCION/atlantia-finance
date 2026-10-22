<?php

namespace App\Models;

use App\Services\Auth\EmailVerificationCodeService;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property bool $must_change_password
 * @property bool $must_enroll_two_factor
 * @property Carbon|null $temporary_password_expires_at
 * @property Carbon|null $password_changed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read OrganizationUser|OfficeUser $pivot
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'current_organization_id'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'must_change_password' => 'boolean',
            'must_enroll_two_factor' => 'boolean',
            'temporary_password_expires_at' => 'datetime',
            'password_changed_at' => 'datetime',
        ];
    }

    public function sendEmailVerificationNotification(): void
    {
        app(EmailVerificationCodeService::class)->issue($this);
    }

    /** @return BelongsToMany<Organization, $this, OrganizationUser> */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class)
            ->using(OrganizationUser::class)
            ->withPivot([
                'role',
                'status',
                'joined_at',
                'invited_by',
            ])
            ->withTimestamps();
    }

    /** @return BelongsToMany<Office, $this, OfficeUser> */
    public function offices(): BelongsToMany
    {
        return $this->belongsToMany(Office::class)
            ->using(OfficeUser::class)
            ->withPivot([
                'role',
                'status',
                'assigned_at',
            ])
            ->withTimestamps();
    }
}
