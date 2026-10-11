<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\WorkManagement\WorkNotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns each company's fiscal configuration into persisted obligations (idempotent per company, type and reference
 * month), keeps their deadlines and responsibles current while untouched, and keeps the related automatic work in
 * sync: one preparation task per open obligation, one task per failed FEL document and one incident per overdue
 * obligation. Notifications are grouped per person.
 */
class FiscalObligationGenerationService
{
    public function __construct(
        private FiscalObligationService $obligations,
        private AccountingService $accounting,
        private WorkNotificationService $notifications,
    ) {}

    /**
     * @param  list<int>  $companyIds
     * @return array{created: int, updated: int, tasks: int, incidents: int}
     */
    public function generate(Organization $organization, ?User $actor, array $companyIds): array
    {
        if ($companyIds === []) {
            return ['created' => 0, 'updated' => 0, 'tasks' => 0, 'incidents' => 0];
        }

        return DB::transaction(function () use ($organization, $actor, $companyIds): array {
            $rows = $this->obligations->obligations($companyIds, now()->subMonths(3)->startOfMonth(), now()->addMonths(2)->endOfMonth());
            $companies = DB::table('companies')->join('offices', 'offices.id', '=', 'companies.office_id')->whereIn('companies.id', $companyIds)
                ->get(['companies.id', 'companies.office_id', 'offices.fiscal_internal_lead_days', 'offices.fiscal_review_lead_days'])->keyBy('id');
            $roles = DB::table('company_assignments')->whereIn('company_id', $companyIds)->where('status', 'active')->whereNull('deleted_at')
                ->whereIn('assignment_role', ['tax_specialist', 'accountant_in_charge'])->orderByDesc('is_primary')->get(['company_id', 'user_id', 'assignment_role']);
            $created = 0;
            $updated = 0;
            $newFor = [];

            foreach ($rows as $row) {
                $company = $companies->get($row['company_id']);
                if ($company === null) {
                    continue;
                }
                $due = Carbon::parse($row['due_on']);
                $assignee = $roles->where('company_id', $row['company_id'])->firstWhere('assignment_role', 'tax_specialist')?->user_id;
                $reviewer = $roles->where('company_id', $row['company_id'])->firstWhere('assignment_role', 'accountant_in_charge')?->user_id;
                $monthEnd = Carbon::create($row['year'], $row['month'], 1)->endOfMonth()->toDateString();
                $values = [
                    'office_id' => (int) $company->office_id,
                    'accounting_period_id' => DB::table('accounting_periods')->where('company_id', $row['company_id'])->where('starts_on', '<=', $monthEnd)->where('ends_on', '>=', $monthEnd)->value('id'),
                    'obligation_type' => $row['type'],
                    'name' => $row['name'],
                    'reference_period' => $row['period'],
                    'reference_year' => $row['year'],
                    'reference_month' => $row['month'],
                    'due_on' => $due->toDateString(),
                    'internal_due_on' => $due->copy()->subDays((int) $company->fiscal_internal_lead_days)->toDateString(),
                    'review_due_on' => $due->copy()->subDays((int) $company->fiscal_review_lead_days)->toDateString(),
                    'configuration_version' => $row['configuration_version'],
                    'assigned_to' => $assignee === null ? null : (int) $assignee,
                    'reviewer_id' => $reviewer === null ? null : (int) $reviewer,
                    'updated_at' => now(),
                ];
                $existing = DB::table('fiscal_obligations')->where('fiscal_obligations.organization_id', $organization->id)->where('generation_key', $row['key'])->first(['id', 'status']);

                if ($existing === null) {
                    $id = DB::table('fiscal_obligations')->insertGetId([...$values, 'public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'company_id' => $row['company_id'], 'generation_key' => $row['key'], 'status' => 'pending', 'created_at' => now()]);
                    $created++;
                    if ($values['assigned_to'] !== null) {
                        $newFor[$values['assigned_to']][] = $id;
                    }
                    if ($actor !== null) {
                        $this->accounting->audit($organization, $actor, 'fiscal.obligation.generated', 'fiscal_obligations', $id, ['company_id' => $row['company_id'], 'type' => $row['type'], 'period' => $row['period'], 'due_on' => $values['due_on']], $values['office_id']);
                    }
                } elseif ($existing->status === 'pending') {
                    DB::table('fiscal_obligations')->where('id', $existing->id)->update($values);
                    $updated++;
                }
            }

            foreach ($newFor as $userId => $ids) {
                $this->notifications->notify($organization->id, (int) $userId, $actor?->id, 'fiscal.obligations.generated', count($ids).' obligación(es) fiscal(es) nueva(s) a tu cargo', 'Revisa tu calendario fiscal.', 'fiscal_obligations', (int) $ids[0]);
            }

            return ['created' => $created, 'updated' => $updated, ...$this->syncWork($organization, $companyIds)];
        });
    }

    /**
     * Automatic work derived from the fiscal state; every item has an automation key so it is never duplicated.
     *
     * @param  list<int>  $companyIds
     * @return array{tasks: int, incidents: int}
     */
    public function syncWork(Organization $organization, array $companyIds): array
    {
        $tasks = 0;
        $incidents = 0;
        $open = DB::table('fiscal_obligations')->where('fiscal_obligations.organization_id', $organization->id)->whereIn('company_id', $companyIds === [] ? [-1] : $companyIds)
            ->whereIn('status', ['pending', 'in_progress', 'changes_requested'])->whereNotNull('assigned_to')->get();
        foreach ($open as $obligation) {
            $tasks += $this->ensureTask($organization->id, 'fiscal_obligation:'.$obligation->id, [
                'office_id' => $obligation->office_id,
                'company_id' => $obligation->company_id,
                'accounting_period_id' => $obligation->accounting_period_id,
                'task_type' => 'prepare_obligation',
                'title' => 'Preparar '.$obligation->name.' · '.$obligation->reference_period,
                'assigned_to' => $obligation->assigned_to,
                'reviewer_id' => $obligation->reviewer_id,
                'due_at' => Carbon::parse($obligation->internal_due_on)->endOfDay(),
                'source_type' => 'fiscal_obligations',
                'source_id' => $obligation->id,
            ]);

            if (Carbon::parse($obligation->due_on)->endOfDay()->isPast()) {
                $key = 'fiscal_overdue:'.$obligation->id;
                if (! DB::table('operational_incidents')->where('operational_incidents.organization_id', $organization->id)->where('automation_key', $key)->exists()) {
                    DB::table('operational_incidents')->insert([
                        'public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'office_id' => $obligation->office_id, 'company_id' => $obligation->company_id,
                        'accounting_period_id' => $obligation->accounting_period_id, 'type' => 'fiscal', 'severity' => 'high', 'status' => 'open',
                        'title' => 'Obligación vencida: '.$obligation->name.' · '.$obligation->reference_period, 'description' => 'Venció el '.Carbon::parse($obligation->due_on)->format('d/m/Y').' sin presentarse.',
                        'blocks_period' => false, 'responsible_id' => $obligation->assigned_to, 'source_type' => 'fiscal_obligations', 'source_id' => $obligation->id,
                        'automation_key' => $key, 'due_at' => null, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $incidents++;
                }
            }
        }

        $specialists = DB::table('company_assignments')->whereIn('company_id', $companyIds === [] ? [-1] : $companyIds)->where('assignment_role', 'tax_specialist')->where('status', 'active')->whereNull('deleted_at')
            ->orderByDesc('is_primary')->get(['company_id', 'user_id'])->unique('company_id')->keyBy('company_id');
        foreach (DB::table('fel_documents')->join('companies', 'companies.id', '=', 'fel_documents.company_id')->whereIn('fel_documents.company_id', $companyIds === [] ? [-1] : $companyIds)
            ->where('fel_documents.status', 'failed')->get(['fel_documents.id', 'fel_documents.company_id', 'fel_documents.series', 'fel_documents.number', 'companies.office_id']) as $document) {
            $assignee = $specialists->get($document->company_id)?->user_id;
            $tasks += $this->ensureTask($organization->id, 'fel_incident:'.$document->id, [
                'office_id' => $document->office_id, 'company_id' => $document->company_id, 'accounting_period_id' => null,
                'task_type' => 'review_fel_incident', 'title' => 'Resolver FEL con error '.$document->series.'-'.$document->number,
                'assigned_to' => $assignee, 'reviewer_id' => null, 'due_at' => now()->addDay()->endOfDay(), 'source_type' => 'fel_documents', 'source_id' => $document->id,
            ]);
        }

        // Automatic work closes itself when the source is done.
        $settledFel = DB::table('fel_documents')->whereIn('company_id', $companyIds === [] ? [-1] : $companyIds)->whereIn('status', ['certified', 'cancelled'])->pluck('id')->map(fn ($id): string => 'fel_incident:'.$id)->all();
        DB::table('work_tasks')->where('work_tasks.organization_id', $organization->id)->whereIn('automation_key', $settledFel === [] ? ['-'] : $settledFel)->whereNotIn('status', ['completed', 'cancelled'])
            ->update(['status' => 'completed', 'completed_at' => now(), 'updated_at' => now()]);

        return ['tasks' => $tasks, 'incidents' => $incidents];
    }

    /** @param array<string, mixed> $data */
    private function ensureTask(int $organizationId, string $key, array $data): int
    {
        if ($data['assigned_to'] === null || DB::table('work_tasks')->where('work_tasks.organization_id', $organizationId)->where('automation_key', $key)->exists()) {
            return 0;
        }

        DB::table('work_tasks')->insert([...$data, 'public_id' => (string) Str::uuid(), 'organization_id' => $organizationId, 'module' => 'tax', 'priority' => 'high', 'status' => 'pending', 'automation_key' => $key, 'created_at' => now(), 'updated_at' => now()]);

        return 1;
    }
}
