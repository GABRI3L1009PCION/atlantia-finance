<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\Tenancy\TenantDatabaseContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PlanCatalogSeeder extends Seeder
{
    public function run(): void
    {
        // The catalog (plans, versions, features) is platform data: it is always written in system mode.
        app(TenantDatabaseContext::class)->runAsSystem(fn () => DB::transaction(function (): void {
            $plans = [
                [
                    'code' => 'essential',
                    'name' => 'Esencial',
                    'description' => 'Para profesionales independientes y oficinas pequeñas.',
                    'order' => 1,
                    'monthly_price' => 399,
                    'annual_price' => 3990,
                    'limits' => [
                        'offices' => [1, 'offices'],
                        'users' => [2, 'users'],
                        'active_cases' => [150, 'cases'],
                        'storage_bytes' => [2147483648, 'bytes'],
                        'ai_queries' => [20, 'queries/month'],
                    ],
                    'features' => [
                        'basic_accounting',
                        'basic_invoicing',
                        'case_management',
                        'task_management',
                        'obligation_management',
                        'audit_log',
                        'automatic_backups',
                    ],
                ],
                [
                    'code' => 'professional',
                    'name' => 'Profesional',
                    'description' => 'Para oficinas establecidas y equipos contables en crecimiento.',
                    'order' => 2,
                    'monthly_price' => 799,
                    'annual_price' => 7990,
                    'limits' => [
                        'offices' => [1, 'offices'],
                        'users' => [8, 'users'],
                        'active_cases' => [500, 'cases'],
                        'storage_bytes' => [10737418240, 'bytes'],
                        'ai_queries' => [120, 'queries/month'],
                    ],
                    'features' => [
                        'basic_accounting',
                        'basic_invoicing',
                        'case_management',
                        'task_management',
                        'obligation_management',
                        'inventory',
                        'advanced_reports',
                        'roles_and_permissions',
                        'approval_workflows',
                        'fel',
                        'ocr',
                        'audit_log',
                        'automatic_backups',
                        'priority_support',
                    ],
                ],
                [
                    'code' => 'multi_office',
                    'name' => 'Multi-oficina',
                    'description' => 'Para propietarios que administran múltiples oficinas.',
                    'order' => 3,
                    'monthly_price' => 1499,
                    'annual_price' => 14990,
                    'limits' => [
                        'offices' => [5, 'offices'],
                        'users' => [25, 'users'],
                        'active_cases' => [1500, 'cases'],
                        'storage_bytes' => [42949672960, 'bytes'],
                        'ai_queries' => [500, 'queries/month'],
                    ],
                    'features' => [
                        'basic_accounting',
                        'basic_invoicing',
                        'case_management',
                        'task_management',
                        'obligation_management',
                        'inventory',
                        'advanced_reports',
                        'roles_and_permissions',
                        'approval_workflows',
                        'fel',
                        'ocr',
                        'multi_office',
                        'consolidated_dashboard',
                        'office_permissions',
                        'centralized_audit',
                        'audit_log',
                        'automatic_backups',
                        'priority_support',
                        'initial_training',
                    ],
                ],
                [
                    'code' => 'corporate',
                    'name' => 'Corporativo',
                    'description' => 'Para firmas de gran escala con procesos avanzados.',
                    'order' => 4,
                    'monthly_price' => 2999,
                    'annual_price' => 29990,
                    'limits' => [
                        'offices' => [10, 'offices'],
                        'users' => [60, 'users'],
                        'active_cases' => [5000, 'cases'],
                        'storage_bytes' => [128849018880, 'bytes'],
                        'ai_queries' => [1500, 'queries/month'],
                    ],
                    'features' => [
                        'basic_accounting',
                        'basic_invoicing',
                        'case_management',
                        'task_management',
                        'obligation_management',
                        'inventory',
                        'payroll',
                        'advanced_reports',
                        'roles_and_permissions',
                        'approval_workflows',
                        'fel',
                        'ocr',
                        'multi_office',
                        'consolidated_dashboard',
                        'office_permissions',
                        'centralized_audit',
                        'api_access',
                        'webhooks',
                        'corporate_workflows',
                        'audit_log',
                        'automatic_backups',
                        'priority_support',
                        'initial_training',
                    ],
                ],
                [
                    'code' => 'enterprise',
                    'name' => 'Empresarial',
                    'description' => 'Plan personalizado para organizaciones con necesidades especiales.',
                    'order' => 5,
                    'monthly_price' => 4999,
                    'annual_price' => 49990,
                    // Null means unlimited; a missing metric would deny allocation.
                    'limits' => [
                        'offices' => [null, 'offices'],
                        'users' => [null, 'users'],
                        'active_cases' => [null, 'cases'],
                        'storage_bytes' => [null, 'bytes'],
                        'ai_queries' => [null, 'queries/month'],
                    ],
                    'features' => [
                        'basic_accounting',
                        'basic_invoicing',
                        'case_management',
                        'task_management',
                        'obligation_management',
                        'inventory',
                        'payroll',
                        'advanced_reports',
                        'roles_and_permissions',
                        'approval_workflows',
                        'fel',
                        'ocr',
                        'multi_office',
                        'consolidated_dashboard',
                        'office_permissions',
                        'centralized_audit',
                        'api_access',
                        'webhooks',
                        'corporate_workflows',
                        'dedicated_infrastructure',
                        'custom_integrations',
                        'custom_migrations',
                        'custom_ai_limits',
                        'service_level_agreement',
                        'audit_log',
                        'automatic_backups',
                        'priority_support',
                        'initial_training',
                    ],
                ],
            ];

            $featureNames = [
                'basic_accounting' => 'Contabilidad básica',
                'basic_invoicing' => 'Facturación básica',
                'case_management' => 'Gestión de expedientes',
                'task_management' => 'Gestión de tareas',
                'obligation_management' => 'Gestión de obligaciones',
                'inventory' => 'Inventario',
                'payroll' => 'Nómina',
                'advanced_reports' => 'Reportes avanzados',
                'roles_and_permissions' => 'Roles y permisos',
                'approval_workflows' => 'Flujos de aprobación',
                'fel' => 'Facturación Electrónica en Línea',
                'ocr' => 'Reconocimiento de documentos con OCR',
                'multi_office' => 'Administración multi-oficina',
                'consolidated_dashboard' => 'Panel consolidado',
                'office_permissions' => 'Permisos por oficina',
                'centralized_audit' => 'Auditoría centralizada',
                'api_access' => 'Acceso a API',
                'webhooks' => 'Webhooks',
                'corporate_workflows' => 'Flujos corporativos',
                'dedicated_infrastructure' => 'Infraestructura dedicada',
                'custom_integrations' => 'Integraciones personalizadas',
                'custom_migrations' => 'Migraciones de datos personalizadas',
                'custom_ai_limits' => 'Límites personalizados de IA',
                'service_level_agreement' => 'Acuerdo de nivel de servicio',
                'audit_log' => 'Registro de auditoría',
                'automatic_backups' => 'Copias de seguridad automáticas',
                'priority_support' => 'Soporte prioritario',
                'initial_training' => 'Capacitación inicial',
            ];

            $now = now();
            // Catalog metadata: module and the features each one requires to be effective.
            $modules = ['basic_accounting' => 'contabilidad', 'basic_invoicing' => 'ventas', 'case_management' => 'operacion', 'task_management' => 'operacion',
                'obligation_management' => 'fiscal', 'inventory' => 'inventario', 'payroll' => 'nomina', 'advanced_reports' => 'reportes', 'roles_and_permissions' => 'seguridad',
                'approval_workflows' => 'operacion', 'fel' => 'fiscal', 'ocr' => 'documentos', 'multi_office' => 'oficinas', 'consolidated_dashboard' => 'oficinas',
                'office_permissions' => 'oficinas', 'centralized_audit' => 'auditoria', 'api_access' => 'integraciones', 'webhooks' => 'integraciones',
                'corporate_workflows' => 'operacion', 'dedicated_infrastructure' => 'infraestructura', 'custom_integrations' => 'integraciones', 'custom_migrations' => 'integraciones',
                'custom_ai_limits' => 'luzma', 'service_level_agreement' => 'soporte', 'audit_log' => 'auditoria', 'automatic_backups' => 'infraestructura',
                'priority_support' => 'soporte', 'initial_training' => 'soporte'];
            $requires = ['fel' => ['basic_invoicing'], 'consolidated_dashboard' => ['multi_office'], 'office_permissions' => ['multi_office'], 'centralized_audit' => ['multi_office']];

            foreach ($featureNames as $code => $name) {
                $exists = DB::table('features')->where('code', $code)->exists();
                DB::table('features')->updateOrInsert(['code' => $code], [
                    'name' => $name, 'module' => $modules[$code], 'updated_at' => $now,
                    ...($exists ? [] : ['created_at' => $now, 'requires' => isset($requires[$code]) ? json_encode($requires[$code]) : null]),
                ]);
            }

            foreach ($plans as $planData) {
                $planId = DB::table('plans')->where('code', $planData['code'])->value('id');
                // Plans managed from the Control Center (they already have versions) are never overwritten here.
                if ($planId !== null && DB::table('plan_versions')->where('plan_id', $planId)->exists()) {
                    continue;
                }

                DB::table('plans')->updateOrInsert(['code' => $planData['code']], [
                    'name' => $planData['name'], 'description' => $planData['description'], 'is_active' => true, 'display_order' => $planData['order'],
                    'updated_at' => $now, 'created_at' => $now,
                ]);
                $planId = DB::table('plans')->where('code', $planData['code'])->value('id');

                foreach (['monthly' => $planData['monthly_price'], 'annual' => $planData['annual_price']] as $period => $amount) {
                    DB::table('plan_prices')->updateOrInsert(['plan_id' => $planId, 'billing_period' => $period, 'currency_code' => 'GTQ'],
                        ['amount' => $amount, 'is_active' => true, 'updated_at' => $now, 'created_at' => $now]);
                }

                $versionLimits = [];
                foreach ($planData['limits'] as $metric => [$value, $unit]) {
                    DB::table('plan_limits')->updateOrInsert(['plan_id' => $planId, 'metric' => $metric],
                        ['limit_value' => $value, 'unit' => $unit, 'enforcement' => 'hard', 'updated_at' => $now, 'created_at' => $now]);
                    $versionLimits[$metric] = ['value' => $value, 'unit' => $unit, 'warning' => 80, 'high' => 95, 'enforcement' => 'hard'];
                }

                DB::table('feature_plan')->where('plan_id', $planId)->delete();
                foreach (DB::table('features')->whereIn('code', $planData['features'])->pluck('id') as $featureId) {
                    DB::table('feature_plan')->insert(['plan_id' => $planId, 'feature_id' => $featureId]);
                }

                DB::table('plan_versions')->insert([
                    'plan_id' => $planId, 'version' => 1, 'name' => $planData['name'], 'description' => $planData['description'], 'currency_code' => 'GTQ',
                    'monthly_price' => $planData['monthly_price'], 'annual_price' => $planData['annual_price'], 'entitlements' => json_encode($planData['features']),
                    'limits' => json_encode($versionLimits), 'status' => 'published', 'change_note' => 'Versión inicial del catálogo.', 'published_at' => $now,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            $this->fillPresentation();
        }));
    }

    /**
     * Sales-page presentation of the catalog plans. It only fills plans that have none, so what the Control Center
     * edits is never overwritten; the recommended flag is only set when no plan is recommended yet.
     */
    private function fillPresentation(): void
    {
        $presentation = [
            'essential' => ['tagline' => 'Para comenzar con orden',
                'highlights' => ['Contabilidad y facturación básica', 'Expedientes, tareas y obligaciones', 'Seguridad, auditoría y copias de respaldo']],
            'professional' => ['tagline' => 'Para oficinas establecidas', 'recommended' => true,
                'highlights' => ['Contabilidad, reportes e inventario', 'Roles, aprobaciones y FEL', 'OCR y soporte prioritario']],
            'multi_office' => ['tagline' => 'Para propietarios de varias sedes',
                'highlights' => ['Contabilidad e inventario multi-oficina', 'Panel consolidado y permisos por sede', 'Auditoría central y capacitación inicial']],
            'corporate' => ['tagline' => 'Para firmas de gran escala',
                'highlights' => ['Contabilidad, inventario y nómina', 'API, webhooks y flujos corporativos', 'Soporte preferente y auditoría extendida']],
            'enterprise' => ['tagline' => 'Para necesidades especiales', 'price_prefix' => 'Desde', 'cta_label' => 'Solicitar cotización',
                'highlights' => ['Todas las funciones de Atlantia', 'Infraestructura compartida o dedicada', 'Integraciones y migraciones especiales', 'Acuerdos de servicio e implementación']],
        ];
        $hasRecommended = DB::table('plans')->where('is_recommended', true)->exists();

        foreach ($presentation as $code => $values) {
            DB::table('plans')->where('code', $code)->whereNull('tagline')->update([
                'tagline' => $values['tagline'],
                'highlights' => json_encode($values['highlights']),
                'price_prefix' => $values['price_prefix'] ?? null,
                'cta_label' => $values['cta_label'] ?? null,
                'is_recommended' => ! $hasRecommended && ($values['recommended'] ?? false),
            ]);
        }
    }
}
