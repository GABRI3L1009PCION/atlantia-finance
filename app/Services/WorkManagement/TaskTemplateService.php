<?php

declare(strict_types=1);

namespace App\Services\WorkManagement;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

class TaskTemplateService
{
    private const DEFAULTS = [
        ['accounting', 'register_documents', 'Registrar documentos del período', 'accounting_assistant', 'accountant_in_charge', 3],
        ['accounting', 'prepare_entry', 'Preparar asientos contables', 'accounting_assistant', 'accountant_in_charge', 5],
        ['banking', 'reconcile_bank', 'Conciliar bancos', 'treasury', 'accountant_in_charge', 7],
        ['receivables', 'review_receivables', 'Revisar cuentas por cobrar', 'accounts_receivable', 'accountant_in_charge', 6],
        ['payables', 'review_payables', 'Revisar cuentas por pagar', 'accounts_payable', 'accountant_in_charge', 6],
        ['tax', 'prepare_vat_books', 'Preparar libros IVA', 'tax_specialist', 'general_accountant', 8],
        ['accounting', 'review_trial_balance', 'Revisar balance de comprobación', 'accountant_in_charge', 'general_accountant', 10],
        ['closing', 'prepare_close', 'Preparar cierre del período', 'accountant_in_charge', 'general_accountant', 12],
        ['closing', 'review_close', 'Revisar cierre del período', 'general_accountant', 'accounting_manager', 14],
    ];

    public function __construct(
        private WorkTaskService $tasks,
        private AccountingService $accounting,
    ) {}

    public function ensureDefaults(Organization $organization): void
    {
        foreach (self::DEFAULTS as [$module, $type, $title, $assignmentRole, $reviewerRole, $days]) {
            DB::table('task_templates')->updateOrInsert(
                [
                    'organization_id' => $organization->id,
                    'module' => $module,
                    'task_type' => $type,
                    'title' => $title,
                ],
                [
                    'description' => 'Tarea mensual generada desde plantilla operativa.',
                    'assignment_role' => $assignmentRole,
                    'reviewer_role' => $reviewerRole,
                    'default_priority' => $module === 'closing' ? 'high' : 'normal',
                    'default_due_days' => $days,
                    'is_active' => true,
                    'metadata' => json_encode(['default' => true], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    /** @return array<int, int> */
    public function generateForPeriod(Organization $organization, User $actor, int $companyId, int $periodId): array
    {
        $this->ensureDefaults($organization);
        $company = DB::table('companies')
            ->join('offices', 'offices.id', '=', 'companies.office_id')
            ->where('offices.organization_id', $organization->id)
            ->where('companies.id', $companyId)
            ->select('companies.id', 'companies.name', 'companies.office_id')
            ->firstOrFail();
        $period = DB::table('accounting_periods')->where('company_id', $companyId)->where('id', $periodId)->firstOrFail();
        $assignments = $this->activeAssignments($organization->id, $companyId);
        $created = [];

        DB::transaction(function () use ($organization, $actor, $company, $period, $assignments, &$created): void {
            $templates = DB::table('task_templates')
                ->where('task_templates.organization_id', $organization->id)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->get();

            foreach ($templates as $template) {
                $assignee = $this->assignmentForRole($assignments, $template->assignment_role);
                $reviewer = $this->assignmentForRole($assignments, $template->reviewer_role);
                $created[] = $this->tasks->create($organization, $actor, [
                    'office_id' => $company->office_id,
                    'company_id' => $company->id,
                    'accounting_period_id' => $period->id,
                    'module' => $template->module,
                    'task_type' => $template->task_type,
                    'title' => $template->title.' - '.$period->name,
                    'description' => $template->description,
                    'assigned_to' => $assignee,
                    'reviewer_id' => $reviewer,
                    'priority' => $template->default_priority,
                    'due_at' => now()->addDays((int) $template->default_due_days),
                    'automation_key' => implode(':', ['period', $company->id, $period->id, $template->task_type]),
                    'metadata' => ['template_id' => $template->id],
                ]);
            }

            $this->accounting->audit($organization, $actor, 'tasks.generated_from_templates', 'accounting_periods', $period->id, [
                'company_id' => $company->id,
                'tasks' => count($created),
            ]);
        });

        return $created;
    }

    /** @return Collection<array-key, Collection<int, stdClass>> */
    private function activeAssignments(int $organizationId, int $companyId): Collection
    {
        return DB::table('company_assignments')
            ->where('company_assignments.organization_id', $organizationId)
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get()
            ->groupBy('assignment_role');
    }

    /** @param Collection<array-key, Collection<int, stdClass>> $assignments */
    private function assignmentForRole(Collection $assignments, ?string $role): ?int
    {
        if ($role === null) {
            return null;
        }

        $assignment = $assignments->get($role)?->first();

        return $assignment ? (int) $assignment->user_id : null;
    }
}
