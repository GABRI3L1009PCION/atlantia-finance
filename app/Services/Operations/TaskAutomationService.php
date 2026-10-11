<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\WorkManagement\WorkTaskService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Turns pending work found in the real modules into tasks or incidents, once (automation_key), and closes automated
 * tasks when the module shows the work is done. The task only tracks responsibility; the work lives in the module.
 */
class TaskAutomationService
{
    public function __construct(
        private WorkTaskService $tasks,
        private OperationalIncidentService $incidents,
        private AccountingService $accounting,
    ) {}

    /**
     * @param  list<int>  $officeIds
     * @return array{created: int, completed: int, incidents: int}
     */
    public function sync(Organization $organization, User $actor, array $officeIds): array
    {
        $created = 0;
        $completed = 0;
        $incidents = 0;

        $companies = DB::table('companies')->where('companies.organization_id', $organization->id)->whereIn('office_id', $officeIds === [] ? [-1] : $officeIds)->get(['id', 'office_id', 'name']);
        foreach ($companies as $company) {
            $period = DB::table('accounting_periods')->where('company_id', $company->id)->whereNull('closed_at')->orderByDesc('starts_on')->first(['id', 'starts_on', 'ends_on', 'name']);
            if ($period === null) {
                continue;
            }

            $responsible = $this->responsible((int) $company->id, ['treasury', 'accounting_assistant', 'accountant_in_charge']);
            $reviewer = $this->responsible((int) $company->id, ['accountant_in_charge', 'supervisor']);
            $dueAt = Carbon::parse($period->ends_on)->addDays(10)->endOfDay()->toDateTimeString();

            $banks = DB::table('bank_accounts')->where('company_id', $company->id)->where('status', 'active')->get(['id', 'bank_name', 'account_name', 'last_four']);
            foreach ($banks as $bank) {
                $key = "bank_reconciliation:{$company->id}:{$bank->id}:{$period->id}";
                $pending = DB::table('bank_transactions')->where('bank_account_id', $bank->id)->where('reconciliation_status', 'pending')
                    ->whereBetween('transaction_date', [$period->starts_on, $period->ends_on])->count();
                $existing = DB::table('work_tasks')->where('work_tasks.organization_id', $organization->id)->where('automation_key', $key)->whereNull('deleted_at')->first(['id', 'status']);

                if ($pending > 0 && $existing === null) {
                    $this->tasks->create($organization, $actor, [
                        'office_id' => (int) $company->office_id,
                        'company_id' => (int) $company->id,
                        'accounting_period_id' => (int) $period->id,
                        'module' => 'banking',
                        'task_type' => 'reconcile_bank',
                        'title' => "Conciliar {$bank->bank_name} ...{$bank->last_four}",
                        'description' => "{$pending} movimiento(s) de {$period->name} sin conciliar.",
                        'assigned_to' => $responsible,
                        'reviewer_id' => $reviewer !== $responsible ? $reviewer : null,
                        'priority' => $pending > 20 ? 'high' : 'normal',
                        'due_at' => $dueAt,
                        'source_type' => 'bank_accounts',
                        'source_id' => (int) $bank->id,
                        'automation_key' => $key,
                    ]);
                    $created++;
                } elseif ($pending === 0 && $existing !== null && ! in_array($existing->status, ['completed', 'approved', 'cancelled'], true)) {
                    DB::table('work_tasks')->where('id', $existing->id)->update(['status' => 'completed', 'completed_at' => now(), 'updated_at' => now()]);
                    $this->accounting->audit($organization, $actor, 'task.completed', 'work_tasks', (int) $existing->id, ['source' => 'automation', 'reason' => 'Sin movimientos pendientes de conciliar'], (int) $company->office_id);
                    $completed++;
                }
            }

            $draftDocuments = DB::table('operational_records')->where('company_id', $company->id)->where('module', 'documentos')->whereIn('status', ['draft', 'review'])->count();
            $documentsKey = "documents_review:{$company->id}:{$period->id}";
            if ($draftDocuments > 0 && ! DB::table('work_tasks')->where('work_tasks.organization_id', $organization->id)->where('automation_key', $documentsKey)->whereNull('deleted_at')->exists()) {
                $this->tasks->create($organization, $actor, [
                    'office_id' => (int) $company->office_id,
                    'company_id' => (int) $company->id,
                    'accounting_period_id' => (int) $period->id,
                    'module' => 'documents',
                    'task_type' => 'classify_documents',
                    'title' => 'Clasificar documentos pendientes',
                    'description' => "{$draftDocuments} documento(s) sin publicar.",
                    'assigned_to' => $this->responsible((int) $company->id, ['accounting_assistant', 'accountant_in_charge']),
                    'priority' => 'normal',
                    'due_at' => $dueAt,
                    'source_type' => 'operational_records',
                    'automation_key' => $documentsKey,
                ]);
                $created++;
            }

            $failedFel = DB::table('fel_documents')->where('company_id', $company->id)->where('status', 'failed')->get(['id', 'series', 'number']);
            foreach ($failedFel as $document) {
                $before = DB::table('operational_incidents')->where('operational_incidents.organization_id', $organization->id)->where('automation_key', "fel_failed:{$document->id}")->exists();
                $this->incidents->create($organization, $actor, [
                    'office_id' => (int) $company->office_id,
                    'company_id' => (int) $company->id,
                    'accounting_period_id' => (int) $period->id,
                    'type' => 'fel',
                    'severity' => 'high',
                    'title' => "FEL {$document->series}-{$document->number} con error de certificación",
                    'description' => 'El documento no fue certificado; corrige y vuelve a certificar desde FEL.',
                    'responsible_id' => $responsible,
                    'blocks_period' => false,
                    'source_type' => 'fel_documents',
                    'source_id' => (int) $document->id,
                    'automation_key' => "fel_failed:{$document->id}",
                ]);
                $incidents += $before ? 0 : 1;
            }
        }

        return ['created' => $created, 'completed' => $completed, 'incidents' => $incidents];
    }

    /** @param list<string> $roles  first role that has an active holder wins */
    private function responsible(int $companyId, array $roles): ?int
    {
        foreach ($roles as $role) {
            $userId = DB::table('company_assignments')->where('company_id', $companyId)->where('assignment_role', $role)->where('status', 'active')->whereNull('deleted_at')
                ->orderByDesc('is_primary')->value('user_id');
            if ($userId !== null) {
                return (int) $userId;
            }
        }

        return null;
    }
}
