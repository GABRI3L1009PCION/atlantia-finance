<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Billing\CheckoutController;
use App\Models\Organization;
use App\Models\User;
use App\Services\Platform\Billing\SubscriptionService;
use App\Services\Platform\SubscriptionOverviewService;
use App\Services\Tenancy\TenantDatabaseContext;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SubscriptionController extends Controller
{
    public function __construct(
        private WorkspaceContext $context,
        private WorkAuthorizationService $authorization,
        private SubscriptionOverviewService $subscription,
        private SubscriptionService $subscriptions,
        private TenantDatabaseContext $tenancy,
    ) {}

    /** GET office/subscription: the firm's active plan, usage, billing history and the plans on offer. */
    public function show(Request $request): Response
    {
        $organization = $this->context->organization($request->user());
        $this->authorization->ensureDefaults($organization);
        abort_unless($this->authorization->can($organization, $request->user(), 'office.executive.view') || $this->authorization->can($organization, $request->user(), 'office.settings.manage'), 403);

        return Inertia::render('work/subscription', [
            ...$this->subscription->overview($organization),
            'canChangePlan' => $this->isOwner($organization, $request->user()) && $this->runningSubscriptionId($organization) !== null,
        ]);
    }

    /** GET office/subscription/plan-preview: what changing to a plan price would mean (price, proration, limit conflicts). Owner only. */
    public function previewPlan(Request $request): JsonResponse
    {
        [$organization, $subscriptionId] = $this->ownerSubscription($request);
        $priceId = $this->planPriceId($request);
        $preview = $this->tenancy->runAsSystem(fn (): array => $this->subscriptions->preview($subscriptionId, $priceId));

        return response()->json(['preview' => $preview, 'organization' => $organization->name])->header('Cache-Control', 'no-store, private');
    }

    /**
     * POST office/subscription/plan: an upgrade applies now and its prorated charge is paid with its invoice; a downgrade
     * waits for the end of the period and is refused while the firm's current use exceeds the new plan's limits.
     */
    public function changePlan(Request $request): RedirectResponse
    {
        [$organization, $subscriptionId] = $this->ownerSubscription($request);
        $priceId = $this->planPriceId($request);
        /** @var User $user */
        $user = $request->user();

        try {
            $result = $this->tenancy->runAsSystem(fn (): string => $this->subscriptions->changePlan($user, $subscriptionId, $priceId, 'Cambio de plan solicitado por el propietario de la firma.'));
        } catch (ValidationException $exception) {
            $message = (string) collect($exception->errors())->flatten()->first();

            return back()->withErrors(['plan_price_id' => trim(str_replace('OVER_LIMIT:', 'No se puede cambiar:', $message))]);
        }

        if ($result === 'scheduled') {
            return back()->with('workspace_notice', 'El cambio de plan quedó programado para el final del período actual.');
        }
        if (CheckoutController::payableSubscription($organization) !== null) {
            return to_route('billing.checkout.show')->with('workspace_notice', 'Plan cambiado. Paga el ajuste prorrateado para dejar la cuenta al día.');
        }

        return back()->with('workspace_notice', 'Plan cambiado.');
    }

    private function isOwner(Organization $organization, ?User $user): bool
    {
        return $user !== null && $organization->users()->whereKey($user->id)->wherePivot('role', 'owner')->wherePivot('status', 'active')->exists();
    }

    private function runningSubscriptionId(Organization $organization): ?int
    {
        $id = DB::table('subscriptions')->where('organization_id', $organization->id)->whereIn('status', ['active', 'trialing'])->orderByDesc('id')->value('id');

        return $id === null ? null : (int) $id;
    }

    /** @return array{Organization, int} */
    private function ownerSubscription(Request $request): array
    {
        $organization = $this->context->organization($request->user());
        abort_unless($this->isOwner($organization, $request->user()), 403, 'Solo el propietario de la firma puede cambiar el plan.');
        $subscriptionId = $this->runningSubscriptionId($organization) ?? abort(409, 'La firma no tiene una suscripción vigente que cambiar.');

        return [$organization, $subscriptionId];
    }

    private function planPriceId(Request $request): int
    {
        $data = $request->validate(['plan_price_id' => ['required', 'integer']]);
        $valid = $this->tenancy->runAsSystem(fn (): bool => DB::table('plan_prices')->join('plans', 'plans.id', '=', 'plan_prices.plan_id')
            ->where('plan_prices.id', $data['plan_price_id'])->where('plan_prices.is_active', true)->where('plan_prices.currency_code', 'GTQ')->where('plans.is_active', true)->exists());
        if (! $valid) {
            throw ValidationException::withMessages(['plan_price_id' => 'El plan elegido no está disponible.']);
        }

        return (int) $data['plan_price_id'];
    }
}
