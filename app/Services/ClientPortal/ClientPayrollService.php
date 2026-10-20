<?php

declare(strict_types=1);

namespace App\Services\ClientPortal;

use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The payroll side of a client company in its portal: the pay periods the office is processing with their status, the
 * headcount and the totals the owner has to fund. Individual salaries never reach the portal. Money is in cents.
 */
class ClientPayrollService
{
    public const STATUS_LABELS = [
        'open' => 'En preparación', 'calculated' => 'Calculada', 'in_review' => 'En revisión', 'submitted' => 'En revisión', 'approved' => 'Aprobada', 'paid' => 'Pagada', 'closed' => 'Cerrada', 'cancelled' => 'Cancelada',
    ];

    /** @return array{periods: list<array<string, mixed>>, next: array<string, mixed>|null, totals: array<string, int>} */
    public function overview(int $companyId): array
    {
        $periods = DB::table('payroll_periods')->where('company_id', $companyId)->where('status', '!=', 'cancelled')->orderByDesc('starts_on')->limit(12)->get()
            ->map(fn (stdClass $period): array => [
                'id' => (int) $period->id, 'name' => (string) $period->name, 'starts_on' => (string) $period->starts_on, 'ends_on' => (string) $period->ends_on, 'payment_date' => (string) $period->payment_date,
                'status' => (string) $period->status, 'status_label' => self::STATUS_LABELS[$period->status] ?? ucfirst((string) $period->status), 'employees' => (int) $period->employees_count,
                'net_cents' => (int) $period->net_cents, 'cost_cents' => (int) $period->gross_cents + (int) $period->employer_cents, 'paid' => $period->paid_at !== null,
            ])->values();
        $next = $periods->first(fn (array $period): bool => ! $period['paid'] && ! in_array($period['status'], ['closed', 'paid'], true));
        $year = now()->year;

        return [
            'periods' => array_values($periods->all()),
            'next' => $next,
            'totals' => [
                'employees' => (int) ($periods->first()['employees'] ?? 0),
                'paid_year_cents' => (int) $periods->filter(fn (array $period): bool => $period['paid'] && str_starts_with($period['payment_date'], (string) $year))->sum('net_cents'),
                'open_periods' => $periods->filter(fn (array $period): bool => ! in_array($period['status'], ['closed', 'paid'], true))->count(),
            ],
        ];
    }
}
