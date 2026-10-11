<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Read-only view of tenant content during an active support access, limited to the scopes granted. Every section
 * opened is audited (support_access.viewed). Secrets (API keys, webhook secrets, passwords, bank accounts) are never
 * shown, even within scope.
 */
class SupportViewerService
{
    public function __construct(private SupportAccessService $access, private PlatformAuditService $audit) {}

    /** @return array<string, mixed> */
    public function view(User $actor, int $requestId, string $scope): array
    {
        $session = $this->access->session($actor, $requestId, $scope);
        $organizationId = (int) $session->organization_id;
        $this->audit->record($actor, 'support_access.viewed', 'support_access_requests', $requestId, null, ['scope' => $scope], null, $organizationId);

        return match ($scope) {
            'structure' => $this->structure($organizationId),
            'configuration' => $this->configuration($organizationId),
            'accounting' => $this->accounting($organizationId),
            'documents' => $this->documents($organizationId),
            'integrations' => $this->integrations($organizationId),
            'payroll' => $this->payroll($organizationId),
            default => abort(404),
        };
    }

    /** @return array<string, mixed> */
    private function structure(int $organizationId): array
    {
        return [
            'offices' => DB::table('offices')->where('offices.organization_id', $organizationId)->orderBy('name')->get(['id', 'code', 'name', 'city', 'status', 'is_primary'])->all(),
            'companies' => DB::table('companies')->join('offices', 'offices.id', '=', 'companies.office_id')->where('companies.organization_id', $organizationId)->orderBy('companies.name')
                ->get(['companies.id', 'companies.name', 'companies.tax_id', 'companies.currency', 'offices.name as office'])->all(),
            'users' => DB::table('organization_user')->join('users', 'users.id', '=', 'organization_user.user_id')->where('organization_user.organization_id', $organizationId)
                ->get(['users.name', 'users.email', 'organization_user.role', 'organization_user.status'])->all(),
            'roles' => DB::table('user_role_assignments')->join('workspace_roles', 'workspace_roles.id', '=', 'user_role_assignments.workspace_role_id')->join('users', 'users.id', '=', 'user_role_assignments.user_id')
                ->where('user_role_assignments.organization_id', $organizationId)->where('user_role_assignments.status', 'active')->whereNull('user_role_assignments.deleted_at')
                ->get(['users.name', 'workspace_roles.name as role'])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function configuration(int $organizationId): array
    {
        return [
            'offices' => DB::table('offices')->where('offices.organization_id', $organizationId)->get(['name', 'timezone', 'require_two_factor', 'review_workflow', 'fiscal_close_policy', 'treasury_close_policy', 'payroll_close_policy'])->all(),
            'fiscal_profiles' => DB::table('company_fiscal_profiles')->join('companies', 'companies.id', '=', 'company_fiscal_profiles.company_id')->where('company_fiscal_profiles.organization_id', $organizationId)
                ->get(['companies.name as company', 'vat_regime', 'income_tax_regime', 'accounting_template', 'base_currency', 'timezone'])->all(),
            'fel' => DB::table('company_fel_settings')->join('companies', 'companies.id', '=', 'company_fel_settings.company_id')->where('companies.organization_id', $organizationId)
                ->get(['companies.name as company', 'company_fel_settings.legal_name', 'company_fel_settings.nit', 'company_fel_settings.provider', 'company_fel_settings.environment'])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function accounting(int $organizationId): array
    {
        return [
            'periods' => DB::table('accounting_periods')->join('companies', 'companies.id', '=', 'accounting_periods.company_id')->where('accounting_periods.organization_id', $organizationId)
                ->orderByDesc('accounting_periods.starts_on')->limit(60)->get(['companies.name as company', 'accounting_periods.name', 'accounting_periods.status', 'accounting_periods.closed_at'])->all(),
            'entries' => DB::table('journal_entries')->join('accounting_periods', 'accounting_periods.id', '=', 'journal_entries.accounting_period_id')->join('companies', 'companies.id', '=', 'accounting_periods.company_id')
                ->where('journal_entries.organization_id', $organizationId)->orderByDesc('journal_entries.id')->limit(50)
                ->get(['companies.name as company', 'journal_entries.number', 'journal_entries.entry_date', 'journal_entries.description', 'journal_entries.status', 'journal_entries.kind',
                    DB::raw('(SELECT CAST(COALESCE(SUM(debit_cents), 0) AS BIGINT) FROM journal_lines WHERE journal_lines.journal_entry_id = journal_entries.id) as total_cents')])->all(),
            'pending_entries' => DB::table('journal_entries')->where('organization_id', $organizationId)->whereIn('status', ['draft', 'pending_review', 'reviewed', 'approved'])->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function documents(int $organizationId): array
    {
        $files = [];
        foreach (['purchase_document_files' => 'Compras', 'journal_entry_documents' => 'Asientos', 'operational_record_files' => 'Operaciones'] as $table => $label) {
            foreach (DB::table($table)->where($table.'.organization_id', $organizationId)->orderByDesc('id')->limit(40)->get(['original_name', 'mime_type', 'size_bytes', 'created_at']) as $file) {
                $files[] = ['area' => $label, 'name' => (string) $file->original_name, 'mime' => $file->mime_type, 'size_bytes' => (int) $file->size_bytes, 'created_at' => Carbon::parse($file->created_at)->toIso8601String()];
            }
        }
        usort($files, fn (array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));

        return ['files' => array_slice($files, 0, 100),
            'client_documents' => DB::table('client_documents')->where('organization_id', $organizationId)->orderByDesc('id')->limit(50)->get(['title', 'category', 'status', 'current_version', 'created_at'])->all()];
    }

    /** @return array<string, mixed> */
    private function integrations(int $organizationId): array
    {
        return [
            'api_tokens' => DB::table('api_tokens')->where('api_tokens.organization_id', $organizationId)->orderByDesc('id')->get(['name', 'token_prefix', 'abilities', 'last_used_at', 'revoked_at', 'expires_at'])
                ->map(fn (stdClass $row): array => [...(array) $row, 'abilities' => json_decode((string) $row->abilities, true)])->all(),
            'webhooks' => DB::table('webhook_endpoints')->where('webhook_endpoints.organization_id', $organizationId)->get(['name', 'url', 'status', 'consecutive_failures', 'disabled_reason', 'last_success_at', 'last_failure_at'])->all(),
            'failed_deliveries' => DB::table('webhook_deliveries')->where('webhook_deliveries.organization_id', $organizationId)->where('status', 'failed')->orderByDesc('id')->limit(30)
                ->get(['event', 'attempts', 'response_status', 'error', 'created_at'])->all(),
            'api_errors' => DB::table('api_request_logs')->where('api_request_logs.organization_id', $organizationId)->where('status', '>=', 400)->orderByDesc('id')->limit(30)
                ->get(['method', 'path', 'status', 'created_at'])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function payroll(int $organizationId): array
    {
        return [
            'periods' => DB::table('payroll_periods')->join('companies', 'companies.id', '=', 'payroll_periods.company_id')->where('payroll_periods.organization_id', $organizationId)
                ->orderByDesc('payroll_periods.starts_on')->limit(30)->get(['companies.name as company', 'payroll_periods.name', 'payroll_periods.status', 'payroll_periods.employees_count',
                    'payroll_periods.gross_cents', 'payroll_periods.net_cents'])->all(),
            'employees' => DB::table('payroll_employees')->join('companies', 'companies.id', '=', 'payroll_employees.company_id')->where('payroll_employees.organization_id', $organizationId)
                ->orderBy('payroll_employees.last_name')->limit(200)->get(['companies.name as company', 'payroll_employees.first_name', 'payroll_employees.last_name', 'payroll_employees.position',
                    'payroll_employees.status', 'payroll_employees.base_salary_cents'])->all(),
        ];
    }
}
