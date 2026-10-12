<?php

declare(strict_types=1);

namespace App\Services\WorkManagement;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Risk map for the director. Nothing here is a manual register: every score is computed from signals the system already
 * holds (overdue obligations, blocked work, ledger differences, overdue receivables, overloaded staff) so it can be audited.
 */
class RiskMapService
{
    private const CATEGORIES = [
        'fiscal' => ['Fiscal', 'Obligaciones tributarias vencidas o por vencer.'],
        'accounting' => ['Contable', 'Cambios solicitados, bloqueos y diferencias contra el Mayor.'],
        'operation' => ['Operación', 'Tareas vencidas y trabajo atrasado del período.'],
        'portfolio' => ['Cartera', 'Cuentas por cobrar vencidas y promesas incumplidas.'],
        'capacity' => ['Capacidad del equipo', 'Sobrecarga de quienes llevan la empresa.'],
    ];

    private const ACTIONS = [
        'fiscal' => 'Regularizar y presentar las obligaciones vencidas.',
        'accounting' => 'Resolver los cambios solicitados y conciliar contra el Mayor.',
        'operation' => 'Priorizar y cerrar las tareas vencidas.',
        'portfolio' => 'Dar seguimiento a la cartera vencida y a las promesas de pago.',
        'capacity' => 'Reasignar carga o apoyar al responsable de la empresa.',
    ];

