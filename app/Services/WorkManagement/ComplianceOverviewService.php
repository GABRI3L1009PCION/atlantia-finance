<?php

declare(strict_types=1);

namespace App\Services\WorkManagement;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Fiscal compliance of the whole portfolio for the director: obligations, due dates, delays and what needs attention. */
class ComplianceOverviewService
{
    private const TYPE_LABELS = [
        'iva' => 'IVA',
        'isr' => 'ISR',
        'igss' => 'Planilla IGSS',
        'isr_retenciones' => 'ISR retenciones',
        'patente' => 'Patente y societarias',
    ];

    public function __construct(private WorkDashboardService $dashboard) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function payload(Organization $organization, User $user, array $filters = []): array
    {
        $executive = $this->dashboard->executiveSnapshot($organization, $user, $filters);
        $companies = collect($executive['companies'])->keyBy('id');
        $today = now()->startOfDay();

        $rows = DB::table('fiscal_obligations')
            ->leftJoin('users', 'users.id', '=', 'fiscal_obligations.assigned_to')
            ->whereIn('fiscal_obligations.company_id', $companies->keys()->all())
            ->whereBetween('fiscal_obligations.due_on', [$today->copy()->subMonths(12)->toDateString(), $today->copy()->addMonths(12)->toDateString()])
            ->orderBy('fiscal_obligations.due_on')
            ->limit(3000)
            ->get(['fiscal_obligations.id', 'fiscal_obligations.company_id', 'fiscal_obligations.name', 'fiscal_obligations.obligation_type', 'fiscal_obligations.due_on', 'fiscal_obligations.status', 'fiscal_obligations.filed_on', 'fiscal_obligations.completed_at', 'users.name as responsible']);

        $obligations = $rows->map(function (object $row) use ($companies, $today): array {
            $company = $companies->get((int) $row->company_id);
            $due = Carbon::parse($row->due_on)->startOfDay();
            $done = $row->filed_on !== null || $row->completed_at !== null;
            $days = (int) $today->diffInDays($due, false);

            return [
                'id' => (int) $row->id,
                'company_id' => (int) $row->company_id,
                'company' => $company['name'] ?? 'Empresa',
                'office' => $company['office_name'] ?? '—',
                'type' => $row->obligation_type,
                'type_label' => self::TYPE_LABELS[$row->obligation_type] ?? $row->name,
                'name' => $row->name,
                'due_on' => $due->toDateString(),
                'days' => $days,
                'responsible' => $row->responsible ?? ($company['accountant'] ?? null),
                'review' => in_array($row->status, ['ready_for_review', 'in_review'], true),
                'state' => $done ? 'done' : ($days < 0 ? 'overdue' : ($days <= 7 ? 'due_soon' : 'pending')),
            ];
        });

        $open = $obligations->where('state', '!=', 'done');
        $monthStart = $today->copy()->startOfMonth()->toDateString();
        $monthEnd = $today->copy()->endOfMonth()->toDateString();
        $thisMonth = $obligations->filter(fn (array $item): bool => $item['due_on'] >= $monthStart && $item['due_on'] <= $monthEnd);
        $total = $obligations->count();
        $overdue = $obligations->where('state', 'overdue');
        $overdueCompanies = $overdue->pluck('company_id')->unique();

        $offices = $companies->groupBy('office_name')->map(function ($group, string $name) use ($obligations): array {
            $mine = $obligations->whereIn('company_id', $group->pluck('id')->all());
            $good = $mine->where('state', '!=', 'overdue')->count();

            return ['name' => $name, 'obligations' => $mine->count(), 'value' => $mine->isEmpty() ? 100 : (int) round($good / $mine->count() * 100)];
        })->values()->sortByDesc('value')->values();

        return [
            'kpis' => [
                'on_time' => $obligations->where('state', '!=', 'overdue')->count(),
                'on_time_rate' => $total > 0 ? (int) round($obligations->where('state', '!=', 'overdue')->count() / $total * 100) : 100,
                'total' => $total,
                'overdue' => $overdue->count(),
                'overdue_rate' => $total > 0 ? (int) round($overdue->count() / $total * 100) : 0,
                'companies_clean' => $companies->count() - $overdueCompanies->count(),
                'companies' => $companies->count(),
                'in_review' => $obligations->where('review', true)->pluck('company_id')->unique()->count(),
                'monthly_rate' => $thisMonth->isEmpty() ? 100 : (int) round($thisMonth->where('state', '!=', 'overdue')->count() / $thisMonth->count() * 100),
            ],
            'obligations' => $open->values()->map(fn (array $item): array => collect($item)->except(['review'])->all())->take(400)->all(),
            'calendar' => $obligations->map(fn (array $item): array => ['date' => $item['due_on'], 'state' => $item['state']])->all(),
            'offices' => $offices->all(),
            'types' => $obligations->groupBy('type_label')->map(fn ($group, string $label): array => ['label' => $label, 'value' => $group->count()])->sortByDesc('value')->values()->all(),
            'alerts' => $this->alerts($obligations, $companies->count()),
            'suggestions' => $this->suggestions($obligations, $open),
        ];
    }

