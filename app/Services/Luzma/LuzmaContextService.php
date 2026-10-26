<?php

declare(strict_types=1);

namespace App\Services\Luzma;

use App\Models\User;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Builds the authorized Luzma context. IDs sent by the browser are hints: the tenant comes from the session, the
 * company must be one the user can access, the period must belong to that company and the entity to that company.
 */
class LuzmaContextService
{
    public const MODULES = [
        'global', 'dashboard', 'executive', 'office', 'operations', 'supervisor', 'accountant', 'assistant', 'tax', 'treasury', 'receivables', 'payables', 'payroll', 'audit_desk', 'client',
        'accounting', 'journal', 'ledger', 'trial_balance', 'financial_statements', 'banks', 'reconciliation', 'inventory', 'assets', 'documents', 'fel',
        'reports', 'closing', 'audit', 'sales', 'purchases', 'library',
    ];

    /** Entities a contextual question may point to, with the table that proves their company. */
    public const ENTITIES = [
        'purchase_document' => 'purchase_documents', 'sales_invoice' => 'sales_invoices', 'payment_proposal' => 'payment_proposals', 'fiscal_obligation' => 'fiscal_obligations',
        'journal_entry' => 'journal_entries', 'ledger_account' => 'ledger_accounts', 'bank_reconciliation' => 'bank_reconciliations', 'payment_instruction' => 'payment_instructions',
    ];

    public function __construct(
        private WorkspaceContext $workspace,
        private WorkAuthorizationService $authorization,
    ) {}

    /**
     * @param  array{company?: string|null, company_id?: int|string|null, period_id?: int|string|null, module?: string|null, route?: string|null, entity_type?: string|null, entity_id?: int|string|null}  $input
     */
    public function resolve(User $user, array $input = []): LuzmaContext
    {
        $organization = $this->workspace->organization($user);
        $this->authorization->ensureDefaults($organization);
        abort_unless($this->authorization->can($organization, $user, 'luzma.use'), 403, 'Tu rol no tiene acceso a Luzma.');
        $companyIds = $this->accessibleCompanyIds($organization->id, $user);

        $company = null;
        // A screen about one record (a purchase, an entry...) implies its company, if the user can access it.
        if (empty($input['company']) && isset(self::ENTITIES[(string) ($input['entity_type'] ?? '')]) && (int) ($input['entity_id'] ?? 0) > 0) {
            $owner = DB::table(self::ENTITIES[(string) $input['entity_type']])->where('id', (int) $input['entity_id'])->value('company_id');
            if ($owner !== null && in_array((int) $owner, $companyIds, true)) {
                $company = DB::table('companies')->where('id', (int) $owner)->first(['id', 'name', 'office_id']);
            }
        }
        if (empty($input['company']) && ! empty($input['company_id'])) {
            $company = DB::table('companies')->where('companies.organization_id', $organization->id)->where('id', (int) $input['company_id'])->first(['id', 'name', 'office_id']);
            abort_unless($company !== null && in_array((int) $company->id, $companyIds, true), 403, 'No tienes acceso a esa empresa.');
        }
        if (! empty($input['company'])) {
            $company = DB::table('companies')->where('companies.organization_id', $organization->id)->where('public_id', (string) $input['company'])->first(['id', 'name', 'office_id']);
            abort_unless($company !== null && in_array((int) $company->id, $companyIds, true), 403, 'No tienes acceso a esa empresa.');
        }

        $period = null;
        if ($company !== null) {
            $periods = DB::table('accounting_periods')->where('company_id', $company->id);
            $row = ! empty($input['period_id'])
                ? (clone $periods)->where('id', (int) $input['period_id'])->first(['id', 'name', 'starts_on', 'ends_on'])
                : null;
            $row ??= (clone $periods)->where('starts_on', '<=', now()->toDateString())->where('ends_on', '>=', now()->toDateString())->first(['id', 'name', 'starts_on', 'ends_on']);
            $period = $row === null ? null : ['id' => (int) $row->id, 'name' => (string) $row->name, 'starts_on' => (string) $row->starts_on, 'ends_on' => (string) $row->ends_on];
        }

        [$entityType, $entityId] = $this->entity($input, $company);
        $module = in_array($input['module'] ?? 'global', self::MODULES, true) ? (string) ($input['module'] ?? 'global') : 'global';
        $timezone = $company === null ? (string) config('app.timezone')
            : (string) (DB::table('company_fiscal_profiles')->where('company_id', $company->id)->value('timezone') ?: config('app.timezone'));

        return new LuzmaContext(
            organization: $organization,
            user: $user,
            role: $this->authorization->primaryRole($organization, $user),
            companyIds: $companyIds,
            companyId: $company === null ? null : (int) $company->id,
            companyName: $company === null ? null : (string) $company->name,
            officeId: $company === null ? null : (int) $company->office_id,
            period: $period,
            module: $module,
            route: isset($input['route']) ? mb_substr(preg_replace('/[^\w\/\-?=&.]/u', '', (string) $input['route']) ?? '', 0, 200) : null,
            entityType: $entityType,
            entityId: $entityId,
            timezone: $timezone,
        );
    }

