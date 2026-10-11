<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\Organization;
use App\Models\User;
use App\Services\WorkManagement\WorkAuthorizationService;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Who may audit what. Internal auditors reach the companies of their auditor assignments and the engagements they are
 * members of; people who create engagements reach their assigned companies; external auditors reach only what an
 * active, unexpired access grant allows (one company, one period, a list of modules). Everything is resolved on the
 * server: an id sent by the browser is never trusted.
 */
class AuditAccessService
{
    public const MODULES = [
        'accounting_review' => 'Revisión contable', 'reconciliations' => 'Conciliaciones', 'period_close' => 'Cierres contables', 'documents' => 'Documentos soporte',
        'traceability' => 'Trazabilidad', 'system_events' => 'Registro de auditoría', 'sensitive_changes' => 'Cambios sensibles',
    ];

    /** @var array<string, bool> */
    private array $owners = [];

    public function __construct(private WorkAuthorizationService $authorization) {}

    public function isOwner(Organization $organization, User $user): bool
    {
        return $this->owners[$organization->id.':'.$user->id] ??= DB::table('organization_user')->where('organization_user.organization_id', $organization->id)->where('user_id', $user->id)
            ->where('role', 'owner')->where('status', 'active')->exists();
    }

    /** Marks expired grants (an expired grant never gives access, even before this runs). */
    public function expireGrants(Organization $organization): int
    {
        $expired = DB::table('audit_access_grants')->where('audit_access_grants.organization_id', $organization->id)->where('status', 'active')->where('expires_at', '<=', now())->get(['id', 'auditor_user_id']);
        foreach ($expired as $grant) {
            DB::table('audit_access_grants')->where('id', $grant->id)->update(['status' => 'expired', 'updated_at' => now()]);
            DB::table('audit_logs')->insert(['organization_id' => $organization->id, 'user_id' => null, 'action' => 'audit.access.expired', 'auditable_type' => 'audit_access_grants', 'auditable_id' => $grant->id,
                'new_values' => json_encode(['auditor_user_id' => (int) $grant->auditor_user_id], JSON_THROW_ON_ERROR), 'occurred_at' => now(), 'created_at' => now()]);
        }

        return $expired->count();
    }

    /**
     * Companies the user may audit.
     *
     * @return list<int>
     */
    public function companyIds(Organization $organization, User $user): array
    {
        if ($this->isOwner($organization, $user)) {
            return array_values(array_map('intval', DB::table('companies')->where('companies.organization_id', $organization->id)->pluck('id')->all()));
        }
        $creator = $this->authorization->can($organization, $user, 'audit.engagements.create');
        $assigned = DB::table('company_assignments')->where('company_assignments.organization_id', $organization->id)->where('user_id', $user->id)->where('status', 'active')->whereNull('deleted_at')
            ->when(! $creator, fn ($q) => $q->where('assignment_role', 'auditor'))->pluck('company_id')->all();
        $members = DB::table('audit_engagement_members')->where('audit_engagement_members.organization_id', $organization->id)->where('user_id', $user->id)->pluck('company_id')->all();

        return array_values(array_unique(array_map('intval', [...$assigned, ...$members, ...array_keys($this->grants($organization, $user))])));
    }

    /**
     * Active grants of the user keyed by company.
     *
     * @return array<int, stdClass>
     */
    public function grants(Organization $organization, User $user): array
    {
        return DB::table('audit_access_grants')->where('audit_access_grants.organization_id', $organization->id)->where('auditor_user_id', $user->id)->where('status', 'active')
            ->where('valid_from', '<=', now())->where('expires_at', '>', now())->get()->keyBy('company_id')->all();
    }