    /**
     * @param  Collection<int, covariant array<string, mixed>>  $obligations
     * @return list<array{tone: string, title: string, detail: string}>
     */
    private function alerts($obligations, int $companyCount): array
    {
        $alerts = [];
        $critical = $obligations->filter(fn (array $item): bool => in_array($item['state'], ['overdue', 'due_soon'], true) && $item['days'] <= 7 && $item['days'] >= -30);
        $repeat = $obligations->where('state', 'overdue')->groupBy('company_id')->filter(fn ($group): bool => $group->count() >= 2);

        if ($critical->isNotEmpty()) {
            $alerts[] = ['tone' => 'red', 'title' => $critical->count().($critical->count() === 1 ? ' obligación crítica' : ' obligaciones críticas').' esta semana', 'detail' => 'Vencidas o por vencer en 7 días: '.$critical->pluck('type_label')->unique()->take(3)->implode(', ').'.'];
        }

        if ($repeat->isNotEmpty()) {
            $alerts[] = ['tone' => 'amber', 'title' => $repeat->count().' empresa(s) con varias obligaciones vencidas', 'detail' => 'Dos o más obligaciones atrasadas en la misma empresa.'];
        }

        $stale = $obligations->where('state', 'overdue')->filter(fn (array $item): bool => $item['days'] <= -30);

        if ($stale->isNotEmpty()) {
            $alerts[] = ['tone' => 'red', 'title' => $stale->count().($stale->count() === 1 ? ' obligación con' : ' obligaciones con').' más de 30 días de atraso', 'detail' => 'Riesgo de multa o recargo. Prioriza su regularización.'];
        }

        return $alerts;
    }

    /**
     * @param  Collection<int, covariant array<string, mixed>>  $obligations
     * @param  Collection<int, covariant array<string, mixed>>  $open
     * @return list<array{title: string, detail: string}>
     */
    private function suggestions($obligations, $open): array
    {
        $suggestions = [];
        $unassigned = $open->filter(fn (array $item): bool => $item['responsible'] === null)->count();
        $soon = $open->where('state', 'due_soon');
        $worst = $obligations->where('state', 'overdue')->groupBy('company')->sortByDesc(fn ($group): int => $group->count())->keys()->first();

        if ($soon->isNotEmpty()) {
            $suggestions[] = ['title' => 'Prioriza '.$soon->count().' obligaciones próximas', 'detail' => 'Vencen en los próximos 7 días; confirma que sus responsables ya las trabajan.'];
        }

        if ($unassigned > 0) {
            $suggestions[] = ['title' => $unassigned.($unassigned === 1 ? ' obligación sin responsable' : ' obligaciones sin responsable'), 'detail' => 'Asígnalas a un contador encargado para que no pasen la fecha.'];
        }

        if ($worst !== null) {
            $suggestions[] = ['title' => 'Revisa '.$worst, 'detail' => 'Es la empresa con más obligaciones vencidas en este momento.'];
        }

        return $suggestions;
    }
}
