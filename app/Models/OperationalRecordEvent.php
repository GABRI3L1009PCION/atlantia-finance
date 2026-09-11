<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\OperationalRecordEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationalRecordEvent extends Model
{
    /** @use HasFactory<OperationalRecordEventFactory> */
    use HasFactory;

    protected $fillable = [
        'operational_record_id', 'company_id', 'user_id', 'action', 'from_status',
        'to_status', 'reason', 'before_values', 'after_values', 'idempotency_key', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'before_values' => 'array',
            'after_values' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<OperationalRecord, $this> */
    public function record(): BelongsTo
    {
        return $this->belongsTo(OperationalRecord::class, 'operational_record_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
