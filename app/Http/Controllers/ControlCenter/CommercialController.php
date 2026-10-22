<?php

declare(strict_types=1);

namespace App\Http\Controllers\ControlCenter;

use App\Models\User;
use App\Services\Fel\FelIssuerSettings;
use App\Services\Platform\Billing\BillingMetricsService;
use App\Services\Platform\Billing\SaaSBillingService;
use App\Services\Platform\Billing\SubscriptionService;
use App\Services\Platform\PlanService;
use App\Support\PlatformStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Response;
use stdClass;

/** Plans and versions, subscriptions, SaaS invoices and payments. */
class CommercialController extends ControlCenterController
{
    public function plans(Request $request, PlanService $plans): Response
    {
        $user = $this->staff($request, 'saas.plans.view');

        return $this->render($request, 'plans', [
            'plans' => $plans->catalog(),
            'features' => DB::table('features')->orderBy('module')->orderBy('name')->get(['code', 'name', 'module', 'release_stage'])->all(),
            'metrics' => PlanService::METRICS,
            'can_manage' => $this->authorization->can($user, 'saas.plans.manage'),
        ]);
    }

    public function storePlan(Request $request, PlanService $plans): RedirectResponse
    {
        $user = $this->staff($request, 'saas.plans.manage');
        $code = (string) $request->validate(['code' => 'required|string|regex:/^[a-z0-9_]{3,30}$/'])['code'];
        $plans->createPlan($user, ['code' => $code, ...$this->planData($request)]);

        return back()->with('workspace_notice', 'Plan creado como inactivo con su versión 1 en borrador. Publícala y actívalo para venderlo.');
    }

    public function storeVersion(Request $request, int $plan, PlanService $plans): RedirectResponse
    {
        $user = $this->staff($request, 'saas.plans.manage');
        $note = (string) $request->validate(['change_note' => 'required|string|min:5|max:500'])['change_note'];
        $plans->draftVersion($user, $plan, $this->planData($request), $note);

        return back()->with('workspace_notice', 'Nueva versión en borrador. Las suscripciones actuales no cambian.');
    }

    public function versionAction(Request $request, int $version, string $action, PlanService $plans): RedirectResponse
    {
        $user = $this->staff($request, 'saas.plans.manage');
        if ($action === 'publish') {
            $plans->publish($user, $version, $this->confirmSensitive($request, $user));

            return back()->with('workspace_notice', 'Versión publicada: es lo que se vende desde ahora. Las suscripciones existentes conservan su versión.');
        }
        $plans->discardDraft($user, $version);

        return back()->with('workspace_notice', 'Borrador descartado.');
    }

    public function planPresentation(Request $request, int $plan, PlanService $plans): RedirectResponse
    {
        $user = $this->staff($request, 'saas.plans.manage');
        $data = $request->validate(['tagline' => 'nullable|string|max:120', 'highlights' => 'array|max:6', 'highlights.*' => 'nullable|string|max:120', 'is_recommended' => 'boolean',
            'price_prefix' => 'nullable|string|max:30', 'cta_label' => 'nullable|string|max:60']);
        $plans->updatePresentation($user, $plan, ['tagline' => $data['tagline'] ?? null, 'highlights' => array_values(array_map('strval', array_filter($data['highlights'] ?? [], fn ($line): bool => $line !== null))),
            'is_recommended' => (bool) ($data['is_recommended'] ?? false), 'price_prefix' => $data['price_prefix'] ?? null, 'cta_label' => $data['cta_label'] ?? null], $this->reason($request));

        return back()->with('workspace_notice', 'Presentación del plan actualizada en la página de ventas.');
    }

    public function planStatus(Request $request, int $plan, PlanService $plans): RedirectResponse
    {
        $user = $this->staff($request, 'saas.plans.manage');
        $active = $request->boolean('active');
        $plans->setActive($user, $plan, $active, $this->confirmSensitive($request, $user));

        return back()->with('workspace_notice', $active ? 'Plan activado para la venta.' : 'Plan retirado de la venta. Las suscripciones actuales no cambian.');
    }