    /**
     * Restrictions of the user over a company: null when the access is internal (assignment, membership or owner),
     * otherwise the period and modules of the external grant.
     *
     * @return array{period_start: string, period_end: string, modules: list<string>, engagement_id: int|null}|null
     */
    public function restrictions(Organization $organization, User $user, int $companyId): ?array
    {
        if ($this->isOwner($organization, $user)) {
            return null;
        }
        $internal = DB::table('company_assignments')->where('company_assignments.organization_id', $organization->id)->where('user_id', $user->id)->where('company_id', $companyId)->where('status', 'active')->whereNull('deleted_at')->exists()
            || DB::table('audit_engagement_members')->where('audit_engagement_members.organization_id', $organization->id)->where('user_id', $user->id)->where('company_id', $companyId)->exists();
        if ($internal) {
            return null;
        }
        $grant = $this->grants($organization, $user)[$companyId] ?? null;
        abort_if($grant === null, 403, 'No tienes acceso de auditoría a esta empresa.');

        return ['period_start' => (string) $grant->period_start, 'period_end' => (string) $grant->period_end, 'modules' => array_values((array) json_decode((string) $grant->allowed_modules, true)),
            'engagement_id' => $grant->audit_engagement_id === null ? null : (int) $grant->audit_engagement_id];
    }

    /**
     * Validates company, module and period of a review request and returns the period to use (clamped to the grant).
     *
     * @return array{0: string, 1: string}
     */
    public function reviewWindow(Organization $organization, User $user, int $companyId, string $module, string $from, string $to): array
    {
        abort_unless(in_array($companyId, $this->companyIds($organization, $user), true), 403);
        $restrictions = $this->restrictions($organization, $user, $companyId);
        if ($restrictions === null) {
            return [$from, $to];
        }
        abort_unless(in_array($module, $restrictions['modules'], true), 403, 'Tu acceso temporal no incluye este módulo.');

        return [max($from, $restrictions['period_start']), min($to, $restrictions['period_end'])];
    }

    /**
     * Engagements the user may see.
     *
     * @return list<int>
     */
    public function engagementIds(Organization $organization, User $user): array
    {
        $query = DB::table('audit_engagements')->where('audit_engagements.organization_id', $organization->id);
        if (! $this->isOwner($organization, $user)) {
            $creatorCompanies = $this->authorization->can($organization, $user, 'audit.engagements.create') ? $this->companyIds($organization, $user) : [];
            $granted = array_values(array_filter(array_map(fn (stdClass $grant): ?int => $grant->audit_engagement_id === null ? null : (int) $grant->audit_engagement_id, $this->grants($organization, $user))));
            $query->where(fn ($q) => $q->where('lead_auditor_id', $user->id)->orWhere('reviewer_id', $user->id)->orWhere('created_by', $user->id)
                ->orWhereIn('id', DB::table('audit_engagement_members')->where('user_id', $user->id)->select('audit_engagement_id'))
                ->orWhereIn('company_id', $creatorCompanies === [] ? [-1] : $creatorCompanies)->orWhereIn('id', $granted === [] ? [-1] : $granted));
        }

        return array_values(array_map('intval', $query->pluck('id')->all()));
    }

    /** The engagement if the user may see it (404 otherwise, so ids of other tenants or companies leak nothing). */
    public function engagement(Organization $organization, User $user, int $engagementId): stdClass
    {
        abort_unless(in_array($engagementId, $this->engagementIds($organization, $user), true), 404);

        return DB::table('audit_engagements')->where('id', $engagementId)->first() ?? abort(404);
    }

    /** Whether the user works on the engagement (lead, member, reviewer) or is the owner. */
    public function isTeam(Organization $organization, User $user, stdClass $engagement): bool
    {
        return $this->isOwner($organization, $user) || (int) $engagement->lead_auditor_id === $user->id || (int) ($engagement->reviewer_id ?? 0) === $user->id
            || DB::table('audit_engagement_members')->where('audit_engagement_id', $engagement->id)->where('user_id', $user->id)->exists();
    }

    /** Team member holding a permission; aborts otherwise. */
    public function ensureTeam(Organization $organization, User $user, stdClass $engagement, string $permission): void
    {
        abort_unless($this->isTeam($organization, $user, $engagement) && $this->authorization->can($organization, $user, $permission), 403);
    }

    public function can(Organization $organization, User $user, string $permission): bool
    {
        return $this->authorization->can($organization, $user, $permission);
    }
}
