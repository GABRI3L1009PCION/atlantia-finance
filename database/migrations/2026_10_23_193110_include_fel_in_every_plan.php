<?php

use App\Services\Tenancy\TenantDatabaseContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every plan issues electronic invoices (FEL): an accounting firm cannot work without them. Catalogs loaded before this
 * change listed FEL from the Profesional plan on, although Esencial could already issue them; this makes the catalog say so.
 */
return new class extends Migration
{
    private const OLD_HIGHLIGHT = 'Contabilidad y facturación básica';

    private const NEW_HIGHLIGHT = 'Contabilidad y facturación electrónica (FEL)';

    public function up(): void
    {
        app(TenantDatabaseContext::class)->runAsSystem(function (): void {
            $plan = DB::table('plans')->where('code', 'essential')->first();
            $featureId = DB::table('features')->where('code', 'fel')->value('id');
            if ($plan === null || $featureId === null) {
                return;
            }

            foreach (DB::table('plan_versions')->where('plan_id', $plan->id)->get(['id', 'entitlements']) as $version) {
                $entitlements = (array) json_decode((string) $version->entitlements, true);
                if (! in_array('fel', $entitlements, true)) {
                    DB::table('plan_versions')->where('id', $version->id)->update(['entitlements' => json_encode([...$entitlements, 'fel']), 'updated_at' => now()]);
                }
            }
            DB::table('feature_plan')->insertOrIgnore(['plan_id' => $plan->id, 'feature_id' => $featureId]);

            // Only the copy written by the catalog seeder is replaced; a text edited in the Control Center is kept.
            $highlights = (array) json_decode((string) ($plan->highlights ?? '[]'), true);
            $index = array_search(self::OLD_HIGHLIGHT, $highlights, true);
            if ($index !== false) {
                $highlights[$index] = self::NEW_HIGHLIGHT;
                DB::table('plans')->where('id', $plan->id)->update(['highlights' => json_encode(array_values($highlights)), 'updated_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        // The catalog keeps FEL in every plan: removing it would take invoicing away from firms already using it.
    }
};
