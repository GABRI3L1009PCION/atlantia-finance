<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\WorkspaceInvitation;
use App\Services\Platform\Billing\SubscriptionService;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Support\PlatformIssuer;
use App\Support\PlatformStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;
use Throwable;

/**
 * Client organizations as seen by the platform: metadata only (plan, status, usage, structure counts). Tenant content
 * (ledgers, salaries, invoices, bank balances…) is never read here; it needs a formal support access.
 *
 * Onboarding: organization → plan → subscription → entitlements and limits → owner invitation → initial office →
 * TRIAL/ACTIVE, all in one transaction (the owner is a tenant ORGANIZATION_OWNER, never a SaaS owner).
 */
class OrganizationManagementService
{
    public function __construct(
        private PlatformAuditService $audit,
        private SubscriptionService $subscriptions,
        private UsageMeterService $usage,
        private EntitlementResolverService $entitlements,
        private GlobalConfigurationService $settings,
    ) {}

    /**
     * @param  array{search?: string|null, status?: string|null, plan_id?: int|null}  $filters
     * @return list<array<string, mixed>>
     */
    public function list(array $filters, int $limit = 300): array
    {
        $rows = $this->base()
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(fn ($inner) => $inner->where('organizations.name', 'ilike', '%'.$search.'%')->orWhere('organizations.code', 'ilike', '%'.$search.'%')->orWhere('organizations.tax_identifier', 'ilike', '%'.$search.'%')))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('organizations.status', $status))
            ->when($filters['plan_id'] ?? null, fn ($q, $plan) => $q->where('current.plan_id', $plan))
            ->orderByDesc('organizations.id')->limit($limit)->get();

        return array_values($rows->map(fn (stdClass $row): array => $this->row($row))->all());
    }

    /** @return array<string, mixed> */
    public function row(stdClass $row): array
    {
        $usage = $this->usage->measure((int) $row->id);
        $limits = $this->entitlements->resolve((int) $row->id)['limits'];

        return ['id' => (int) $row->id, 'public_id' => (string) $row->public_id, 'code' => $row->code, 'name' => (string) $row->name, 'tax_id' => $row->tax_identifier,
            'plan' => $row->plan_name, 'plan_version' => $row->plan_version === null ? null : (int) $row->plan_version,
            'subscription_status' => $row->subscription_status, 'subscription_label' => PlatformStatus::SUBSCRIPTION_LABELS[$row->subscription_status ?? ''] ?? 'Sin suscripción',
            'organization_status' => (string) $row->status, 'organization_label' => PlatformStatus::ORGANIZATION_LABELS[$row->status] ?? $row->status,
            'offices_count' => $usage['offices'], 'users_count' => $usage['users'], 'companies_count' => $usage['active_cases'], 'storage_bytes' => $usage['storage_bytes'],
            'storage_limit' => $limits['storage_bytes']['value'] ?? null, 'luzma_usage' => $usage['ai_queries'], 'luzma_limit' => $limits['ai_queries']['value'] ?? null,
            'renewal_date' => $row->current_period_ends_at === null ? null : Carbon::parse($row->current_period_ends_at)->toDateString(),
            'billing_status' => DB::table('saas_invoices')->where('organization_id', $row->id)->where('status', 'past_due')->exists() ? 'past_due' : 'ok',
            'last_activity' => $this->lastActivity((int) $row->id), 'beta_program' => (bool) $row->beta_program, 'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
            'destination' => '/control-center/organizations/'.$row->id];
    }

    public function find(int $organizationId): stdClass
    {
        return $this->base()->where('organizations.id', $organizationId)->first() ?? abort(404);
    }

    /**
     * Platform metadata of one organization for the detail screen (never journal, payroll, receivables or fiscal data).
     *
     * @return array<string, mixed>
     */
    public function detail(int $organizationId): array
    {
        $row = $this->find($organizationId);
        $entitlements = $this->entitlements->resolve($organizationId);
        $subscription = DB::table('subscriptions')->leftJoin('subscription_items', fn ($join) => $join->on('subscription_items.subscription_id', '=', 'subscriptions.id')->where('subscription_items.item_type', 'base_plan'))
            ->leftJoin('plan_prices', 'plan_prices.id', '=', 'subscription_items.plan_price_id')->leftJoin('plans', 'plans.id', '=', 'plan_prices.plan_id')
            ->leftJoin('plan_versions', 'plan_versions.id', '=', 'subscriptions.plan_version_id')->where('subscriptions.organization_id', $organizationId)->orderByDesc('subscriptions.id')
            ->first(['subscriptions.*', 'plans.name as plan_name', 'plan_prices.billing_period', 'plan_prices.amount', 'plan_prices.currency_code', 'plan_versions.version as plan_version']);

        return [
            'organization' => [...$this->row($row), 'legal_name' => $row->legal_name, 'country' => $row->country_code, 'currency' => $row->currency_code, 'timezone' => $row->timezone, 'phone' => $row->phone,
                'suspended_at' => $row->suspended_at, 'suspension_reason' => $row->suspension_reason, 'archived_at' => $row->archived_at],
            'subscription' => $subscription === null ? null : app(SubscriptionService::class)->row($subscription),
            'features' => array_map(fn (string $code, array $feature): array => ['key' => $code, ...$feature], array_keys($entitlements['features']), array_values($entitlements['features'])),
            'limits' => $entitlements['limits'],
            'usage' => $this->usage->usage($organizationId),
            'offices' => DB::table('offices')->where('offices.organization_id', $organizationId)->orderBy('name')->get(['id', 'code', 'name', 'city', 'status', 'is_primary', 'created_at'])
                ->map(fn (stdClass $office): array => ['id' => (int) $office->id, 'code' => (string) $office->code, 'name' => (string) $office->name, 'city' => $office->city, 'status' => (string) $office->status,
                    'primary' => (bool) $office->is_primary, 'users' => DB::table('office_user')->where('office_id', $office->id)->where('status', 'active')->count(),
                    'companies' => DB::table('companies')->where('office_id', $office->id)->count()])->all(),
            'users' => DB::table('organization_user')->join('users', 'users.id', '=', 'organization_user.user_id')->where('organization_user.organization_id', $organizationId)->orderBy('users.name')
                ->get(['users.id', 'users.name', 'users.email', 'users.two_factor_confirmed_at', 'organization_user.role', 'organization_user.status'])
                ->map(fn (stdClass $user): array => ['id' => (int) $user->id, 'name' => (string) $user->name, 'email' => (string) $user->email, 'role' => (string) $user->role, 'status' => (string) $user->status,
                    'two_factor' => $user->two_factor_confirmed_at !== null, 'last_seen' => ($last = DB::table('sessions')->where('user_id', $user->id)->max('last_activity')) === null ? null : Carbon::createFromTimestamp((int) $last)->toIso8601String()])->all(),
            'integrations' => ['api_tokens' => DB::table('api_tokens')->where('api_tokens.organization_id', $organizationId)->whereNull('revoked_at')->count(),
                'webhooks' => DB::table('webhook_endpoints')->where('webhook_endpoints.organization_id', $organizationId)->where('status', 'active')->count(),
                'webhook_failures_7d' => DB::table('webhook_deliveries')->where('webhook_deliveries.organization_id', $organizationId)->where('status', 'failed')->where('created_at', '>=', now()->subDays(7))->count(),
                'api_requests_month' => DB::table('api_request_logs')->where('api_request_logs.organization_id', $organizationId)->where('created_at', '>=', now()->startOfMonth())->count()],
            'billing' => DB::table('saas_invoices')->where('organization_id', $organizationId)->orderByDesc('id')->limit(20)->get()->map(fn (stdClass $invoice): array => app(Billing\SaaSBillingService::class)->invoiceRow($invoice))->all(),
            'credits' => DB::table('saas_credits')->where('organization_id', $organizationId)->orderByDesc('id')->get(['id', 'amount', 'remaining', 'reason', 'expires_at', 'created_at'])->all(),
            'security' => ['users_with_2fa' => DB::table('organization_user')->join('users', 'users.id', '=', 'organization_user.user_id')->where('organization_user.organization_id', $organizationId)->where('organization_user.status', 'active')->whereNotNull('users.two_factor_confirmed_at')->count(),
                'offices_requiring_2fa' => DB::table('offices')->where('offices.organization_id', $organizationId)->where('require_two_factor', true)->count(),
                'support_accesses' => DB::table('support_access_requests')->where('organization_id', $organizationId)->orderByDesc('id')->limit(10)->get(['id', 'status', 'reason', 'expires_at', 'created_at'])->all()],
            'support' => DB::table('support_tickets')->where('organization_id', $organizationId)->orderByDesc('id')->limit(10)->get(['id', 'code', 'subject', 'priority', 'status', 'created_at'])->all(),
            'activity' => DB::table('platform_audit_events')->where('organization_id', $organizationId)->orderByDesc('id')->limit(30)->get(['action', 'actor_name', 'reason', 'created_at'])->all(),
            'overrides' => app(FeatureCatalogService::class)->overrides($organizationId),
        ];
    }

    /**
     * @param  array{name: string, legal_name?: string|null, tax_identifier?: string|null, country_code?: string|null, timezone?: string|null, plan_price_id: int, trial: bool, trial_days?: int|null, discount_percent?: float|null,
     *     owner_name: string, owner_email: string, office_name: string, office_city?: string|null, beta_program?: bool, billing_email?: string|null}  $data
     * @return array{organization_id: int, invitation_id: int}
     */
    public function onboard(User $actor, array $data): array
    {
        $email = Str::lower(trim($data['owner_email']));
        if (DB::table('platform_staff')->join('users', 'users.id', '=', 'platform_staff.user_id')->whereRaw('lower(users.email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages(['owner_email' => 'El dueño de una organización no puede ser personal SaaS: usa el correo del cliente.']);
        }
        if (($data['tax_identifier'] ?? null) && DB::table('organizations')->where('tax_identifier', $data['tax_identifier'])->whereNotIn('status', ['archived'])->exists()) {
            throw ValidationException::withMessages(['tax_identifier' => 'Ya existe una organización con ese NIT.']);
        }
        $token = Str::random(64);
        $result = DB::transaction(function () use ($actor, $data, $email, $token): array {
            $organization = Organization::query()->create(['name' => $data['name'], 'status' => 'pending_payment']);
            DB::table('organizations')->where('id', $organization->id)->update([
                'code' => 'ORG-'.str_pad((string) $organization->id, 5, '0', STR_PAD_LEFT), 'legal_name' => $data['legal_name'] ?? $data['name'], 'tax_identifier' => $data['tax_identifier'] ?? null,
                'country_code' => $data['country_code'] ?? 'GT', 'timezone' => $data['timezone'] ?? 'America/Guatemala',
                'beta_program' => (bool) ($data['beta_program'] ?? $this->settings->get('features.new_organizations_beta', false)), 'updated_at' => now(),
            ]);
            DB::table('organization_billing_profiles')->insert(['organization_id' => $organization->id, 'name' => $data['legal_name'] ?? $data['name'], 'nit' => $data['tax_identifier'] ?? 'CF',
                'address' => 'Ciudad', 'email' => $data['billing_email'] ?? $email, 'created_at' => now(), 'updated_at' => now()]);
            $office = $organization->offices()->create(['public_id' => (string) Str::uuid(), 'code' => 'PRINCIPAL', 'name' => $data['office_name'], 'city' => $data['office_city'] ?? null,
                'country_code' => $data['country_code'] ?? 'GT', 'timezone' => $data['timezone'] ?? 'America/Guatemala', 'status' => 'active', 'is_primary' => true]);
            // A new firm starts strict: unreconciled banks and unfiled obligations stop the close until the office decides otherwise.
            DB::table('offices')->where('id', $office->id)->update(['treasury_close_policy' => 'block', 'fiscal_close_policy' => 'block']);
            $subscriptionId = $this->subscriptions->create($actor, (int) $organization->id, (int) $data['plan_price_id'], (bool) $data['trial'], (float) ($data['discount_percent'] ?? 0), trialDays: $data['trial_days'] ?? null);
            app(WorkAuthorizationService::class)->ensureDefaults($organization);
            $invitation = Invitation::query()->create(['public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'invited_by' => $actor->id, 'email' => $email, 'name' => trim($data['owner_name']),
                'role' => 'owner', 'workspace_role_id' => null, 'token_hash' => hash('sha256', $token), 'status' => 'pending', 'metadata' => json_encode(['companies' => [], 'source' => 'control_center']),
                'sent_count' => 1, 'last_sent_at' => now(), 'expires_at' => now()->addDays(14)]);
            $invitation->offices()->attach($office->id, ['organization_id' => $organization->id]);
            $this->audit->record($actor, 'organization.created', 'organizations', (int) $organization->id, null,
                ['name' => $data['name'], 'plan_price_id' => (int) $data['plan_price_id'], 'trial' => (bool) $data['trial'], 'owner_email' => $email, 'subscription_id' => $subscriptionId], null, (int) $organization->id);

            return ['organization_id' => (int) $organization->id, 'invitation_id' => (int) $invitation->id, 'invitation' => $invitation, 'organization' => $organization];
        });

        try {
            $url = URL::temporarySignedRoute('invitations.show', $result['invitation']->expires_at, ['invitation' => $result['invitation']->public_id, 'token' => $token]);
            Notification::route('mail', $email)->notify(new WorkspaceInvitation($result['organization']->name, $url, $result['invitation']->expires_at, true));
        } catch (Throwable $exception) {
            report($exception);
        }

        return ['organization_id' => $result['organization_id'], 'invitation_id' => $result['invitation_id']];
    }

    /** @param array{name?: string, legal_name?: string|null, tax_identifier?: string|null, timezone?: string|null, beta_program?: bool} $data */
    public function update(User $actor, int $organizationId, array $data): void
    {
        $before = (array) (DB::table('organizations')->where('id', $organizationId)->first(['name', 'legal_name', 'tax_identifier', 'timezone', 'beta_program']) ?? abort(404));
        DB::table('organizations')->where('id', $organizationId)->update([...$data, 'updated_at' => now()]);
        $this->audit->record($actor, 'organization.updated', 'organizations', $organizationId, $before, $data, null, $organizationId);
    }

    /** @return Builder */
    private function base()
    {
        $current = DB::table('subscriptions as s')->leftJoin('subscription_items as si', fn ($join) => $join->on('si.subscription_id', '=', 's.id')->where('si.item_type', 'base_plan'))
            ->leftJoin('plan_prices as pp', 'pp.id', '=', 'si.plan_price_id')->leftJoin('plans as p', 'p.id', '=', 'pp.plan_id')->leftJoin('plan_versions as pv', 'pv.id', '=', 's.plan_version_id')
            ->selectRaw('DISTINCT ON (s.organization_id) s.organization_id, s.status as subscription_status, s.current_period_ends_at, p.id as plan_id, p.name as plan_name, pv.version as plan_version')
            ->orderBy('s.organization_id')->orderByDesc('s.id');

        return DB::table('organizations')->leftJoinSub($current, 'current', 'current.organization_id', '=', 'organizations.id')->whereNull('organizations.deleted_at')->where('organizations.id', '<>', PlatformIssuer::organizationId())
            ->select('organizations.*', 'current.subscription_status', 'current.current_period_ends_at', 'current.plan_id', 'current.plan_name', 'current.plan_version');
    }

    private function lastActivity(int $organizationId): ?string
    {
        $last = DB::table('sessions')->join('organization_user', 'organization_user.user_id', '=', 'sessions.user_id')->where('organization_user.organization_id', $organizationId)->max('sessions.last_activity');
        $audit = DB::table('audit_logs')->where('audit_logs.organization_id', $organizationId)->max('occurred_at');
        $times = array_filter([$last === null ? null : Carbon::createFromTimestamp((int) $last), $audit === null ? null : Carbon::parse($audit)]);

        return $times === [] ? null : max($times)->toIso8601String();
    }
}
