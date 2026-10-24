<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\User;
use App\Services\Platform\Billing\BillingMetricsService;
use App\Support\PlatformIssuer;
use App\Support\PlatformStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use stdClass;
use Throwable;

/**
 * Data of the Control Center home (SaaSDashboardData) and the attention list (SaaSAttentionService), built only from
 * the existing services and tables. A failing source shows "no disponible" — never a fake 0 or a healthy state.
 */
class SaaSDashboardService
{
    public function __construct(
        private BillingMetricsService $billing,
        private PlatformHealthService $health,
        private LuzmaPlatformService $luzma,
        private UsageMeterService $usage,
    ) {}

    /** @return array<string, mixed> */
    public function data(PlatformAuthorization $authorization, User $user): array
    {
        $can = fn (string $permission): bool => $authorization->can($user, $permission);

        return [
            'metrics' => $this->metrics($can('saas.billing.view')),
            'platform_health' => $this->safe('health', fn () => $this->health->snapshot()),
            'attention_items' => $this->safe('attention', fn () => $this->attention()),
            'recent_organizations' => $this->safe('organizations', fn () => $this->recentOrganizations()),
            'recent_activity' => $this->safe('activity', fn () => $this->recentActivity()),
            'luzma_status' => $can('saas.luzma.view') ? $this->safe('luzma', fn () => $this->luzma->status()) : null,
            'usage_summary' => $this->safe('usage', fn () => $this->usageSummary()),
            'billing_at_risk' => $can('saas.billing.view') ? $this->safe('billing_risk', fn () => $this->billingAtRisk()) : null,
            'critical_incidents' => $this->safe('incidents', fn () => app(IncidentManagementService::class)->list(true)),
            'last_updated_at' => now()->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function metrics(bool $billing): array
    {
        $count = fn (array $statuses): int => DB::table('organizations')->where('organizations.id', '<>', PlatformIssuer::organizationId())->whereNull('deleted_at')->whereIn('status', $statuses)->count();
        $mrr = $billing ? $this->safe('mrr', fn () => $this->billing->mrr()) : null;
        $previousMonth = now()->subMonthNoOverflow()->endOfMonth();

        return [
            'organizations_active' => $count(['active', 'past_due', 'grace_period']),
            'organizations_active_previous' => DB::table('organizations')->where('organizations.id', '<>', PlatformIssuer::organizationId())->whereNull('deleted_at')->where('created_at', '<=', $previousMonth)->whereIn('status', ['active', 'past_due', 'grace_period'])->count(),
            'trials_active' => DB::table('subscriptions')->where('status', 'trialing')->count(),
            'subscriptions_active' => DB::table('subscriptions')->whereIn('status', ['active', 'past_due', 'grace_period'])->where('provider', '!=', 'internal')->count(),
            'past_due_subscriptions' => DB::table('subscriptions')->whereIn('status', ['past_due', 'grace_period'])->count(),
            'suspended_organizations' => $count(['suspended']),
            'monthly_recurring_revenue' => $mrr,
            'failed_jobs' => $this->safe('failed_jobs', fn () => DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count()),
            'critical_incidents' => DB::table('system_incidents')->whereNotIn('status', ['resolved', 'postmortem'])->whereIn('severity', ['high', 'critical'])->count(),
            'support_requests' => DB::table('support_tickets')->whereIn('status', ['open', 'in_progress', 'waiting_internal'])->count() + DB::table('support_access_requests')->where('status', 'requested')->count(),
            'service_health' => $this->safe('service_health', function (): array {
                $snapshot = $this->health->snapshot();
                $checked = array_values(array_filter($snapshot, fn (array $service): bool => $service['status'] !== 'unknown'));
                $uptimes = array_values(array_filter(array_column($snapshot, 'uptime'), fn ($value): bool => $value !== null));

                return ['healthy' => count(array_filter($snapshot, fn (array $service): bool => $service['status'] === 'healthy')), 'total' => count($snapshot), 'checked' => count($checked),
                    'uptime' => $uptimes === [] ? null : round(array_sum($uptimes) / count($uptimes), 2)];
            }),
            'sparklines' => $this->sparklines(),
        ];
    }

    /**
     * SaaSAttentionService: what needs someone today.
     *
     * @return list<array{key: string, severity: string, count: int, title: string, subtitle: string, destination: string}>
     */
    public function attention(): array
    {
        $items = [];
        $add = function (string $key, string $severity, int $count, string $title, string $subtitle, string $destination) use (&$items): void {
            if ($count > 0) {
                $items[] = compact('key', 'severity', 'count', 'title', 'subtitle', 'destination');
            }
        };
        $add('grace', 'high', DB::table('subscriptions')->where('status', 'grace_period')->count(), 'organizaciones en período de gracia', 'Requieren seguimiento de facturación', '/control-center/subscriptions?status=grace_period');
        $add('past_due', 'medium', DB::table('subscriptions')->where('status', 'past_due')->count(), 'suscripciones con pago vencido', 'Facturas sin pagar después de su vencimiento', '/control-center/billing?status=past_due');
        $add('jobs', 'high', DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count(), 'jobs fallidos pendientes de revisión', 'En las últimas 24 horas', '/control-center/jobs');
        $add('incidents', 'critical', DB::table('system_incidents')->whereNotIn('status', ['resolved', 'postmortem'])->whereIn('severity', ['high', 'critical'])->count(), 'incidente(s) crítico(s) abierto(s)', 'Afectan a organizaciones cliente', '/control-center/incidents');
        $add('support_access', 'medium', DB::table('support_access_requests')->where('status', 'requested')->count(), 'solicitudes de acceso de soporte pendientes', 'Requieren aprobación de otra persona', '/control-center/support-access');
        $add('tickets', 'medium', DB::table('support_tickets')->whereIn('priority', ['urgent', 'critical'])->whereNotIn('status', ['resolved', 'closed'])->count(), 'tickets urgentes sin resolver', 'Clientes esperando respuesta', '/control-center/support/tickets');
        $add('down', 'critical', count(array_filter($this->health->snapshot(), fn (array $service): bool => $service['status'] === 'down')), 'servicio(s) caído(s)', 'Revisa el estado de servicios', '/control-center/services');
        $add('webhooks', 'low', DB::table('webhook_endpoints')->where('status', 'disabled')->where('disabled_reason', 'like', 'Se deshabilitó automáticamente%')->count(), 'webhooks de clientes deshabilitados por fallas', 'Sus receptores no responden', '/control-center/integrations');
        $add('over_limit', 'medium', DB::table('subscriptions')->whereNotNull('over_limit_since')->count(), 'organizaciones por encima del límite de su plan', 'Bloqueadas para reducir de plan', '/control-center/usage');
        $add('fel', 'high', DB::table('fel_documents')->where('status', 'processing')->where('processing_at', '<', now()->subMinutes(30))->count(), 'documentos FEL atascados en certificación', 'Usa la recuperación de FEL', '/control-center/jobs');
        $add('luzma', 'medium', DB::table('luzma_messages')->where('role', 'assistant')->where('status', '<>', 'ok')->where('created_at', '>=', now()->subHour())->count() >= 5 ? 1 : 0, 'Luzma con errores frecuentes', 'Más de 5 respuestas con error en la última hora', '/control-center/luzma');
        $order = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        usort($items, fn (array $a, array $b): int => $order[$a['severity']] <=> $order[$b['severity']]);

        return $items;
    }

    /** @return list<array<string, mixed>> */
    public function billingAtRisk(): array
    {
        return array_values(DB::table('saas_invoices')->join('organizations', 'organizations.id', '=', 'saas_invoices.organization_id')
            ->leftJoin('subscriptions', 'subscriptions.id', '=', 'saas_invoices.subscription_id')->whereIn('saas_invoices.status', ['open', 'past_due'])
            ->where('saas_invoices.due_on', '<', now()->toDateString())->orderBy('saas_invoices.due_on')->limit(10)
            ->get(['saas_invoices.*', 'organizations.name as organization_name', 'subscriptions.status as subscription_status', 'subscriptions.grace_ends_at'])
            ->map(fn (stdClass $row): array => ['organization' => (string) $row->organization_name, 'invoice' => (string) $row->number, 'amount' => (float) $row->total, 'currency' => (string) $row->currency_code,
                'days_overdue' => (int) Carbon::parse($row->due_on)->diffInDays(now()->startOfDay()), 'subscription_status' => $row->subscription_status,
                'subscription_label' => PlatformStatus::SUBSCRIPTION_LABELS[$row->subscription_status ?? ''] ?? '—',
                'grace_period_remaining' => $row->grace_ends_at === null ? null : max(0, (int) now()->startOfDay()->diffInDays(Carbon::parse($row->grace_ends_at), false)),
                'destination' => '/control-center/organizations/'.$row->organization_id.'?tab=billing'])->all());
    }

    /** @return array<string, mixed> */
    public function usageSummary(): array
    {
        $totals = ['users' => [0, 0], 'active_cases' => [0, 0], 'storage_bytes' => [0, 0], 'ai_queries' => [0, 0]];
        $unlimited = [];
        $alerts = 0;
        foreach (DB::table('organizations')->where('organizations.id', '<>', PlatformIssuer::organizationId())->whereNull('deleted_at')->whereIn('status', ['active', 'trial', 'past_due', 'grace_period'])->pluck('id') as $organizationId) {
            foreach ($this->usage->usage((int) $organizationId) as $row) {
                if (! isset($totals[$row['metric']])) {
                    continue;
                }
                $totals[$row['metric']][0] += $row['value'];
                if ($row['limit'] === null) {
                    $unlimited[$row['metric']] = true;
                } else {
                    $totals[$row['metric']][1] += $row['limit'];
                }
                if (in_array($row['status'], ['high', 'at_limit', 'over_limit'], true)) {
                    $alerts++;
                }
            }
        }
        $labels = ['users' => 'Usuarios', 'active_cases' => 'Empresas', 'storage_bytes' => 'Storage', 'ai_queries' => 'Consultas Luzma (mes)'];

        return ['metrics' => array_map(fn (string $metric): array => ['metric' => $metric, 'label' => $labels[$metric], 'value' => $totals[$metric][0],
            'limit' => isset($unlimited[$metric]) ? null : $totals[$metric][1], 'unlimited_tenants' => isset($unlimited[$metric]),
            'percent' => isset($unlimited[$metric]) || $totals[$metric][1] === 0 ? null : (int) floor($totals[$metric][0] * 100 / $totals[$metric][1])], array_keys($totals)),
            'tenants_near_limit' => $alerts];
    }

    /** @return list<array<string, mixed>> */
    private function recentOrganizations(): array
    {
        return app(OrganizationManagementService::class)->list([], 6);
    }

    /** @return list<array{action: string, actor: string|null, subtitle: string|null, at: string}> */
    private function recentActivity(): array
    {
        $names = DB::table('organizations')->pluck('name', 'id');

        return array_values(DB::table('platform_audit_events')->orderByDesc('id')->limit(8)->get()->map(fn (stdClass $row): array => [
            'action' => (string) $row->action, 'actor' => $row->actor_name,
            'subtitle' => trim(($row->organization_id === null ? '' : ($names[$row->organization_id] ?? '')).($row->reason ? ' · '.mb_strimwidth((string) $row->reason, 0, 60, '…') : ''), ' ·') ?: null,
            'at' => Carbon::parse($row->created_at)->toIso8601String()])->all());
    }

    /** @return array<string, array<int, int>> */
    private function sparklines(): array
    {
        $days = collect(range(13, 0))->map(fn (int $offset): string => now()->subDays($offset)->toDateString());
        $series = fn (string $table, string $column, ?\Closure $scope = null): array => $days->map(fn (string $day): int => (int) DB::table($table)
            ->when($scope, $scope)->whereRaw("CAST({$column} AS date) = ?", [$day])->count())->values()->all();

        return [
            'organizations' => $series('organizations', 'created_at'),
            'payments' => $series('payments', 'paid_at', fn ($q) => $q->where('status', 'succeeded')),
            'failed_jobs' => $series('failed_jobs', 'failed_at'),
            'incidents' => $series('system_incidents', 'detected_at'),
            'past_due' => $series('saas_invoices', 'updated_at', fn ($q) => $q->where('status', 'past_due')),
        ];
    }

    private function safe(string $source, callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $exception) {
            Log::warning('Control Center source unavailable', ['source' => $source, 'error' => $exception->getMessage()]);

            return ['unavailable' => true, 'message' => 'No disponible'];
        }
    }
}
