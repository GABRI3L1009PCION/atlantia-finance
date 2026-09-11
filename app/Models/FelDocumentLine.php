<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * @property string $item
 * @property string $description
 * @property string $classification
 * @property numeric-string $quantity
 * @property int $unit_cents
 * @property int $discount_cents
 * @property int $subtotal_cents
 * @property int $tax_cents
 * @property int $total_cents
 */
class FelDocumentLine extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'tax_snapshot' => 'array', 'unit_cents' => 'integer', 'discount_cents' => 'integer',
            'subtotal_cents' => 'integer', 'tax_cents' => 'integer', 'total_cents' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $line): void {
            abort_unless(FelDocument::query()->whereKey($line->fel_document_id)->where('status', 'draft')->exists(), 422);
        });
        static::deleting(function (self $line): void {
            if (! FelDocument::query()->whereKey($line->fel_document_id)->where('status', 'draft')->exists()) {
                throw ValidationException::withMessages(['document' => 'El detalle emitido es inmutable.']);
            }
        });
    }
}
