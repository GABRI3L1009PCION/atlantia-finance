<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property numeric-string $amount
 * @property CarbonInterface|null $paid_at
 * @property array{name: string, nit: string, address: string, email: string, plan_name?: string, billing_period?: string}|null $billing_snapshot
 */
class Payment extends Model
{
    protected $fillable = [
        'organization_id',
        'subscription_id',
        'provider',
        'provider_payment_id',
        'currency_code',
        'amount',
        'status',
        'paid_at',
        'failed_at',
        'billing_snapshot',
    ];

    protected static function booted(): void
    {
        static::creating(function (Payment $payment): void {
            $payment->public_id ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
            'billing_snapshot' => 'array',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** @return HasMany<PaymentAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }
}
