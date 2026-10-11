<?php

declare(strict_types=1);

namespace App\Services\WorkManagement;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Read-only portfolio of the firm's client companies for the director: status, risk, next obligation and contact data. */
class CompanyPortfolioService
{
    public function __construct(private WorkDashboardService $dashboard) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{summary: array<string, int>, companies: list<array<string, mixed>>, offices: list<string>, accountants: list<string>}
     */
    public function payload(Organization $organization, User $user, array $filters = []): array
    {
        $executive = $this->dashboard->executiveSnapshot($organization, $user, $filters);
        $rows = collect($executive['companies']);
        $ids = $rows->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $today = now()->toDateString();

        $details = DB::table('companies')->whereIn('id', $ids)
            ->get(['id', 'legal_representative', 'contact_name', 'phone', 'email', 'address', 'created_at'])->keyBy('id');
        $obligations = DB::table('fiscal_obligations')->whereIn('company_id', $ids)
            ->orderBy('due_on')
            ->get(['company_id', 'name', 'due_on', 'status', 'filed_on', 'completed_at'])
            ->groupBy('company_id');
        $tasks = DB::table('work_tasks')->whereIn('company_id', $ids)->whereNull('deleted_at')
            ->whereNotIn('status', ['completed', 'approved', 'cancelled'])
            ->orderBy('due_at')
            ->get(['company_id', 'title', 'status', 'due_at'])
            ->groupBy('company_id');

        $companies = $rows->map(function (array $company) use ($details, $obligations, $tasks, $today): array {
            $detail = $details->get($company['id']);
            $list = $obligations->get($company['id'], collect())->map(fn (object $obligation): array => [
                'name' => $obligation->name,
                'due_on' => $obligation->due_on,
                'done' => $obligation->filed_on !== null || $obligation->completed_at !== null,
                'overdue' => $obligation->filed_on === null && $obligation->completed_at === null && $obligation->due_on < $today,
            ]);
            $pending = $list->where('done', false);
            $overdueObligations = $list->where('overdue', true)->count();
            $overdueTasks = (int) ($company['overdue_tasks'] ?? 0);
            $hasPeriod = $company['period_name'] !== null;
            $risk = $company['status'] === 'blocked' || $overdueTasks > 0 || $overdueObligations > 0
                ? 'high'
                : ($hasPeriod && ($company['pending_tasks'] > 0 || $company['progress'] < 50) ? 'medium' : 'low');

            return [
                'id' => (int) $company['id'],
                'name' => $company['name'],
                'tax_id' => $company['tax_id'],
                'office' => $company['office_name'],
                'accountant' => $company['accountant'],
                'assistant' => $company['assistant'],
                'period_name' => $company['period_name'],
                'progress' => (int) $company['progress'],
                'pending_tasks' => (int) $company['pending_tasks'],
                'overdue_tasks' => $overdueTasks,
                'overdue_obligations' => $overdueObligations,
                'compliance' => ! $hasPeriod
                    ? 'onboarding'
                    : ($overdueTasks > 0 || $overdueObligations > 0
                        ? 'late'
                        : (in_array($company['status'], ['ready_for_review', 'in_review', 'changes_requested'], true) ? 'review' : 'operational')),
                'risk' => $risk,
                'next_obligation' => $pending->first()['due_on'] ?? null,
                'obligations' => $list->take(6)->values()->all(),
                'tasks' => ($tasks->get($company['id']) ?? collect())->take(4)->map(fn (object $task): array => [
                    'title' => $task->title,
                    'status' => $task->status,
                    'due_at' => $task->due_at,
                ])->values()->all(),
                'client_since' => $detail?->created_at,
                'contact' => [
                    'name' => $detail->contact_name ?? $detail?->legal_representative,
                    'phone' => $detail?->phone,
                    'email' => $detail?->email,
                    'address' => $detail?->address,
                ],
            ];
        })->values();

        return [
            'summary' => [
                'total' => $companies->count(),
                'active' => $companies->where('compliance', '!=', 'onboarding')->count(),
                'at_risk' => $companies->where('risk', 'high')->count(),
                'overdue_obligations' => $companies->where('overdue_obligations', '>', 0)->count(),
                'onboarding' => $companies->where('compliance', 'onboarding')->count(),
            ],
            'companies' => array_values($companies->all()),
            'offices' => $this->distinct($companies, 'office'),
            'accountants' => $this->distinct($companies, 'accountant'),
        ];
    }

    /**
     * @param  Collection<int, covariant array<string, mixed>>  $companies
     * @return list<string>
     */
    private function distinct(Collection $companies, string $key): array
    {
        return array_values(array_map('strval', $companies->pluck($key)->filter()->unique()->sort()->all()));
    }
}
