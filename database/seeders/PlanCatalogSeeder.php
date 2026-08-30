<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PlanCatalogSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
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
                    'limits' => [],
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

            foreach ($featureNames as $code => $name) {
                DB::table('features')->updateOrInsert(
                    ['code' => $code],
                    [
                        'name' => $name,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ],
                );
            }

            foreach ($plans as $planData) {
                DB::table('plans')->updateOrInsert(
                    ['code' => $planData['code']],
                    [
                        'name' => $planData['name'],
                        'description' => $planData['description'],
                        'is_active' => true,
                        'display_order' => $planData['order'],
                        'updated_at' => $now,
                        'created_at' => $now,
                    ],
                );

                $planId = DB::table('plans')
                    ->where('code', $planData['code'])
                    ->value('id');

                foreach ([
                    'monthly' => $planData['monthly_price'],
                    'annual' => $planData['annual_price'],
                ] as $period => $amount) {
                    DB::table('plan_prices')->updateOrInsert(
                        [
                            'plan_id' => $planId,
                            'billing_period' => $period,
                            'currency_code' => 'GTQ',
                        ],
                        [
                            'amount' => $amount,
                            'is_active' => true,
                            'updated_at' => $now,
                            'created_at' => $now,
                        ],
                    );
                }

                foreach ($planData['limits'] as $metric => [$value, $unit]) {
                    DB::table('plan_limits')->updateOrInsert(
                        [
                            'plan_id' => $planId,
                            'metric' => $metric,
                        ],
                        [
                            'limit_value' => $value,
                            'unit' => $unit,
                            'updated_at' => $now,
                            'created_at' => $now,
                        ],
                    );
                }

                DB::table('feature_plan')
                    ->where('plan_id', $planId)
                    ->delete();

                $featureIds = DB::table('features')
                    ->whereIn('code', $planData['features'])
                    ->pluck('id');

                foreach ($featureIds as $featureId) {
                    DB::table('feature_plan')->insert([
                        'plan_id' => $planId,
                        'feature_id' => $featureId,
                    ]);
                }
            }
        });
    }
}