    /**
     * Companies the user may see: all of the organization for owners; otherwise active assignments and explicit
     * company access, always inside the tenant.
     *
     * @return list<int>
     */
    public function accessibleCompanyIds(int $organizationId, User $user): array
    {
        $isOwner = DB::table('organization_user')->where('organization_user.organization_id', $organizationId)->where('user_id', $user->id)->where('role', 'owner')->where('status', 'active')->exists();
        if ($isOwner) {
            return array_values(array_map('intval', DB::table('companies')->where('companies.organization_id', $organizationId)->orderBy('id')->pluck('id')->all()));
        }
        $assigned = DB::table('company_assignments')->where('company_assignments.organization_id', $organizationId)->where('user_id', $user->id)->where('status', 'active')->whereNull('deleted_at')->pluck('company_id');
        $explicit = DB::table('company_user')->join('companies', 'companies.id', '=', 'company_user.company_id')->where('companies.organization_id', $organizationId)->where('company_user.user_id', $user->id)->pluck('company_user.company_id');
        $client = DB::table('client_company_access')->where('client_company_access.organization_id', $organizationId)->where('user_id', $user->id)->where('status', 'active')->pluck('company_id');
        $ids = array_values(array_unique(array_map('intval', [...$assigned->all(), ...$explicit->all(), ...$client->all()])));
        sort($ids);

        return $ids;
    }

    /**
     * A company named in the text among the accessible ones ("recuérdame revisar Comercial Maya"), longest name first.
     *
     * @return array{id: int, name: string}|null
     */
    public function companyFromText(LuzmaContext $context, string $text): ?array
    {
        $needle = $this->normalize($text);
        $companies = DB::table('companies')->whereIn('id', $context->companyIds === [] ? [-1] : $context->companyIds)->get(['id', 'name'])
            ->sortByDesc(fn (stdClass $company): int => mb_strlen((string) $company->name));
        foreach ($companies as $company) {
            if (str_contains($needle, $this->normalize((string) $company->name))) {
                return ['id' => (int) $company->id, 'name' => (string) $company->name];
            }
        }

        return null;
    }

    /** Lowercase without accents, so "Número" and "numero" read the same. */
    public static function fold(string $text): string
    {
        return strtr(mb_strtolower($text), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    }

    public function normalize(string $text): string
    {
        $text = mb_strtolower($text);

        return strtr($text, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: string|null, 1: int|null}
     */
    private function entity(array $input, ?stdClass $company): array
    {
        $type = (string) ($input['entity_type'] ?? '');
        $id = (int) ($input['entity_id'] ?? 0);
        if ($company === null || $id <= 0 || ! isset(self::ENTITIES[$type])) {
            return [null, null];
        }
        $belongs = DB::table(self::ENTITIES[$type])->where('id', $id)->where('company_id', $company->id)->exists();

        return $belongs ? [$type, $id] : [null, null];
    }
}
