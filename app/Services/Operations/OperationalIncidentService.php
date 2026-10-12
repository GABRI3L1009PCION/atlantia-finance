<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\WorkManagement\WorkNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Operational incidents that stop or endanger a company's period. Critical or period-blocking incidents mark the
 * company as blocked (through CompanyPeriodStatusService) until they are resolved or dismissed.
 */
class OperationalIncidentService
{
    public const TYPES = [
        'document' => 'Documentos',
        'bank_reconciliation' => 'Conciliación bancaria',
        'accounting' => 'Contabilidad',
        'fel' => 'FEL',
        'receivables' => 'Cuentas por cobrar',
        'payables' => 'Cuentas por pagar',
        'fiscal' => 'Fiscal',
        'inventory' => 'Inventario',
        'payroll' => 'Nómina',
        'review' => 'Revisión',
        'close' => 'Cierre',
        'assignment' => 'Asignación',
        'system' => 'Sistema',
    ];

    public const SEVERITIES = ['low', 'medium', 'high', 'critical'];

    public const STATUSES = ['open', 'in_progress', 'resolved', 'dismissed', 'blocked'];

    public function __construct(
        private AccountingService $accounting,
        private WorkNotificationService $notifications,
    ) {}

    /**
     * @param  array{office_id: int, company_id?: int|null, accounting_period_id?: int|null, type: string, severity: string, title: string, description?: string|null, responsible_id?: int|null, blocks_period?: bool, due_at?: string|null, source_type?: string|null, source_id?: int|null, automation_key?: string|null}  $data
     */
    public function create(Organization $organization, ?User $actor, array $data): int
    {
        if (! array_key_exists($data['type'], self::TYPES) || ! in_array($data['severity'], self::SEVERITIES, true)) {
            throw ValidationException::withMessages(['type' => 'Tipo o severidad de incidencia inválidos.']);
        }
        if (! empty($data['company_id'])) {
            abort_unless(DB::table('companies')->where('companies.organization_id', $organization->id)->where('id', $data['company_id'])->where('office_id', $data['office_id'])->exists(), 404);
        }
        if (! empty($data['responsible_id'])) {
            $this->assertOfficeMember($organization->id, (int) $data['office_id'], (int) $data['responsible_id']);
        }

        return DB::transaction(function () use ($organization, $actor, $data): int {
            if (! empty($data['automation_key'])) {
                $existing = DB::table('operational_incidents')->where('operational_incidents.organization_id', $organization->id)->where('automation_key', $data['automation_key'])->value('id');
                if ($existing !== null) {
                    return (int) $existing;
                }
            }

            $id = DB::table('operational_incidents')->insertGetId([
                'public_id' => (string) Str::uuid(),
                'organization_id' => $organization->id,
                'office_id' => (int) $data['office_id'],
                'company_id' => $data['company_id'] ?? null,
                'accounting_period_id' => $data['accounting_period_id'] ?? null,
                'type' => $data['type'],
                'severity' => $data['severity'],
                'status' => 'open',
                'title' => mb_substr($data['title'], 0, 180),
                'description' => $data['description'] ?? null,
                'blocks_period' => (bool) ($data['blocks_period'] ?? $data['severity'] === 'critical'),
                'responsible_id' => $data['responsible_id'] ?? null,
                'created_by' => $actor?->id,
                'source_type' => $data['source_type'] ?? null,
                'source_id' => $data['source_id'] ?? null,
                'automation_key' => $data['automation_key'] ?? null,
                'due_at' => $data['due_at'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($actor !== null) {
                $this->accounting->audit($organization, $actor, 'incident.created', 'operational_incidents', $id, ['type' => $data['type'], 'severity' => $data['severity'], 'company_id' => $data['company_id'] ?? null], (int) $data['office_id']);
            }
            $this->notifications->notify($organization->id, $data['responsible_id'] ?? null, $actor?->id, 'incident.assigned', 'Incidencia asignada: '.$data['title'], $data['description'] ?? null, 'operational_incidents', $id);

            return $id;
        });
    }

    public function update(Organization $organization, User $actor, int $incidentId, string $status, ?int $responsibleId, ?string $resolution): void
    {
        abort_unless(in_array($status, self::STATUSES, true), 422);

        DB::transaction(function () use ($organization, $actor, $incidentId, $status, $responsibleId, $resolution): void {
            $incident = DB::table('operational_incidents')->where('operational_incidents.organization_id', $organization->id)->where('id', $incidentId)->lockForUpdate()->firstOrFail();
            if ($responsibleId !== null) {
                $this->assertOfficeMember($organization->id, (int) $incident->office_id, $responsibleId);
            }
            if (in_array($status, ['resolved', 'dismissed'], true) && trim((string) $resolution) === '') {
                throw ValidationException::withMessages(['resolution' => 'Describe cómo se resolvió o por qué se descarta.']);
            }

            $closing = in_array($status, ['resolved', 'dismissed'], true);
            DB::table('operational_incidents')->where('id', $incidentId)->update([
                'status' => $status,
                'responsible_id' => $responsibleId ?? $incident->responsible_id,
                'resolution' => $closing ? $resolution : $incident->resolution,
                'resolved_by' => $closing ? $actor->id : null,
                'resolved_at' => $closing ? now() : null,
                'updated_at' => now(),
            ]);

            $action = match ($status) {
                'resolved' => 'incident.resolved',
                'dismissed' => 'incident.dismissed',
                default => 'incident.updated',
            };
            $this->accounting->audit($organization, $actor, $action, 'operational_incidents', $incidentId, ['from' => $incident->status, 'to' => $status, 'responsible_id' => $responsibleId ?? $incident->responsible_id], (int) $incident->office_id);

            if ($responsibleId !== null && $responsibleId !== ($incident->responsible_id === null ? null : (int) $incident->responsible_id)) {
                $this->notifications->notify($organization->id, $responsibleId, $actor->id, 'incident.assigned', 'Incidencia asignada: '.$incident->title, $incident->description, 'operational_incidents', $incidentId);
            }
        });
    }

    /**
     * A manager without structural permissions asks the office administrators to change who is responsible for a company.
     * The request is an assignment incident, so it is tracked and audited like any other.
     */
    public function requestAssignmentChange(Organization $organization, User $actor, int $companyId, string $role, ?int $suggestedUserId, string $reason): int
    {
        $company = DB::table('companies')->where('companies.organization_id', $organization->id)->where('id', $companyId)->first(['id', 'name', 'office_id']);
        abort_if($company === null, 404);
        $suggested = $suggestedUserId === null ? null : DB::table('users')->where('id', $suggestedUserId)->value('name');

        $id = $this->create($organization, $actor, [
            'office_id' => (int) $company->office_id,
            'company_id' => (int) $company->id,
            'type' => 'assignment',
            'severity' => 'medium',
            'title' => 'Cambio de '.str_replace('_', ' ', $role).' solicitado para '.$company->name,
            'description' => trim($reason.($suggested ? ' Sugerido: '.$suggested.'.' : '')),
            'blocks_period' => false,
        ]);

        $admins = DB::table('user_role_assignments as ura')
            ->join('workspace_roles as role', 'role.id', '=', 'ura.workspace_role_id')
            ->where('ura.organization_id', $organization->id)
            ->where('role.code', 'office_admin')
            ->where('ura.status', 'active')
            ->whereNull('ura.deleted_at')
            ->where(fn ($query) => $query->whereNull('ura.office_id')->orWhere('ura.office_id', $company->office_id))
            ->pluck('ura.user_id');
        foreach ($admins as $adminId) {
            $this->notifications->notify($organization->id, (int) $adminId, $actor->id, 'assignment.change_requested', 'Solicitud de cambio de responsable: '.$company->name, $reason, 'operational_incidents', $id);
        }

        return $id;
    }

    private function assertOfficeMember(int $organizationId, int $officeId, int $userId): void
    {
        $valid = DB::table('office_user')->where('office_id', $officeId)->where('user_id', $userId)->where('status', 'active')->exists()
            && DB::table('organization_user')->where('organization_user.organization_id', $organizationId)->where('user_id', $userId)->where('status', 'active')->exists();

        if (! $valid) {
            throw ValidationException::withMessages(['responsible_id' => 'El responsable debe ser un colaborador activo de la oficina.']);
        }
    }
}
