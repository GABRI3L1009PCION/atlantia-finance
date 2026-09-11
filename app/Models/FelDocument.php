<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Fel\FelDocumentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/**
 * @property int $id
 * @property int $company_id
 * @property int $organization_id
 * @property int $created_by
 * @property string $public_id
 * @property string $provider
 * @property string $environment
 * @property string $series
 * @property int $number
 * @property string $currency
 * @property string $document_type
 * @property FelDocumentStatus $status
 * @property CarbonImmutable $issued_on
 * @property array<string, mixed> $fiscal_snapshot
 * @property array<string, mixed> $receiver_snapshot
 * @property array<string, mixed>|null $provider_payload
 * @property array<string, mixed>|null $provider_response
 * @property int $subtotal_cents
 * @property int $discount_cents
 * @property int $tax_cents
 * @property int $total_cents
 * @property string|null $pdf_path
 * @property string|null $email_sent_at
 * @property string|null $authorization_number
 * @property string|null $fel_series
 * @property string|null $fel_number
 * @property CarbonImmutable|null $certified_at
 * @property string|null $cancellation_reason
 * @property string|null $observations
 * @property Collection<int, FelDocumentLine> $lines
 */
class FelDocument extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['provider_payload', 'provider_response', 'cancellation_response', 'pdf_path'];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return [
            'status' => FelDocumentStatus::class, 'issued_on' => 'immutable_date',
            'certified_at' => 'immutable_datetime',
            'fiscal_snapshot' => 'array', 'receiver_snapshot' => 'array',
            'provider_payload' => 'array', 'provider_response' => 'array', 'cancellation_response' => 'array',
            'subtotal_cents' => 'integer', 'discount_cents' => 'integer', 'tax_cents' => 'integer', 'total_cents' => 'integer',
        ];
    }

    /** @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    /** @return HasMany<FelDocumentLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(FelDocumentLine::class)->orderBy('position');
    }

    public function transition(FelDocumentStatus $next): void
    {
        $this->status->transitionTo($next);
        $this->status = $next;
    }

    protected static function booted(): void
    {
        static::updating(function (self $document): void {
            $original = $document->getRawOriginal('status');
            if ($original !== 'draft' && $document->isDirty([
                'company_id', 'organization_id', 'created_by', 'public_id', 'customer_id', 'issued_on', 'series', 'number',
                'provider', 'environment', 'currency', 'document_type', 'fiscal_snapshot', 'receiver_snapshot', 'subtotal_cents', 'discount_cents', 'tax_cents', 'total_cents', 'observations',
            ])) {
                throw ValidationException::withMessages(['document' => 'El documento emitido es inmutable.']);
            }
            if ($document->isDirty('status')) {
                FelDocumentStatus::from($original)->transitionTo($document->status);
            }
        });
        static::deleting(function (): void {
            throw ValidationException::withMessages(['document' => 'Los documentos FEL se conservan para auditoría.']);
        });
    }
}
