<?php

use App\Services\Tenancy\TenantDatabaseContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every taxpayer must keep the VAT books, so they leave "advanced_reports" and come with the accounting of every plan
 * (the code change); and almost every accounting office runs its clients' payrolls, so payroll (planilla, libro de
 * salarios, IGSS) is included from Profesional on instead of from Corporativo. This brings existing catalogs, including
 * versions already sold, up to date; texts edited in the Control Center are kept.
 */
return new class extends Migration
{
    private const PAYROLL_PLANS = ['professional', 'multi_office'];

    /** Catalog seeder copy that is replaced; any other text was edited by hand and stays. */
    private const HIGHLIGHTS = [
        'essential' => ['Contabilidad y facturación electrónica (FEL)', 'Contabilidad, libros de IVA y facturación electrónica (FEL)'],
        'professional' => ['Contabilidad, reportes e inventario', 'Contabilidad, inventario y planilla'],
        'multi_office' => ['Contabilidad e inventario multi-oficina', 'Contabilidad, inventario y planilla multi-oficina'],
    ];

    public function up(): void
    {
        app(TenantDatabaseContext::class)->runAsSystem(function (): void {
            $featureId = DB::table('features')->where('code', 'payroll')->value('id');
            foreach (DB::table('plans')->whereIn('code', self::PAYROLL_PLANS)->get(['id']) as $plan) {
                foreach (DB::table('plan_versions')->where('plan_id', $plan->id)->get(['id', 'entitlements']) as $version) {
                    $entitlements = (array) json_decode((string) $version->entitlements, true);
                    if (! in_array('payroll', $entitlements, true)) {
                        DB::table('plan_versions')->where('id', $version->id)->update(['entitlements' => json_encode([...$entitlements, 'payroll']), 'updated_at' => now()]);
                    }
                }
                if ($featureId !== null) {
                    DB::table('feature_plan')->insertOrIgnore(['plan_id' => $plan->id, 'feature_id' => $featureId]);
                }
            }

            foreach (self::HIGHLIGHTS as $code => [$old, $new]) {
                $plan = DB::table('plans')->where('code', $code)->first(['id', 'highlights']);
                $highlights = (array) json_decode((string) ($plan->highlights ?? '[]'), true);
                $index = array_search($old, $highlights, true);
                if ($plan !== null && $index !== false) {
                    $highlights[$index] = $new;
                    DB::table('plans')->where('id', $plan->id)->update(['highlights' => json_encode(array_values($highlights)), 'updated_at' => now()]);
                }
            }
        });
    }

    public function down(): void
    {
        // Payroll stays in the plans: removing it would take it away from offices already running payrolls.
    }
};
