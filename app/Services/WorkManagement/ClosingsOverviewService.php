<?php

declare(strict_types=1);

namespace App\Services\WorkManagement;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Period-close progress of every client company for the director: reconciliation, reviews, approval, fiscal review and final close. */
class ClosingsOverviewService
{
    private const STEPS = [
        'reconciliation' => 'Conciliación',
        'review' => 'Revisión contable',
        'approval' => 'Aprobación',
        'fiscal' => 'Revisión fiscal',
        'close' => 'Cierre final',
    ];

    public function __construct(private WorkDashboardService $dashboard) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function payload(Organization $organization, User $user, array $filters = []): array
    {
        $executive = $this->dashboard->dashboard($organization, $user, 'executive', $filters);
        $rows = collect($executive['companies']);
        $periodIds = $rows->pluck('period_id')->filter()->all();

        $periods = DB::table('accounting_periods')->whereIn('id', $periodIds)->get(['id', 'name', 'starts_on', 'ends_on', 'closed_at'])->keyBy('id');
        $reviews = DB::table('company_period_reviews')->whereIn('accounting_period_id', $periodIds)->whereNull('deleted_at')
            ->orderBy('id')->get()->keyBy('accounting_period_id');
        $statuses = DB::table('company_period_work_statuses')->whereIn('accounting_period_id', $periodIds)->get()->keyBy('accounting_period_id');
        $approvedReconciliations = DB::table('bank_reconciliations')->whereIn('company_id', $rows->pluck('id')->all())->get(['company_id', 'status', 'cutoff_date'])->groupBy('company_id');
        $names = DB::table('users')->whereIn('id', $reviews->flatMap(fn (object $review): array => [$review->submitted_by, $review->reviewer_id, $review->first_level_approved_by, $review->approved_by, $review->fiscal_reviewed_by])->filter()->unique()->all())->pluck('name', 'id');

        $companies = $rows->map(function (array $company) use ($periods, $reviews, $statuses, $approvedReconciliations, $names): array {
            $period = $company['period_id'] ? $periods->get($company['period_id']) : null;
            $review = $company['period_id'] ? $reviews->get($company['period_id']) : null;
            $work = $company['period_id'] ? $statuses->get($company['period_id']) : null;
            $reconciliations = $period
                ? $approvedReconciliations->get($company['id'], collect())->filter(fn (object $item): bool => $item->cutoff_date >= $period->starts_on && $item->cutoff_date <= $period->ends_on)
                : collect();
            $closed = $period?->closed_at !== null;
            $changes = $review && ($review->status === 'changes_requested' || $review->fiscal_status === 'changes_requested');

            $done = [
                'reconciliation' => $reconciliations->isNotEmpty() && $reconciliations->every(fn (object $item): bool => $item->status === 'approved'),
                'review' => $closed || ($review && ($review->first_level_approved_at !== null || $review->status === 'approved')),
                'approval' => $closed || ($review && $review->status === 'approved'),
                'fiscal' => $closed || ($review && $review->fiscal_status === 'approved'),
                'close' => $closed,
            ];
            $firstOpen = collect(array_keys(self::STEPS))->first(fn (string $key): bool => ! $done[$key]);
            $started = $period !== null && ($review !== null || $reconciliations->isNotEmpty() || ($company['progress'] ?? 0) > 0);
            $steps = [];

            foreach (self::STEPS as $key => $label) {
                $state = $done[$key] ? 'done' : ($key === $firstOpen && $started ? ($changes || ($work?->blocked_tasks ?? 0) > 0 ? 'blocked' : 'active') : 'pending');
                $steps[] = ['key' => $key, 'label' => $label, 'state' => $state];
            }

            $completed = count(array_filter($done));
            $blockers = [];

            if ($changes) {
                $blockers[] = ['title' => 'Cambios solicitados en la revisión', 'detail' => 'El revisor pidió correcciones antes de continuar.'];
            }

            if (($work?->blocked_tasks ?? 0) > 0) {
                $blockers[] = ['title' => $work->blocked_tasks.' tarea(s) bloqueada(s)', 'detail' => 'Hay trabajo detenido que impide avanzar el cierre.'];
            }

            return [
                'id' => (int) $company['id'],
                'name' => $company['name'],
                'tax_id' => $company['tax_id'],
                'office' => $company['office_name'],
                'period' => $period?->name,
                'period_ends_on' => $period?->ends_on,
                'accountant' => $company['accountant'],
                'assistant' => $company['assistant'],
                'reviewer' => $review?->reviewer_id ? ($names[$review->reviewer_id] ?? null) : null,
                'steps' => $steps,
                'stage' => $closed ? 'close' : ($firstOpen ?? 'close'),
                'progress' => $period === null ? 0 : (int) round($completed / count(self::STEPS) * 100),
                'state' => $period === null ? 'pending' : ($closed ? 'closed' : ($blockers !== [] ? 'blocked' : ($completed > 0 || $started ? 'progress' : 'pending'))),
                'blockers' => $blockers,
                'history' => collect([
                    ['label' => 'Enviado a revisión', 'at' => $review?->submitted_at, 'by' => $names[$review?->submitted_by ?? 0] ?? null],
                    ['label' => 'Revisión contable aprobada', 'at' => $review?->first_level_approved_at, 'by' => $names[$review?->first_level_approved_by ?? 0] ?? null],
                    ['label' => 'Aprobación superior', 'at' => $review?->approved_at, 'by' => $names[$review?->approved_by ?? 0] ?? null],
                    ['label' => 'Revisión fiscal aprobada', 'at' => $review?->fiscal_reviewed_at, 'by' => $names[$review?->fiscal_reviewed_by ?? 0] ?? null],
                    ['label' => 'Cierre final', 'at' => $period?->closed_at, 'by' => null],
                ])->all(),
            ];
        })->values();

        return [
            'summary' => [
                'closed' => $companies->where('state', 'closed')->count(),
                'pending' => $companies->where('state', '!=', 'closed')->count(),
                'progress' => $companies->isEmpty() ? 0 : (int) round($companies->avg('progress')),
                'in_review' => $companies->whereIn('stage', ['review', 'approval', 'fiscal'])->where('state', '!=', 'closed')->where('progress', '>', 0)->count(),
                'blocked' => $companies->where('state', 'blocked')->count(),
            ],
            'steps' => array_values(self::STEPS),
            'companies' => $companies->all(),
            'offices' => $companies->pluck('office')->unique()->sort()->values()->all(),
            'period' => $executive['filters']['period'] ?? null,
        ];
    }
}