    public function __construct(private WorkDashboardService $dashboard) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function payload(Organization $organization, User $user, array $filters = []): array
    {
        $executive = $this->dashboard->executiveSnapshot($organization, $user, $filters);
        $companies = collect($executive['companies']);
        $today = now()->startOfDay();
        $receivables = collect($executive['receivables'])->keyBy('id');
        $payables = collect($executive['payables'])->keyBy('id');
        $loads = collect($executive['workload'])->keyBy('name');
        $obligations = DB::table('fiscal_obligations')->whereIn('company_id', $companies->pluck('id')->all())
            ->whereNull('filed_on')->whereNull('completed_at')
            ->orderBy('due_on')->get(['company_id', 'due_on'])->groupBy('company_id');

        $rows = $companies->map(function (array $company) use ($receivables, $payables, $loads, $obligations, $today): array {
            $pending = $obligations->get($company['id'], collect());
            $overdueObligations = $pending->filter(fn (object $item): bool => $item->due_on < $today->toDateString());
            $soon = $pending->filter(fn (object $item): bool => $item->due_on >= $today->toDateString() && $item->due_on <= $today->copy()->addDays(7)->toDateString());
            $oldest = $overdueObligations->isEmpty() ? 0 : (int) Carbon::parse($overdueObligations->first()->due_on)->diffInDays($today);

            $receivable = $receivables->get($company['id']);
            $payable = $payables->get($company['id']);
            $overdueRatio = $receivable && ($receivable['total_cents'] ?? 0) > 0 ? ($receivable['overdue_cents'] ?? 0) / $receivable['total_cents'] : 0;
            $ledgerDifference = ($receivable['ledger_status'] ?? 'ok') !== 'ok' || ($payable['ledger_status'] ?? 'ok') !== 'ok';
            $staffLoad = max(
                (int) ($loads->get($company['accountant'])['utilization_percentage'] ?? 0),
                (int) ($loads->get($company['assistant'])['utilization_percentage'] ?? 0),
            );
            $overdueTasks = (int) ($company['overdue_tasks'] ?? 0);

            $scores = [
                'fiscal' => min(100, $overdueObligations->count() * 35 + $soon->count() * 10 + ($oldest > 60 ? 25 : 0)),
                'accounting' => min(100, (int) ($company['incidents'] ?? 0) * 30 + ($ledgerDifference ? 40 : 0)),
                'operation' => min(100, $overdueTasks * 12),
                'portfolio' => min(100, (int) round($overdueRatio * 100) + (int) ($receivable['broken_promises'] ?? 0) * 15 + (int) ($receivable['critical_documents'] ?? 0) * 10),
                'capacity' => $staffLoad >= 90 ? 80 : ($staffLoad >= 75 ? 45 : 0),
            ];
            arsort($scores);
            $main = array_key_first($scores);
            $score = (int) round(max($scores) * 0.7 + (array_sum($scores) / count($scores)) * 0.3);
            $blocked = $company['status'] === 'blocked';
            $score = $blocked ? max($score, 70) : $score;

            $probability = $overdueObligations->count() + $overdueTasks >= 2 ? 4 : ($overdueObligations->count() + $overdueTasks === 1 ? 3 : ($soon->isNotEmpty() || $company['pending_tasks'] > 0 ? 2 : 1));
            $impact = match (true) {
                max($scores) === 0 => 1,
                $main === 'fiscal' && $overdueObligations->isNotEmpty() => $oldest > 60 ? 4 : 3,
                in_array($main, ['accounting', 'portfolio'], true) => 3,
                default => 2,
            };

            return [
                'id' => (int) $company['id'],
                'name' => $company['name'],
                'tax_id' => $company['tax_id'],
                'office' => $company['office_name'],
                'accountant' => $company['accountant'],
                'score' => $score,
                'level' => $score >= 70 ? 'critical' : ($score >= 50 ? 'high' : ($score >= 25 ? 'medium' : 'low')),
                'blocked' => $blocked,
                'main' => $main,
                'main_label' => self::CATEGORIES[$main][0],
                'cause' => $this->cause($main, $overdueObligations->count(), $oldest, $overdueTasks, (int) ($company['incidents'] ?? 0), $ledgerDifference, $overdueRatio, $staffLoad),
                'action' => max($scores) > 0 ? self::ACTIONS[$main] : 'Sin riesgos activos; mantener el seguimiento habitual.',
                'deadline' => $pending->first()->due_on ?? null,
                'probability' => $probability,
                'impact' => $impact,
                'scores' => collect(self::CATEGORIES)->map(fn (array $meta, string $key): array => ['label' => $meta[0], 'value' => $scores[$key]])->values()->all(),
                'signals' => [
                    ['label' => 'Obligaciones vencidas', 'value' => $overdueObligations->count()],
                    ['label' => 'Obligaciones por vencer (7 días)', 'value' => $soon->count()],
                    ['label' => 'Tareas vencidas', 'value' => $overdueTasks],
                    ['label' => 'Tareas bloqueadas o con cambios', 'value' => (int) ($company['incidents'] ?? 0)],
                    ['label' => 'Diferencia contra el Mayor', 'value' => $ledgerDifference ? 'Sí' : 'No'],
                    ['label' => 'Carga del responsable', 'value' => $staffLoad.'%'],
                ],
            ];
        })->sortByDesc('score')->values();

        $categories = collect(self::CATEGORIES)->map(function (array $meta, string $key) use ($rows): array {
            $index = array_search($meta[0], array_column(self::CATEGORIES, 0), true);
            $affected = $rows->filter(fn (array $row): bool => ($row['scores'][$index]['value'] ?? 0) >= 25)->count();

            return ['label' => $meta[0], 'description' => $meta[1], 'companies' => $affected, 'value' => $rows->isEmpty() ? 0 : (int) round($affected / $rows->count() * 100)];
        })->values();

        $workload = collect($executive['workload']);
        $overloaded = $workload->filter(fn (array $row): bool => ($row['utilization_percentage'] ?? 0) >= 90)->count();

        return [
            'summary' => [
                'critical' => $rows->where('level', 'critical')->count(),
                'high' => $rows->whereIn('level', ['critical', 'high'])->count(),
                'blocked' => $rows->where('blocked', true)->count(),
                'overload' => $workload->isEmpty() ? 0 : (int) round($overloaded / $workload->count() * 100),
                'score' => $rows->isEmpty() ? 0 : (int) round($rows->avg('score')),
            ],
            'companies' => $rows->all(),
            'categories' => $categories->all(),
        ];
    }

    private function cause(string $main, int $overdueObligations, int $oldest, int $overdueTasks, int $incidents, bool $ledgerDifference, float $overdueRatio, int $staffLoad): string
    {
        return match ($main) {
            'fiscal' => $overdueObligations > 0 ? $overdueObligations.' obligación(es) vencida(s); la más antigua con '.$oldest.' días.' : 'Obligaciones por vencer esta semana.',
            'accounting' => $ledgerDifference ? 'Diferencia contra el Mayor en cartera o proveedores.' : $incidents.' tarea(s) bloqueada(s) o con cambios solicitados.',
            'operation' => $overdueTasks.' tarea(s) vencida(s) en el período.',
            'portfolio' => 'Cartera vencida ('.round($overdueRatio * 100).'% del saldo) o promesas incumplidas.',
            default => 'Responsable con '.$staffLoad.'% de carga.',
        };
    }
}