    public function subscriptions(Request $request, SubscriptionService $subscriptions): Response
    {
        $user = $this->staff($request, 'saas.subscriptions.view');
        $filters = $request->validate(['status' => ['nullable', Rule::in(array_keys(PlatformStatus::SUBSCRIPTION_LABELS))], 'search' => 'nullable|string|max:100']);
        $rows = DB::table('subscriptions')->join('organizations', 'organizations.id', '=', 'subscriptions.organization_id')
            ->leftJoin('subscription_items', fn ($join) => $join->on('subscription_items.subscription_id', '=', 'subscriptions.id')->where('subscription_items.item_type', 'base_plan'))
            ->leftJoin('plan_prices', 'plan_prices.id', '=', 'subscription_items.plan_price_id')->leftJoin('plans', 'plans.id', '=', 'plan_prices.plan_id')
            ->leftJoin('plan_versions', 'plan_versions.id', '=', 'subscriptions.plan_version_id')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('subscriptions.status', $status))
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where('organizations.name', 'ilike', '%'.$search.'%'))
            ->orderByDesc('subscriptions.id')->limit(300)
            ->get(['subscriptions.*', 'organizations.name as organization_name', 'plans.name as plan_name', 'plan_prices.billing_period', 'plan_prices.amount', 'plan_prices.currency_code', 'plan_versions.version as plan_version']);

        return $this->render($request, 'subscriptions', [
            'subscriptions' => array_values($rows->map(fn (stdClass $row): array => $subscriptions->row($row))->all()),
            'filters' => $filters, 'statuses' => PlatformStatus::SUBSCRIPTION_LABELS,
            'prices' => DB::table('plan_prices')->join('plans', 'plans.id', '=', 'plan_prices.plan_id')->where('plans.is_active', true)->where('plan_prices.is_active', true)->orderBy('plans.display_order')
                ->get(['plan_prices.id', 'plans.name', 'plan_prices.billing_period', 'plan_prices.amount'])->map(fn (stdClass $p): array => ['id' => (int) $p->id, 'label' => $p->name.' · '.($p->billing_period === 'annual' ? 'anual' : 'mensual').' · Q'.number_format((float) $p->amount, 2)])->all(),
            'can_manage' => $this->authorization->can($user, 'saas.subscriptions.manage'),
        ]);
    }

    public function preview(Request $request, int $subscription, SubscriptionService $subscriptions): JsonResponse
    {
        $this->staff($request, 'saas.subscriptions.manage');

        return response()->json($subscriptions->preview($subscription, $request->integer('plan_price_id')));
    }

    public function subscriptionAction(Request $request, int $subscription, string $action, SubscriptionService $subscriptions): RedirectResponse
    {
        $user = $this->staff($request, 'saas.subscriptions.manage');
        $message = match ($action) {
            'change' => $subscriptions->changePlan($user, $subscription, (int) $request->validate(['plan_price_id' => 'required|integer'])['plan_price_id'], $this->reason($request)) === 'scheduled'
                ? 'Cambio programado para el fin del período actual.' : 'Plan cambiado. Las funciones y límites se recalcularon.',
            'cancel' => $this->cancel($request, $user, $subscription, $subscriptions),
            'pause' => $this->pause($request, $user, $subscription, $subscriptions),
            'resume' => tap('Suscripción reanudada.', fn () => $subscriptions->resume($user, $subscription, $this->reason($request))),
            'extend-trial' => tap('Prueba extendida.', fn () => $subscriptions->extendTrial($user, $subscription, (string) $request->validate(['until' => 'required|date|after:today'])['until'], $this->reason($request))),
            'discount' => tap('Descuento actualizado (aplica desde la próxima factura).', fn () => $subscriptions->setDiscount($user, $subscription, (float) $request->validate(['discount_percent' => 'required|numeric|min:0|max:100'])['discount_percent'], $this->reason($request))),
            default => abort(404),
        };

        return back()->with('workspace_notice', $message);
    }

    public function createSubscription(Request $request, int $organization, SubscriptionService $subscriptions): RedirectResponse
    {
        $user = $this->staff($request, 'saas.subscriptions.manage');
        $data = $request->validate(['plan_price_id' => 'required|integer', 'trial' => 'boolean', 'discount_percent' => 'nullable|numeric|min:0|max:100']);
        $subscriptions->create($user, $organization, (int) $data['plan_price_id'], (bool) ($data['trial'] ?? false), (float) ($data['discount_percent'] ?? 0));

        return back()->with('workspace_notice', 'Suscripción creada.');
    }

    public function billing(Request $request, SaaSBillingService $billing, BillingMetricsService $metrics): Response
    {
        $user = $this->staff($request, 'saas.billing.view');
        $filters = $request->validate(['status' => ['nullable', Rule::in(array_keys(SaaSBillingService::INVOICE_LABELS))], 'search' => 'nullable|string|max:100']);

        return $this->render($request, 'billing', [
            'invoices' => array_values(DB::table('saas_invoices')->join('organizations', 'organizations.id', '=', 'saas_invoices.organization_id')
                ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('saas_invoices.status', $status))
                ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(fn ($inner) => $inner->where('organizations.name', 'ilike', '%'.$search.'%')->orWhere('saas_invoices.number', 'ilike', '%'.$search.'%')))
                ->orderByDesc('saas_invoices.id')->limit(300)->get(['saas_invoices.*', 'organizations.name as organization_name'])
                ->map(fn (stdClass $row): array => [...$billing->invoiceRow($row), 'lines' => DB::table('saas_invoice_lines')->where('saas_invoice_id', $row->id)->get(['description', 'quantity', 'unit_amount', 'amount'])->all()])->all()),
            'filters' => $filters, 'statuses' => SaaSBillingService::INVOICE_LABELS,
            'summary' => $this->safe(fn () => [...$metrics->mrr(), ...$metrics->receivables()]),
            'credits' => DB::table('saas_credits')->join('organizations', 'organizations.id', '=', 'saas_credits.organization_id')->where('remaining', '>', 0)->orderByDesc('saas_credits.id')->limit(100)
                ->get(['saas_credits.*', 'organizations.name as organization'])->all(),
            'can_manage' => $this->authorization->can($user, 'saas.billing.manage'), 'can_pay' => $this->authorization->can($user, 'saas.payments.manage'),
        ]);
    }

    public function invoiceAction(Request $request, int $invoice, string $action, SaaSBillingService $billing): RedirectResponse
    {
        if (in_array($action, ['manual-payment', 'payment-link'], true)) {
            $user = $this->staff($request, 'saas.payments.manage');
            if ($action === 'payment-link') {
                $link = $billing->paymentLink($user, $invoice);

                return back()->with('platform_reveal', ['kind' => 'link', 'title' => 'Enlace de pago generado', 'value' => $link['url']])->with('workspace_notice', 'Enlace de pago creado: compártelo con el cliente.');
            }
            $data = $request->validate(['reference' => 'required|string|max:120', 'paid_on' => 'required|date|before_or_equal:today']);
            $billing->recordManualPayment($user, $invoice, (string) $data['reference'], (string) $data['paid_on']);

            return back()->with('workspace_notice', 'Pago registrado. La factura quedó pagada y la suscripción al día.');
        }
        $user = $this->staff($request, 'saas.billing.manage');
        $reason = $this->confirmSensitive($request, $user);
        match ($action) {
            'void' => $billing->void($user, $invoice, $reason),
            'refund' => $billing->refund($user, $invoice, $reason),
            default => abort(404),
        };

        return back()->with('workspace_notice', $action === 'void' ? 'Factura anulada.' : 'Reembolso registrado.');
    }

    public function chargeInvoice(Request $request, int $organization, SaaSBillingService $billing): RedirectResponse
    {
        $user = $this->staff($request, 'saas.billing.manage');
        $data = $request->validate(['description' => 'required|string|max:255', 'amount' => 'required|numeric|min:0.01|max:1000000']);
        $subscription = DB::table('subscriptions')->where('organization_id', $organization)->orderByDesc('id')->first() ?? abort(422, 'La organización no tiene suscripción.');
        $invoice = $billing->issue($user, $subscription, 'charge:'.$organization.':'.now()->format('YmdHisv'), [['description' => (string) $data['description'], 'unit_amount' => (float) $data['amount']]], 'one_time');

        return back()->with('workspace_notice', 'Factura '.$invoice->number.' emitida (cargo único: no forma parte del MRR).');
    }

    public function payments(Request $request): Response
    {
        $this->staff($request, 'saas.payments.view');
        $filters = $request->validate(['status' => 'nullable|string|max:20', 'provider' => 'nullable|string|max:30']);

        return $this->render($request, 'payments', [
            'filters' => $filters,
            'payments' => array_values(DB::table('payments')->join('organizations', 'organizations.id', '=', 'payments.organization_id')->leftJoin('saas_invoices', 'saas_invoices.id', '=', 'payments.saas_invoice_id')
                ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('payments.status', $status))->when($filters['provider'] ?? null, fn ($q, $provider) => $q->where('payments.provider', $provider))
                ->orderByDesc('payments.id')->limit(300)->get(['payments.*', 'organizations.name as organization', 'saas_invoices.number as invoice'])
                ->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'organization_id' => (int) $row->organization_id, 'organization' => (string) $row->organization, 'invoice' => $row->invoice,
                    'provider' => (string) $row->provider, 'reference' => $row->reference ?? (str_starts_with((string) $row->provider_payment_id, 'pending:') ? null : $row->provider_payment_id),
                    'amount' => (float) $row->amount, 'currency' => (string) $row->currency_code, 'status' => (string) $row->status, 'attempt' => max(1, (int) $row->attempt_count),
                    'created' => Carbon::parse($row->created_at)->toIso8601String(), 'paid_at' => $row->paid_at === null ? null : Carbon::parse($row->paid_at)->toIso8601String(),
                    'attempts' => DB::table('payment_attempts')->where('payment_id', $row->id)->orderBy('id')->get(['status', 'failure_code', 'failure_message', 'attempted_at'])->all()])->all()),
            // Subscription invoices (FEL) that did not come out on their own, for support to attend.
            'fel_requests' => $this->felRequests(),
            'webhook_events' => DB::table('webhook_events')->orderByDesc('id')->limit(50)->get(['provider', 'provider_event_id', 'event_type', 'status', 'received_at', 'processed_at', 'error_message'])->all(),
        ]);
    }

    /**
     * Subscription invoices (FEL) that did not come out on their own. Each one links to the document of the platform issuer,
     * where the copy sheet for the SAT portal and the registration of the result are.
     *
     * @return list<array<string, mixed>>
     */
    private function felRequests(): array
    {
        $rows = DB::table('fel_subscription_requests as r')->join('payments', 'payments.id', '=', 'r.payment_id')->join('organizations', 'organizations.id', '=', 'payments.organization_id')
            ->where('r.status', '!=', 'generated')->orderBy('r.created_at')->limit(100)
            ->get(['r.payment_id', 'r.status', 'r.error_code', 'r.created_at', 'payments.amount', 'payments.currency_code', 'organizations.id as organization_id', 'organizations.name as organization']);
        $documents = DB::table('fel_documents')->whereIn('payment_id', $rows->pluck('payment_id')->all())->pluck('public_id', 'payment_id');
        $issuer = app(FelIssuerSettings::class)->platformCompanyUuid();

        return array_values($rows->map(fn (stdClass $row): array => ['payment_id' => (int) $row->payment_id, 'organization_id' => (int) $row->organization_id, 'organization' => (string) $row->organization, 'amount' => (float) $row->amount,
            'currency' => (string) $row->currency_code, 'status' => (string) $row->status, 'error_code' => $row->error_code, 'created' => Carbon::parse($row->created_at)->toIso8601String(),
            'document_url' => is_string($issuer) && isset($documents[$row->payment_id]) ? route('accounting.fel.show', [$issuer, $documents[$row->payment_id]]) : null])->all());
    }

    private function cancel(Request $request, User $user, int $subscription, SubscriptionService $subscriptions): string
    {
        $reason = $this->confirmSensitive($request, $user);
        $immediately = $request->boolean('immediately');
        $subscriptions->cancel($user, $subscription, $immediately, $reason);

        return $immediately ? 'Suscripción cancelada de inmediato.' : 'Se cancelará al final del período actual.';
    }

    private function pause(Request $request, User $user, int $subscription, SubscriptionService $subscriptions): string
    {
        $subscriptions->pause($user, $subscription, $this->confirmSensitive($request, $user));

        return 'Suscripción pausada.';
    }

    /**
     * @return array{name: string, description: string|null, monthly_price: string, annual_price: string, entitlements: list<string>, limits: array<string, array{value: int|null, warning?: int, high?: int, enforcement?: string}>}
     */
    private function planData(Request $request): array
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'description' => 'nullable|string|max:500',
            'monthly_price' => 'required|numeric|min:0|max:1000000', 'annual_price' => 'required|numeric|min:0|max:10000000', 'entitlements' => 'array', 'entitlements.*' => 'string',
            'limits' => 'array', 'limits.*.value' => 'nullable|integer|min:0', 'limits.*.warning' => 'nullable|integer|min:1|max:100', 'limits.*.high' => 'nullable|integer|min:1|max:100',
            'limits.*.enforcement' => ['nullable', Rule::in(['soft', 'hard'])]]);
        $limits = [];
        foreach ((array) ($data['limits'] ?? []) as $metric => $limit) {
            $limits[(string) $metric] = ['value' => isset($limit['value']) && $limit['value'] !== '' ? (int) $limit['value'] : null, 'warning' => (int) ($limit['warning'] ?? 80),
                'high' => (int) ($limit['high'] ?? 95), 'enforcement' => (string) ($limit['enforcement'] ?? 'soft')];
        }

        return ['name' => (string) $data['name'], 'description' => isset($data['description']) ? (string) $data['description'] : null, 'monthly_price' => (string) $data['monthly_price'],
            'annual_price' => (string) $data['annual_price'], 'entitlements' => array_values(array_map('strval', (array) ($data['entitlements'] ?? []))), 'limits' => $limits];
    }

    private function safe(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (\Throwable) {
            return ['unavailable' => true];
        }
    }
}
