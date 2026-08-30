<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * @property int $organization_id
 * @property int $user_id
 * @property string $role
 * @property string $status
 * @property Carbon|null $joined_at
 * @property int|null $invited_by
 */
class OrganizationUser extends Pivot
{
    public $incrementing = false;

    protected $table = 'organization_user';

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
        ];
    }
}
