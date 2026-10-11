<?php

declare(strict_types=1);

namespace App\Services\WorkManagement;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Catalog of the executive reports the director can open or download, with how often they were exported. */
class ExecutiveReportsService
{
    public function __construct(private WorkDashboardService $dashboard, private WorkAuthorizationService $authorization) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function payload(Organization $organization, User $user, array $filters = []): array
    {
        $executive = $this->dashboard->dashboard($organization, $user, 'executive', $filters);
        $offices = collect($executive['offices']);
        $companies = collect($executive['companies']);
        $workload = collect($executive['workload']);
        $productivity = collect($executive['productivity'] ?? []);
        $summary = $executive['summary'];
        $canRevenue = $this->authorization->can($organization, $user, 'crm.view');
        $overloaded = $workload->filter(fn (array $row): bool => ($row['utilization_percentage'] ?? 0) >= 90)->count();
        $completed = (int) $productivity->sum('completed');

        $exports = DB::table('audit_logs')->where('organization_id', $organization->id)->where('action', 'executive_report.exported')->orderByDesc('occurred_at');
        $month = (clone $exports)->where('occurred_at', '>=', now()->startOfMonth())->get(['new_values', 'occurred_at']);
        $last = (clone $exports)->first(['new_values', 'occurred_at']);
        $titles = ['offices' => 'Resumen de oficinas', 'companies' => 'Cumplimiento por empresa', 'workload' => 'Carga del equipo', 'productivity' => 'Productividad del equipo', 'risk' => 'Mapa de riesgos', 'compliance' => 'Obligaciones fiscales', 'closings' => 'Avance de cierres', 'revenue' => 'Cobros de la firma'];

        $reports = [
            ['key' => 'offices', 'title' => 'Resumen de oficinas', 'description' => 'Empresas, avance de cierres, atrasos y responsable de cada oficina.', 'category' => 'operational', 'href' => '/office/offices', 'export' => 'offices',
                'stats' => [['Oficinas', $offices->count()], ['Empresas', $companies->count()], ['Avance promedio', ($offices->isEmpty() ? 0 : (int) round($offices->avg('progress'))).'%']]],
            ['key' => 'companies', 'title' => 'Cumplimiento por empresa', 'description' => 'Estado del cierre, avance, tareas pendientes y equipo asignado a cada empresa.', 'category' => 'compliance', 'href' => '/accounting', 'export' => 'companies',
                'stats' => [['Empresas', $companies->count()], ['Con atrasos', $companies->where('is_overdue', true)->count()], ['Cierres del período', (int) $summary['completed_closings']]]],
            ['key' => 'compliance', 'title' => 'Obligaciones fiscales pendientes', 'description' => 'Lo que vence o ya venció en todas las empresas, con su responsable.', 'category' => 'compliance', 'href' => '/office/compliance', 'export' => 'compliance',
                'stats' => [['Tareas vencidas', (int) $summary['overdue_tasks']], ['Empresas', $companies->count()]]],
            ['key' => 'closings', 'title' => 'Avance de cierres', 'description' => 'Etapa del cierre de cada empresa: conciliación, revisión, aprobación y cierre final.', 'category' => 'operational', 'href' => '/work/closings', 'export' => 'closings',
                'stats' => [['En proceso', (int) $summary['periods_in_progress']], ['Para revisión', (int) $summary['ready_for_review']], ['Cerradas', (int) $summary['completed_closings']]]],
            ['key' => 'risk', 'title' => 'Mapa de riesgos', 'description' => 'Nivel de riesgo de cada cliente, causa principal y acción sugerida.', 'category' => 'clients', 'href' => '/office/risk-map', 'export' => 'risk', 'stats' => []],
            ['key' => 'workload', 'title' => 'Carga del equipo', 'description' => 'Tareas abiertas, vencidas y utilización de cada colaborador.', 'category' => 'team', 'href' => '/office/workload', 'export' => 'workload',
                'stats' => [['Colaboradores', $workload->count()], ['Sobrecargados', $overloaded], ['Utilización', ($workload->isEmpty() ? 0 : (int) round($workload->avg('utilization_percentage'))).'%']]],
            ['key' => 'productivity', 'title' => 'Productividad del equipo', 'description' => 'Tareas terminadas, puntualidad y tiempos de entrega por colaborador.', 'category' => 'team', 'href' => '/office/productivity', 'export' => 'productivity',
                'stats' => [['Terminadas', $completed], ['Abiertas', (int) $productivity->sum('open')]]],
            ...($canRevenue ? [
                ['key' => 'revenue', 'title' => 'Cobranza e ingresos', 'description' => 'Cobros emitidos a los clientes: pagados, pendientes y vencidos.', 'category' => 'financial', 'href' => '/office/revenue', 'export' => 'revenue',
                    'stats' => [['Cobros', DB::table('crm_invoices')->where('organization_id', $organization->id)->count()], ['Vencidos', DB::table('crm_invoices')->where('organization_id', $organization->id)->where('status', 'pending')->where('due_on', '<', now()->toDateString())->count()]]],
                ['key' => 'growth', 'title' => 'Crecimiento comercial', 'description' => 'Nuevas empresas, prospectos y oportunidades abiertas del pipeline.', 'category' => 'commercial', 'href' => '/office/growth', 'export' => null,
                    'stats' => [['Prospectos abiertos', DB::table('crm_prospects')->where('organization_id', $organization->id)->whereNotIn('stage', ['won', 'lost'])->count()]]],
            ] : []),
        ];

        return [
            'kpis' => [
                'available' => count($reports),
                'exports_month' => $month->count(),
                'exports_week' => (int) (clone $exports)->where('occurred_at', '>=', now()->subDays(7))->count(),
                'last_at' => $last->occurred_at ?? null,
                'last_report' => $last ? ($titles[json_decode((string) $last->new_values, true)['report'] ?? ''] ?? 'Reporte') : null,
            ],
            'reports' => $reports,
            'filters' => $executive['filters'],
            'options' => $executive['selectorOptions'],
        ];
    }
}
