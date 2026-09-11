<?php

declare(strict_types=1);

namespace App\Services\Fel;

use App\Models\FelDocument;

class FelDocumentBuilder
{
    /** @return array<string, mixed> */
    public function build(FelDocument $document): array
    {
        return [
            'schema_version' => 1, 'document_id' => $document->public_id,
            'company_id' => $document->company_id, 'environment' => $document->environment,
            'type' => $document->document_type, 'date' => $document->issued_on->toDateString(),
            'currency' => $document->currency, 'series' => $document->series, 'number' => $document->number,
            'issuer' => array_diff_key($document->fiscal_snapshot, ['logo_data_uri' => true]), 'receiver' => $document->receiver_snapshot,
            'lines' => $document->lines->map(fn ($line) => $line->only([
                'position', 'item', 'description', 'classification', 'quantity', 'unit_cents',
                'subtotal_cents', 'discount_cents', 'tax_cents', 'total_cents', 'tax_snapshot',
            ]))->all(),
            'totals' => $document->only(['subtotal_cents', 'discount_cents', 'tax_cents', 'total_cents']),
            'notice' => config('fel.notice'),
        ];
    }
}
