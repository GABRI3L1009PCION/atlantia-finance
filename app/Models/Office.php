<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/** @property-read OfficeUser $pivot */
class Office extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'code',
        'name',
        'email',
        'phone',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'postal_code',
        'country_code',
        'timezone',
        'is_primary',
        'status',
    ];

    protected static function booted(): void
    {
        static::creating(function (Office $office): void {
            $office->public_id ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsToMany<User, $this, OfficeUser> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(OfficeUser::class)
            ->withPivot(['role', 'status', 'assigned_at'])
            ->withTimestamps();
    }
}
