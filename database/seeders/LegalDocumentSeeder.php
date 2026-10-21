<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\Platform\LegalDocumentService;
use App\Services\Tenancy\TenantDatabaseContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Loads the first version of the terms, the privacy policy and the personal data processing document. It never
 * overwrites a document that already has versions: later edits belong to the Control Center.
 */
class LegalDocumentSeeder extends Seeder
{
    public function run(): void
    {
        app(TenantDatabaseContext::class)->runAsSystem(function (): void {
            foreach (LegalDocumentService::DOCUMENTS as $slug => $definition) {
                if (DB::table('legal_documents')->where('slug', $slug)->exists()) {
                    continue;
                }
                DB::table('legal_documents')->insert(['slug' => $slug, 'version' => 1, 'title' => $definition['label'], 'body' => LegalDocumentService::starterText($slug),
                    'status' => 'active', 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            }
        });
    }
}
