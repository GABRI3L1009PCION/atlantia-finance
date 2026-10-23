<?php

declare(strict_types=1);

namespace App\Services\Platform\Billing;

use App\Models\User;
use App\Services\Platform\GlobalConfigurationService;
use App\Services\Platform\NotificationTemplateService;
use App\Services\Platform\OrganizationStatusService;
use App\Services\Platform\PlatformAuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;
use Throwable;

/**
 * Billing of Atlantia Systems to its client organizations: invoices (idempotent per period), credits, payments
 * (provider or manual) and the configurable dunning policy (past due → reminder → grace period → suspension).
 */
class SaaSBillingService
{
    public const INVOICE_LABELS = ['draft' => 'Borrador', 'open' => 'Pendiente', 'paid' => 'Pagada', 'past_due' => 'Vencida', 'void' => 'Anulada', 'refunded' => 'Reembolsada'];

    public function __construct(
        private PlatformAuditService $audit,
        private GlobalConfigurationService $settings,
        private OrganizationStatusService $organizations,
        private NotificationTemplateService $templates,
        private PaymentProviderInterface $provider,
    ) {}

    /**
     * Creates (once) the invoice of a subscription for a period. Applies the subscription discount and available credits.
     *
     * @param  list<array{description: string, quantity?: float|int, unit_amount: float|string, plan_version_id?: int|null}>  $lines
     * @param  array{discount: float, credit: float}|null  $fixed  amounts already charged to the firm (a first payment), used as they are
     */
    public function issue(?User $actor, stdClass $subscription, string $idempotencyKey, array $lines, string $kind = 'recurring', ?string $periodStart = null, ?string $periodEnd = null, ?array $fixed = null): stdClass
    {
        $existing = DB::table('saas_invoices')->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($actor, $subscription, $idempotencyKey, $lines, $kind, $periodStart, $periodEnd, $fixed): stdClass {
            $subtotal = 0.0;
            foreach ($lines as $line) {
                $subtotal += round((float) ($line['quantity'] ?? 1) * (float) $line['unit_amount'], 2);
            }
            if ($fixed !== null) {
                $discount = round((float) $fixed['discount'], 2);
                $credit = round((float) $fixed['credit'], 2);
                $this->applyCredits((int) $subscription->organization_id, $credit);
            } else {
                $discount = $kind === 'recurring' ? round($subtotal * (float) $subscription->discount_percent / 100, 2) : 0.0;
                $credit = $this->applyCredits((int) $subscription->organization_id, max(0, $subtotal - $discount));
            }
            $total = max(0, round($subtotal - $discount - $credit, 2));
            $id = DB::table('saas_invoices')->insertGetId([
                'public_id' => (string) Str::uuid(), 'organization_id' => $subscription->organization_id, 'subscription_id' => $subscription->id,
                'number' => $this->nextNumber(), 'status' => $total > 0 ? 'open' : 'paid', 'currency_code' => (string) $this->settings->get('billing.currency', 'GTQ'),
                'subtotal' => $subtotal, 'discount' => $discount, 'credit_applied' => $credit, 'total' => $total, 'period_start' => $periodStart, 'period_end' => $periodEnd,
                'due_on' => now()->addDays((int) $this->settings->get('billing.invoice_due_days', 5))->toDateString(), 'kind' => $kind, 'idempotency_key' => $idempotencyKey,
                'issued_at' => now(), 'paid_at' => $total > 0 ? null : now(), 'created_by' => $actor?->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($lines as $line) {
                DB::table('saas_invoice_lines')->insert(['saas_invoice_id' => $id, 'description' => $line['description'], 'quantity' => $line['quantity'] ?? 1, 'unit_amount' => $line['unit_amount'],
                    'amount' => round((float) ($line['quantity'] ?? 1) * (float) $line['unit_amount'], 2), 'plan_version_id' => $line['plan_version_id'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->audit->record($actor, 'invoice.issued', 'saas_invoices', $id, null, ['total' => $total, 'kind' => $kind], null, (int) $subscription->organization_id);

            return DB::table('saas_invoices')->where('id', $id)->first() ?? abort(500);
        });
    }

    /** Settles an invoice and brings the subscription back to good standing. */
    public function markPaid(?User $actor, int $invoiceId, int $paymentId): void
    {
        DB::transaction(function () use ($actor, $invoiceId, $paymentId): void {
            $invoice = DB::table('saas_invoices')->where('id', $invoiceId)->lockForUpdate()->first() ?? abort(404);
            if ($invoice->status === 'paid') {
                return;
            }
            if (! in_array($invoice->status, ['open', 'past_due'], true)) {
                throw ValidationException::withMessages(['invoice' => 'La factura está '.mb_strtolower(self::INVOICE_LABELS[$invoice->status]).'.']);
            }
            DB::table('saas_invoices')->where('id', $invoiceId)->update(['status' => 'paid', 'paid_at' => now(), 'updated_at' => now()]);
            DB::table('payments')->where('id', $paymentId)->update(['saas_invoice_id' => $invoiceId, 'updated_at' => now()]);
            $this->restoreStanding((int) $invoice->organization_id, $invoice->subscription_id === null ? null : (int) $invoice->subscription_id, $actor);
            $this->audit->record($actor, 'payment.received', 'saas_invoices', $invoiceId, ['status' => $invoice->status], ['status' => 'paid', 'payment_id' => $paymentId], null, (int) $invoice->organization_id);
        });
    }

    /**
     * A provider payment confirmed by its (verified, idempotent) webhook: settles the invoice it was created for or,
     * for a subscription checkout, records the paid invoice of the period.
     */
    public function settleProviderPayment(int $paymentId): void
    {
        $payment = DB::table('payments')->where('id', $paymentId)->first() ?? abort(404);
        if ($payment->saas_invoice_id !== null) {
            $this->markPaid(null, (int) $payment->saas_invoice_id, $paymentId);

            return;
        }
        $subscription = $payment->subscription_id === null ? null : DB::table('subscriptions')->where('id', $payment->subscription_id)->first();
        if ($subscription === null) {
            return;
        }
        if ($subscription->plan_version_id === null) {
            $planId = DB::table('subscription_items')->join('plan_prices', 'plan_prices.id', '=', 'subscription_items.plan_price_id')->where('subscription_id', $subscription->id)->where('item_type', 'base_plan')->value('plan_prices.plan_id');
            $versionId = DB::table('plan_versions')->where('plan_id', $planId)->where('status', 'published')->orderByDesc('version')->value('id');
            DB::table('subscriptions')->where('id', $subscription->id)->update(['plan_version_id' => $versionId, 'updated_at' => now()]);
            $subscription = DB::table('subscriptions')->where('id', $subscription->id)->first() ?? $subscription;
        }
        $snapshot = (array) json_decode((string) $payment->billing_snapshot, true);
        // The first payment was charged with its discount and credits already applied: the invoice repeats exactly that.
        $breakdown = isset($snapshot['breakdown']) && is_array($snapshot['breakdown']) ? $snapshot['breakdown'] : null;
        $invoice = $this->issue(null, $subscription, 'payment:'.$paymentId, [['description' => 'Atlantia Finance · '.($snapshot['plan_name'] ?? 'Suscripción').' ('.(($snapshot['billing_period'] ?? 'monthly') === 'annual' ? 'anual' : 'mensual').')',
            'unit_amount' => $breakdown === null ? (float) $payment->amount : (float) $breakdown['subtotal'], 'plan_version_id' => $subscription->plan_version_id === null ? null : (int) $subscription->plan_version_id]], 'recurring',
            $subscription->current_period_starts_at === null ? null : Carbon::parse($subscription->current_period_starts_at)->toDateString(),
            $subscription->current_period_ends_at === null ? null : Carbon::parse($subscription->current_period_ends_at)->toDateString(),
            $breakdown === null ? null : ['discount' => (float) $breakdown['discount'], 'credit' => (float) $breakdown['credit']]);
        if ($invoice->status !== 'paid') {
            $this->markPaid(null, (int) $invoice->id, $paymentId);
        } else {
            DB::table('payments')->where('id', $paymentId)->update(['saas_invoice_id' => $invoice->id, 'updated_at' => now()]);
        }
    }

    /** A provider reported a failed charge: the attempt is recorded and the dunning policy takes over. */
    public function failProviderPayment(int $paymentId, string $code, string $message): void
    {
        $payment = DB::table('payments')->where('id', $paymentId)->first() ?? abort(404);
        if ($payment->status === 'succeeded') {
            return;
        }
        DB::table('payments')->where('id', $paymentId)->update(['status' => 'failed', 'failed_at' => now(), 'attempt_count' => (int) $payment->attempt_count + 1, 'updated_at' => now()]);
        DB::table('payment_attempts')->insert(['organization_id' => $payment->organization_id, 'payment_id' => $paymentId, 'status' => 'failed', 'failure_code' => mb_substr($code, 0, 60),
            'failure_message' => mb_substr($message, 0, 255), 'attempted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record(null, 'payment.failed', 'payments', $paymentId, null, ['code' => $code], $message, (int) $payment->organization_id);
    }

    /** Bank transfer or other manual payment registered by billing staff. */
    public function recordManualPayment(User $actor, int $invoiceId, string $reference, string $paidOn): int
    {
        $invoice = DB::table('saas_invoices')->where('id', $invoiceId)->first() ?? abort(404);
        if (! in_array($invoice->status, ['open', 'past_due'], true)) {
            throw ValidationException::withMessages(['invoice' => 'Solo se registra pago a una factura pendiente o vencida.']);
        }
        if (DB::table('payments')->where('provider', 'manual')->where('reference', $reference)->where('saas_invoice_id', $invoiceId)->exists()) {
            throw ValidationException::withMessages(['reference' => 'Ese pago ya fue registrado.']);
        }

        return DB::transaction(function () use ($actor, $invoice, $reference, $paidOn): int {
            $paymentId = DB::table('payments')->insertGetId(['public_id' => (string) Str::uuid(), 'organization_id' => $invoice->organization_id, 'subscription_id' => $invoice->subscription_id,
                'provider' => 'manual', 'provider_payment_id' => 'manual:'.Str::uuid(), 'currency_code' => $invoice->currency_code, 'amount' => $invoice->total, 'status' => 'succeeded',
                'paid_at' => Carbon::parse($paidOn)->setTimeFrom(now()), 'reference' => $reference, 'attempt_count' => 1, 'saas_invoice_id' => $invoice->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->markPaid($actor, (int) $invoice->id, $paymentId);

            return $paymentId;
        });
    }

    /**
     * Payment link of the provider for an open invoice (retry of a failed collection).
     *
     * @return array{url: string, payment_id: int}
     */
    public function paymentLink(User $actor, int $invoiceId): array
    {
        $invoice = DB::table('saas_invoices')->where('id', $invoiceId)->first() ?? abort(404);
        if (! in_array($invoice->status, ['open', 'past_due'], true)) {
            throw ValidationException::withMessages(['invoice' => 'La factura no está pendiente de pago.']);
        }
        if (! $this->provider->isConfigured()) {
            throw ValidationException::withMessages(['provider' => 'El proveedor de pagos ('.$this->provider->name().') no está configurado.']);
        }
        $profile = DB::table('organization_billing_profiles')->where('organization_id', $invoice->organization_id)->first();
        $owner = DB::table('organization_user')->join('users', 'users.id', '=', 'organization_user.user_id')->where('organization_user.organization_id', $invoice->organization_id)
            ->where('role', 'owner')->where('organization_user.status', 'active')->first(['users.email', 'users.name']);
        $paymentId = DB::table('payments')->insertGetId(['public_id' => (string) Str::uuid(), 'organization_id' => $invoice->organization_id, 'subscription_id' => $invoice->subscription_id,
            'provider' => $this->provider->key(), 'provider_payment_id' => 'pending:'.Str::uuid(), 'currency_code' => $invoice->currency_code, 'amount' => $invoice->total, 'status' => 'pending',
            'saas_invoice_id' => $invoice->id, 'attempt_count' => (int) DB::table('payments')->where('saas_invoice_id', $invoice->id)->count() + 1,
            'billing_snapshot' => json_encode(['name' => $profile->name ?? $owner->name, 'nit' => $profile->nit ?? 'CF', 'email' => $profile->email ?? $owner?->email, 'invoice' => $invoice->number]),
            'created_at' => now(), 'updated_at' => now()]);
        try {
            $link = $this->provider->createPaymentLink($invoice, ['name' => (string) ($profile->name ?? $owner->name ?? ''), 'email' => $profile->email ?? $owner?->email]);
        } catch (Throwable $exception) {
            DB::table('payment_attempts')->insert(['organization_id' => $invoice->organization_id, 'payment_id' => $paymentId, 'status' => 'failed', 'failure_code' => 'link_failed',
                'failure_message' => 'El proveedor no generó el enlace de pago.', 'attempted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('payments')->where('id', $paymentId)->update(['status' => 'failed', 'failed_at' => now(), 'updated_at' => now()]);
            report($exception);
            throw ValidationException::withMessages(['provider' => 'El proveedor de pagos no respondió. No se realizó ningún cobro; intenta de nuevo.']);
        }
        DB::table('payment_attempts')->insert(['organization_id' => $invoice->organization_id, 'payment_id' => $paymentId, 'provider_attempt_id' => $link['reference'], 'status' => 'created',
            'attempted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record($actor, 'payment.link_created', 'saas_invoices', (int) $invoice->id, null, ['payment_id' => $paymentId, 'provider' => $this->provider->key()], null, (int) $invoice->organization_id);

        return ['url' => $link['url'], 'payment_id' => $paymentId];
    }

    public function void(User $actor, int $invoiceId, string $reason): void
    {
        $invoice = DB::table('saas_invoices')->where('id', $invoiceId)->first() ?? abort(404);
        if (! in_array($invoice->status, ['draft', 'open', 'past_due'], true)) {
            throw ValidationException::withMessages(['invoice' => 'Solo se anula una factura sin pagar.']);
        }
        DB::table('saas_invoices')->where('id', $invoiceId)->update(['status' => 'void', 'voided_at' => now(), 'void_reason' => $reason, 'updated_at' => now()]);
        if ((float) $invoice->credit_applied > 0) {
            $this->grantCredit($actor, (int) $invoice->organization_id, (float) $invoice->credit_applied, 'Devolución de crédito de la factura anulada '.$invoice->number.'.', null, false);
        }
        if (! DB::table('saas_invoices')->where('organization_id', $invoice->organization_id)->where('status', 'past_due')->exists()) {
            $this->restoreStanding((int) $invoice->organization_id, $invoice->subscription_id === null ? null : (int) $invoice->subscription_id, $actor);
        }
        $this->audit->record($actor, 'invoice.voided', 'saas_invoices', $invoiceId, ['status' => $invoice->status, 'total' => (float) $invoice->total], ['status' => 'void'], $reason, (int) $invoice->organization_id, [], true);
    }

    /** Records that a paid invoice was refunded through the provider (the refund itself happens at the provider). */
    public function refund(User $actor, int $invoiceId, string $reason): void
    {
        $invoice = DB::table('saas_invoices')->where('id', $invoiceId)->first() ?? abort(404);
        if ($invoice->status !== 'paid') {
            throw ValidationException::withMessages(['invoice' => 'Solo se reembolsa una factura pagada.']);
        }
        DB::table('saas_invoices')->where('id', $invoiceId)->update(['status' => 'refunded', 'updated_at' => now()]);
        DB::table('payments')->where('saas_invoice_id', $invoiceId)->where('status', 'succeeded')->update(['status' => 'refunded', 'updated_at' => now()]);
        $this->audit->record($actor, 'invoice.refunded', 'saas_invoices', $invoiceId, ['status' => 'paid'], ['status' => 'refunded', 'total' => (float) $invoice->total], $reason, (int) $invoice->organization_id, [], true);
    }

    public function grantCredit(User $actor, int $organizationId, float $amount, string $reason, ?string $expiresAt, bool $audit = true): int
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'El crédito debe ser mayor que cero.']);
        }
        $id = DB::table('saas_credits')->insertGetId(['organization_id' => $organizationId, 'amount' => $amount, 'remaining' => $amount, 'currency_code' => (string) $this->settings->get('billing.currency', 'GTQ'),
            'reason' => $reason, 'granted_by' => $actor->id, 'expires_at' => $expiresAt === null ? null : Carbon::parse($expiresAt)->endOfDay(), 'created_at' => now(), 'updated_at' => now()]);
        if ($audit) {
            $this->audit->record($actor, 'credit.granted', 'saas_credits', $id, null, ['amount' => $amount, 'expires_at' => $expiresAt], $reason, $organizationId);
        }

        return $id;
    }

    /**
     * Daily dunning: overdue invoices → past due; reminder; grace period; suspension when the policy says so.
     *
     * @return array{past_due: int, reminded: int, grace: int, suspended: int}
     */
    public function runDunning(): array
    {
        $result = ['past_due' => 0, 'reminded' => 0, 'grace' => 0, 'suspended' => 0];
        $today = now()->toDateString();
        foreach (DB::table('saas_invoices')->where('status', 'open')->whereNotNull('due_on')->where('due_on', '<', $today)->get() as $invoice) {
            DB::table('saas_invoices')->where('id', $invoice->id)->update(['status' => 'past_due', 'updated_at' => now()]);
            // Only a running subscription falls behind: an invoice left over from an ended one does not reopen the firm.
            if ($invoice->subscription_id !== null && DB::table('subscriptions')->where('id', $invoice->subscription_id)->whereIn('status', ['active', 'trialing'])
                ->update(['status' => 'past_due', 'past_due_since' => now(), 'updated_at' => now()]) > 0) {
                $this->organizations->syncWithSubscription((int) $invoice->organization_id, 'past_due');
            }
            $this->audit->record(null, 'subscription.past_due', 'saas_invoices', (int) $invoice->id, ['status' => 'open'], ['status' => 'past_due'], 'Factura vencida sin pago.', (int) $invoice->organization_id);
            $result['past_due']++;
        }

        $reminder = (int) $this->settings->get('billing.reminder_after_days', 3);
        $grace = (int) $this->settings->get('billing.grace_after_days', 7);
        $suspend = (int) $this->settings->get('billing.suspend_after_days', 15);
        foreach (DB::table('subscriptions')->whereIn('status', ['past_due', 'grace_period'])->whereNotNull('past_due_since')->get() as $subscription) {
            $days = (int) Carbon::parse($subscription->past_due_since)->startOfDay()->diffInDays(now()->startOfDay());
            $organizationId = (int) $subscription->organization_id;
            if ($days >= $reminder && $this->templates->notifyOnce($organizationId, 'payment_failed', 'dunning:'.$subscription->id.':'.$subscription->past_due_since, ['days' => $days])) {
                $result['reminded']++;
            }
            if ($days >= $grace && $subscription->status === 'past_due') {
                DB::table('subscriptions')->where('id', $subscription->id)->update(['status' => 'grace_period', 'grace_ends_at' => Carbon::parse($subscription->past_due_since)->addDays($suspend), 'updated_at' => now()]);
                $this->organizations->syncWithSubscription($organizationId, 'grace_period');
                $this->audit->record(null, 'subscription.grace_period', 'subscriptions', (int) $subscription->id, ['status' => 'past_due'], ['status' => 'grace_period'], $days.' días de atraso.', $organizationId);
                $result['grace']++;
            }
            if ($days >= $suspend && (bool) $this->settings->get('billing.auto_suspend', true) && ! in_array(DB::table('organizations')->where('id', $organizationId)->value('status'), ['suspended', 'archived'], true)) {
                $this->organizations->suspend(null, $organizationId, 'Falta de pago: '.$days.' días de atraso.', true);
                $result['suspended']++;
            }
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function invoiceRow(stdClass $row): array
    {
        return ['id' => (int) $row->id, 'public_id' => (string) $row->public_id, 'number' => (string) $row->number, 'organization_id' => (int) $row->organization_id,
            'organization' => $row->organization_name ?? null, 'status' => (string) $row->status, 'status_label' => self::INVOICE_LABELS[$row->status] ?? $row->status,
            'currency' => (string) $row->currency_code, 'subtotal' => (float) $row->subtotal, 'discount' => (float) $row->discount, 'credit_applied' => (float) $row->credit_applied, 'total' => (float) $row->total,
            'kind' => (string) $row->kind, 'period_start' => $row->period_start, 'period_end' => $row->period_end, 'due_on' => $row->due_on,
            'days_overdue' => in_array($row->status, ['open', 'past_due'], true) && $row->due_on !== null && $row->due_on < now()->toDateString() ? (int) Carbon::parse($row->due_on)->diffInDays(now()->startOfDay()) : 0,
            'issued_at' => $row->issued_at === null ? null : Carbon::parse($row->issued_at)->toIso8601String(), 'paid_at' => $row->paid_at === null ? null : Carbon::parse($row->paid_at)->toIso8601String(),
            'void_reason' => $row->void_reason];
    }

    private function restoreStanding(int $organizationId, ?int $subscriptionId, ?User $actor): void
    {
        if ($subscriptionId === null || DB::table('saas_invoices')->where('organization_id', $organizationId)->where('status', 'past_due')->exists()) {
            return;
        }
        $subscription = DB::table('subscriptions')->where('id', $subscriptionId)->first();
        if ($subscription !== null && in_array($subscription->status, ['past_due', 'grace_period'], true)) {
            DB::table('subscriptions')->where('id', $subscriptionId)->update(['status' => 'active', 'past_due_since' => null, 'grace_ends_at' => null, 'updated_at' => now()]);
            $this->organizations->syncWithSubscription($organizationId, 'active');
        }
        $organization = DB::table('organizations')->where('id', $organizationId)->first();
        if ($organization !== null && $organization->status === 'suspended' && str_starts_with((string) $organization->suspension_reason, '[billing]')) {
            DB::table('organizations')->where('id', $organizationId)->update(['status' => 'active', 'suspended_at' => null, 'suspension_reason' => null, 'updated_at' => now()]);
            $this->audit->record($actor, 'organization.reactivated', 'organizations', $organizationId, ['status' => 'suspended'], ['status' => 'active'], 'Pago recibido: se levanta la suspensión por falta de pago.', $organizationId);
        }
    }

    /**
     * What the first payment of a subscription costs: the list price, the subscription discount and the credits the firm has.
     * Read only; the credits are consumed when the paid invoice is recorded. Platform rows: call it with system access.
     *
     * @return array{subtotal: float, discount: float, credit: float, total: float}
     */
    public function firstChargeBreakdown(int $organizationId, float $discountPercent, float $listPrice): array
    {
        $subtotal = round($listPrice, 2);
        $discount = round($subtotal * max(0, min(100, $discountPercent)) / 100, 2);
        $available = (float) DB::table('saas_credits')->where('organization_id', $organizationId)->where('remaining', '>', 0)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->sum('remaining');
        $credit = round(min($available, max(0, $subtotal - $discount)), 2);

        return ['subtotal' => $subtotal, 'discount' => $discount, 'credit' => $credit, 'total' => max(0, round($subtotal - $discount - $credit, 2))];
    }

    private function applyCredits(int $organizationId, float $amount): float
    {
        $applied = 0.0;
        foreach (DB::table('saas_credits')->where('organization_id', $organizationId)->where('remaining', '>', 0)->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderBy('id')->lockForUpdate()->get() as $credit) {
            if ($applied >= $amount) {
                break;
            }
            $use = min((float) $credit->remaining, $amount - $applied);
            DB::table('saas_credits')->where('id', $credit->id)->update(['remaining' => round((float) $credit->remaining - $use, 2), 'updated_at' => now()]);
            $applied += $use;
        }

        return round($applied, 2);
    }

    private function nextNumber(): string
    {
        $year = now()->year;
        $count = DB::table('saas_invoices')->where('number', 'like', 'ATL-'.$year.'-%')->count();

        return sprintf('ATL-%d-%05d', $year, $count + 1);
    }
}
