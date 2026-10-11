<?php

declare(strict_types=1);

namespace App\Services\Platform\Billing;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Commercial metrics of the platform. MRR counts only recurring subscription revenue (monthly equivalent of the base
 * plan of active, past-due and grace subscriptions, after discounts); trials and one-time charges are excluded.
 */
class BillingMetricsService
{
    /** @return array{mrr: float, arr: float, currency: string, subscriptions: int, previous_mrr: float, change_percent: float|null} */
    public function mrr(): array
    {
        $now = $this->mrrAt(now(), true);
        $previous = $this->mrrAt(now()->subMonthNoOverflow()->endOfMonth(), false);

        return ['mrr' => round($now['total'], 2), 'arr' => round($now['total'] * 12, 2), 'currency' => 'GTQ', 'subscriptions' => $now['count'],
            'previous_mrr' => round($previous['total'], 2), 'change_percent' => $previous['total'] > 0 ? round(($now['total'] - $previous['total']) * 100 / $previous['total'], 1) : null];
    }

    /** @return array{open: float, past_due: float, collected_month: float, open_invoices: int, past_due_invoices: int} */
    public function receivables(): array
    {
        $row = DB::table('saas_invoices')->selectRaw("COALESCE(SUM(total) FILTER (WHERE status = 'open'), 0) as open, COALESCE(SUM(total) FILTER (WHERE status = 'past_due'), 0) as past_due,
            CAST(COUNT(*) FILTER (WHERE status = 'open') AS BIGINT) as open_count, CAST(COUNT(*) FILTER (WHERE status = 'past_due') AS BIGINT) as past_due_count")->first();
        $collected = (float) DB::table('payments')->where('status', 'succeeded')->where('paid_at', '>=', now()->startOfMonth())->sum('amount');

        return ['open' => (float) ($row->open ?? 0), 'past_due' => (float) ($row->past_due ?? 0), 'collected_month' => $collected,
            'open_invoices' => (int) ($row->open_count ?? 0), 'past_due_invoices' => (int) ($row->past_due_count ?? 0)];
    }

    /** @return array{total: float, count: int} */
    private function mrrAt(CarbonInterface $moment, bool $current): array
    {
        $query = DB::table('subscriptions')->join('subscription_items', 'subscription_items.subscription_id', '=', 'subscriptions.id')
            ->join('plan_prices', 'plan_prices.id', '=', 'subscription_items.plan_price_id')->leftJoin('plan_versions', 'plan_versions.id', '=', 'subscriptions.plan_version_id')
            ->where('subscription_items.item_type', 'base_plan')->where('subscriptions.created_at', '<=', $moment);
        if ($current) {
            $query->whereIn('subscriptions.status', ['active', 'past_due', 'grace_period']);
        } else {
            $query->whereNotIn('subscriptions.status', ['incomplete', 'trialing'])->where(fn ($q) => $q->whereNull('subscriptions.ended_at')->orWhere('subscriptions.ended_at', '>', $moment));
        }
        $total = 0.0;
        $count = 0;
        foreach ($query->get(['plan_prices.billing_period', 'plan_prices.amount', 'plan_versions.monthly_price', 'plan_versions.annual_price', 'subscriptions.discount_percent', 'subscriptions.provider']) as $row) {
            if ($row->provider === 'internal') {
                continue;
            }
            $amount = $row->billing_period === 'annual' ? (float) ($row->annual_price ?? $row->amount) / 12 : (float) ($row->monthly_price ?? $row->amount);
            $total += $amount * (1 - (float) $row->discount_percent / 100);
            $count++;
        }

        return ['total' => $total, 'count' => $count];
    }
}
