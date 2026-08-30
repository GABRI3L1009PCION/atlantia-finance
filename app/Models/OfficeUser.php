<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * @property int $office_id
 * @property int $user_id
 * @property string $role
 * @property string $status
 * @property Carbon|null $assigned_at
 */
class OfficeUser extends Pivot
{
    public $incrementing = false;

    protected $table = 'office_user';

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
        ];
    }
}
