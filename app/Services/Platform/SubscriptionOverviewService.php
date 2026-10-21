<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\Organization;
use App\Services\Tenancy\TenantDatabaseContext;
use App\Services\WorkManagement\OfficeInvitationService;
use App\Support\PlatformStatus;
use Illuminate\Support\Facades\DB;

/** What the firm has contracted with Atlantia and how much of it is in use. Read only; the plan itself is changed with sales/support. */
class SubscriptionOverviewService
{
    /** Module shown in the plan screen => the feature that switches it on (null = included in every plan). */
    private const MODULES = [
        ['Gestión de clientes (CRM)', null],
        ['Contabilidad y cumplimiento', 'basic_accounting'],
        ['Reportes ejecutivos', 'advanced_reports'],
        ['Multi-oficina', 'multi_office'],
        ['Auditoría y trazabilidad', 'audit_log'],
        ['Integraciones y API', 'api_access'],
        ['Nómina', 'payroll'],
        ['Soporte prioritario', 'priority_support'],
    ];

    public function __construct(private EntitlementResolverService $entitlements) {}

    /** @return array<string, mixed> */
    public function overview(Organization $organization): array
    {
        $resolved = $this->entitlements->resolve($organization);
        $subscription = DB::table('subscriptions')->where('organization_id', $organization->id)->orderByDesc('id')->first();
        $planId = $resolved['plan']->id ?? null;
        $version = $resolved['version'];
        $monthly = $planId ? DB::table('plan_prices')->where('plan_id', $planId)->where('billing_period', 'monthly')->where('currency_code', 'GTQ')->value('amount') : null;
        $annual = $planId ? DB::table('plan_prices')->where('plan_id', $planId)->where('billing_period', 'annual')->where('currency_code', 'GTQ')->value('amount') : null;
        $period = (string) ($resolved['plan']->billing_period ?? 'monthly');
        $price = (float) ($period === 'annual' ? ($annual ?? $monthly) : ($monthly ?? $version->monthly_price ?? 0));
        $limits = $resolved['limits'];

        $storage = (int) DB::table('operational_record_files')->where('organization_id', $organization->id)->sum('size_bytes')
            + (int) DB::table('purchase_document_files')->where('organization_id', $organization->id)->sum('size_bytes')
            + (int) DB::table('journal_entry_documents')->where('organization_id', $organization->id)->sum('size_bytes');
        $usage = [
            ['key' => 'offices', 'label' => 'Oficinas', 'used' => DB::table('offices')->where('organization_id', $organization->id)->whereNull('deleted_at')->count(), 'limit' => $limits['offices']['value'] ?? null, 'unit' => ''],
            // Counted as the invitation and company limits enforce them: client-portal users take no seat and client
            // companies are the plan's "empresas cliente" (active_cases).
            ['key' => 'users', 'label' => 'Usuarios', 'used' => app(OfficeInvitationService::class)->seatsInUse($organization), 'limit' => $limits['users']['value'] ?? null, 'unit' => ''],
            ['key' => 'companies', 'label' => 'Empresas cliente', 'used' => DB::table('companies')->where('organization_id', $organization->id)->count(), 'limit' => $limits['active_cases']['value'] ?? null, 'unit' => ''],
            ['key' => 'storage', 'label' => 'Almacenamiento', 'used' => round($storage / 1_073_741_824, 2), 'limit' => isset($limits['storage_bytes']['value']) ? round($limits['storage_bytes']['value'] / 1_073_741_824, 2) : null, 'unit' => 'GB'],
            ['key' => 'ai', 'label' => 'Consultas a Luzma IA', 'used' => DB::table('luzma_messages')->where('organization_id', $organization->id)->where('role', 'user')->where('created_at', '>=', now()->startOfMonth())->count(), 'limit' => $limits['ai_queries']['value'] ?? null, 'unit' => ''],
        ];

        $profile = DB::table('organization_billing_profiles')->where('organization_id', $organization->id)->first();
        $payments = DB::table('payments')->where('organization_id', $organization->id)->orderByDesc('created_at')->limit(8)->get(['paid_at', 'created_at', 'amount', 'status', 'reference', 'currency_code']);

        return [
            'plan' => [
                'name' => $resolved['plan']->name ?? 'Sin plan',
                'tagline' => $resolved['plan']->tagline ?? ($resolved['plan']->description ?? null),
                'status' => $subscription->status ?? 'none',
                'status_label' => PlatformStatus::SUBSCRIPTION_LABELS[$subscription->status ?? ''] ?? 'Sin suscripción',
                // A renewal, end of trial or overdue period is paid from the checkout screen, which charges the open invoice.
                'can_pay' => $subscription !== null && $subscription->status !== 'incomplete' && app(TenantDatabaseContext::class)->runAsSystem(fn (): bool => DB::table('saas_invoices')
                    ->where('organization_id', $organization->id)->where('subscription_id', $subscription->id)->whereIn('status', ['open', 'past_due'])->exists()),
                'billing_period' => $period,
                'price' => $price,
                'currency' => $resolved['plan']->currency_code ?? 'GTQ',
                'period_ends_at' => $subscription->current_period_ends_at ?? null,
                'period_starts_at' => $subscription->current_period_starts_at ?? null,
                'cancel_at_period_end' => (bool) ($subscription->cancel_at_period_end ?? false),
                'provider' => $subscription->provider ?? null,
                'discount_percent' => (float) ($subscription->discount_percent ?? 0),
            ],
            'usage' => $usage,
            'modules' => array_map(fn (array $module): array => ['name' => $module[0], 'included' => $module[1] === null || $this->entitlements->has($organization, $module[1])], self::MODULES),
            'available' => DB::table('plans')->where('is_active', true)->orderBy('display_order')->get()->map(function (object $plan) use ($planId): array {
                $prices = DB::table('plan_prices')->where('plan_id', $plan->id)->where('is_active', true)->where('currency_code', 'GTQ')->pluck('amount', 'billing_period');

                return [
                    'name' => $plan->name,
                    'tagline' => $plan->tagline ?? $plan->description,
                    'monthly' => isset($prices['monthly']) ? (float) $prices['monthly'] : null,
                    'annual' => isset($prices['annual']) ? (float) $prices['annual'] : null,
                    'highlights' => json_decode((string) ($plan->highlights ?? '[]'), true) ?: [],
                    'current' => $plan->id === $planId,
                ];
            })->values()->all(),
            'payments' => $payments->map(fn (object $payment): array => [
                'date' => $payment->paid_at ?? $payment->created_at,
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency_code,
                'status' => $payment->status,
                'reference' => $payment->reference,
            ])->all(),
            'billing' => $profile ? ['name' => $profile->name, 'nit' => $profile->nit, 'address' => $profile->address, 'email' => $profile->email] : null,
        ];
    }
}
