<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\OperationalRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property CarbonImmutable $effective_on
 * @property int $amount_cents
 * @property int $version
 * @property array<string, mixed>|null $data
 */
class OperationalRecord extends Model
{
    /** @use HasFactory<OperationalRecordFactory> */
    use HasFactory;

    protected $fillable = [
        'company_id', 'accounting_period_id', 'journal_entry_id', 'module', 'record_type',
        'reference', 'title', 'status', 'effective_on', 'currency', 'amount_cents', 'data',
        'idempotency_key', 'created_by', 'updated_by', 'approved_by', 'closed_by',
        'approved_at', 'closed_at', 'version',
    ];

    protected static function booted(): void
    {
        static::creating(function (OperationalRecord $record): void {
            $record->public_id ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'effective_on' => 'date',
            'amount_cents' => 'integer',
            'data' => 'array',
            'version' => 'integer',
            'approved_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<OperationalRecordEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(OperationalRecordEvent::class);
    }

    /** @return HasMany<OperationalRecordFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(OperationalRecordFile::class);
    }
}
