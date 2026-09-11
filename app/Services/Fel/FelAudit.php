<?php

declare(strict_types=1);

namespace App\Services\Fel;

use App\Models\FelDocument;
use Illuminate\Support\Facades\DB;

class FelAudit
{
    /** @param array<string, int|string|null> $details */
    public function record(FelDocument $document, string $action, ?int $actorId = null, array $details = []): void
    {
        DB::table('audit_logs')->insert([
            'organization_id' => $document->organization_id, 'user_id' => $actorId ?? $document->created_by,
            'action' => 'fel.'.$action, 'auditable_type' => 'fel_documents', 'auditable_id' => $document->id,
            'new_values' => json_encode(['company_id' => $document->company_id, 'document' => $document->public_id,
                'environment' => $document->environment, ...$details], JSON_THROW_ON_ERROR),
            'occurred_at' => now(), 'created_at' => now(),
        ]);
    }
}
