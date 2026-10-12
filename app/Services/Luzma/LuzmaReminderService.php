<?php

declare(strict_types=1);

namespace App\Services\Luzma;

use App\Models\Organization;
use App\Models\User;
use App\Services\Payables\PayableService;
use App\Services\Receivables\ReceivableService;
use App\Services\WorkManagement\WorkNotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Luzma reminders. A reminder only reminds (a task is the work itself): it never duplicates a task, it is grouped
 * instead of sent one by one, and its condition is re-checked before delivery so a resolved matter is skipped.
 */
class LuzmaReminderService
{
    public const STATUS_LABELS = [
        'pending' => 'Pendiente', 'scheduled' => 'Programado', 'delivered' => 'Entregado', 'completed' => 'Completado', 'snoozed' => 'Pospuesto',
        'cancelled' => 'Cancelado', 'skipped' => 'Omitido (ya resuelto)', 'expired' => 'Vencido',
    ];

    public function __construct(
        private LuzmaReminderConditionService $conditions,
        private WorkNotificationService $notifications,
        private LuzmaAuditService $audit,
    ) {}

    /**
     * @param  array{kind?: string, company_id?: int|null, title: string, message?: string|null, remind_at: string, condition_type?: string|null, condition_payload?: array<string, mixed>|null, source_type?: string|null, source_id?: int|null, destination?: string|null, priority?: string, rule_key?: string|null, initiated_via?: string}  $data
     */
    public function create(Organization $organization, User $user, array $data): int
    {
        $remindAt = Carbon::parse($data['remind_at']);
        if (($data['kind'] ?? 'manual') !== 'automatic' && $remindAt->lt(now()->subMinute())) {
            throw ValidationException::withMessages(['remind_at' => 'La fecha del recordatorio ya pasó.']);
        }
        if (! empty($data['condition_type']) && (! isset(LuzmaReminderConditionService::CONDITIONS[$data['condition_type']]) || empty($data['company_id']))) {
            throw ValidationException::withMessages(['condition_type' => 'La condición necesita una empresa y un tipo válido.']);
        }
        $id = DB::table('luzma_reminders')->insertGetId([
            'public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'user_id' => $user->id, 'company_id' => $data['company_id'] ?? null,
            'kind' => $data['kind'] ?? (empty($data['condition_type']) ? 'manual' : 'conditional'), 'rule_key' => $data['rule_key'] ?? null,
            'source_type' => $data['source_type'] ?? null, 'source_id' => $data['source_id'] ?? null, 'title' => mb_substr($data['title'], 0, 180), 'message' => $data['message'] ?? null,
            'remind_at' => $remindAt, 'condition_type' => $data['condition_type'] ?? null,
            'condition_payload' => isset($data['condition_payload']) ? json_encode($data['condition_payload'], JSON_THROW_ON_ERROR) : null,
            'status' => 'scheduled', 'priority' => $data['priority'] ?? 'normal', 'destination' => $data['destination'] ?? null,
            'initiated_via' => $data['initiated_via'] ?? 'user', 'created_by' => ($data['kind'] ?? '') === 'automatic' ? null : $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        if (($data['kind'] ?? '') !== 'automatic') {
            $this->audit->record($organization, $user, 'luzma.reminder.created', 'luzma_reminders', $id, ['kind' => $data['kind'] ?? 'manual', 'condition' => $data['condition_type'] ?? null, 'initiated_via' => $data['initiated_via'] ?? 'user']);
        }

        return $id;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(Organization $organization, User $user, string $status = 'active', int $limit = 100): array
    {
        return array_values(DB::table('luzma_reminders')->leftJoin('companies', 'companies.id', '=', 'luzma_reminders.company_id')
            ->where('luzma_reminders.organization_id', $organization->id)->where('luzma_reminders.user_id', $user->id)
            ->when($status === 'active', fn ($query) => $query->whereIn('luzma_reminders.status', ['pending', 'scheduled', 'snoozed', 'delivered']))
            ->when($status === 'history', fn ($query) => $query->whereIn('luzma_reminders.status', ['completed', 'cancelled', 'skipped', 'expired']))
            ->orderByRaw("CASE luzma_reminders.status WHEN 'delivered' THEN 0 ELSE 1 END")->orderBy('luzma_reminders.remind_at')->limit($limit)
            ->get(['luzma_reminders.*', 'companies.name as company_name'])->map(fn (stdClass $row): array => $this->row($row))->all());
    }

    /** @return array<string, mixed> */
    public function row(stdClass $row): array
    {
        return [
            'id' => (string) $row->public_id, 'kind' => (string) $row->kind, 'title' => (string) $row->title, 'message' => $row->message,
            'company' => $row->company_name ?? null, 'remind_at' => Carbon::parse($row->remind_at)->toIso8601String(), 'status' => (string) $row->status,
            'status_label' => self::STATUS_LABELS[$row->status] ?? (string) $row->status, 'priority' => (string) $row->priority,
            'condition' => $row->condition_type === null ? null : (LuzmaReminderConditionService::CONDITIONS[$row->condition_type] ?? (string) $row->condition_type),
            'resolution' => $row->resolution, 'destination' => $row->destination, 'initiated_via' => (string) $row->initiated_via,
        ];
    }

    public function transition(Organization $organization, User $user, string $publicId, string $action, ?string $until = null): void
    {
        $row = DB::table('luzma_reminders')->where('luzma_reminders.organization_id', $organization->id)->where('user_id', $user->id)->where('public_id', $publicId)->first();
        abort_if($row === null, 404);
        if (in_array($row->status, ['completed', 'cancelled', 'skipped', 'expired'], true)) {
            throw ValidationException::withMessages(['reminder' => 'El recordatorio ya no está activo.']);
        }
        $changes = match ($action) {
            'snooze' => ['status' => 'snoozed', 'snoozed_until' => $this->snoozeUntil($until), 'remind_at' => $this->snoozeUntil($until), 'delivered_at' => null],
            'complete' => ['status' => 'completed', 'resolved_at' => now(), 'resolution' => 'Atendido por el usuario.'],
            'cancel' => ['status' => 'cancelled', 'resolved_at' => now(), 'resolution' => 'Cancelado por el usuario.'],
            default => abort(404),
        };
        DB::table('luzma_reminders')->where('id', $row->id)->update([...$changes, 'updated_at' => now()]);
        $this->audit->record($organization, $user, 'luzma.reminder.'.$action, 'luzma_reminders', (int) $row->id, []);
    }

    /**
     * Automatic rules of the tenant (idempotent through rule_key): fiscal obligations escalate as the due date gets
     * closer; payables and receivables due soon become one grouped reminder per person and company per day.
     *
     * @return int reminders created
     */
    public function syncAutomatic(Organization $organization): int
    {
        $created = 0;
        $today = now()->toDateString();
        foreach (DB::table('fiscal_obligations')->join('companies', 'companies.id', '=', 'fiscal_obligations.company_id')->where('fiscal_obligations.organization_id', $organization->id)
            ->whereIn('fiscal_obligations.status', LuzmaReminderConditionService::FISCAL_OPEN)->whereNotNull('fiscal_obligations.assigned_to')->where('fiscal_obligations.due_on', '<=', now()->addDays(3)->toDateString())
            ->get(['fiscal_obligations.id', 'fiscal_obligations.company_id', 'fiscal_obligations.assigned_to', 'fiscal_obligations.name', 'fiscal_obligations.reference_period', 'fiscal_obligations.due_on', 'companies.name as company']) as $obligation) {
            $days = (int) now()->startOfDay()->diffInDays(Carbon::parse($obligation->due_on)->startOfDay(), false);
            [$stage, $priority, $label] = match (true) {
                $days < 0 => ['overdue', 'high', 'vencida'],
                $days <= 1 => ['1d', 'warning', $days === 0 ? 'vence hoy' : 'vence mañana'],
                default => ['3d', 'info', 'vence en '.$days.' días'],
            };
            $created += $this->ensureAutomatic($organization, (int) $obligation->assigned_to, 'fiscal:'.$obligation->id.':'.$stage, [
                'company_id' => (int) $obligation->company_id, 'title' => $obligation->name.' '.$obligation->reference_period.' '.$label, 'message' => $obligation->company.' · fecha límite '.$obligation->due_on.'.',
                'priority' => $priority, 'condition_type' => 'source_open', 'source_type' => 'fiscal_obligations', 'source_id' => (int) $obligation->id, 'destination' => '/tax/obligations/'.$obligation->id,
            ]);
        }

        foreach ([['audit_evidence_requests', 'requested_from_user_id', ['requested', 'more_information_required', 'rejected'], 'Evidencia de auditoría', '/work/audit-requests?evidence='],
            ['audit_findings', 'responsible_user_id', ['open', 'response_requested', 'remediation_in_progress'], 'Hallazgo de auditoría', '/work/audit-requests?finding=']] as [$table, $column, $open, $label, $destination]) {
            foreach (DB::table($table)->where($table.'.organization_id', $organization->id)->whereIn('status', $open)->whereNotNull('due_date')->where('due_date', '<=', now()->addDays(3)->toDateString())
                ->get(['id', 'company_id', $column, 'code', 'title', 'due_date']) as $row) {
                $days = (int) now()->startOfDay()->diffInDays(Carbon::parse($row->due_date)->startOfDay(), false);
                [$stage, $priority, $when] = match (true) {
                    $days < 0 => ['overdue', 'high', 'vencido'],
                    $days <= 1 => ['1d', 'warning', $days === 0 ? 'vence hoy' : 'vence mañana'],
                    default => ['3d', 'info', 'vence en '.$days.' días'],
                };
                $created += $this->ensureAutomatic($organization, (int) $row->{$column}, $table.':'.$row->id.':'.$stage, [
                    'company_id' => (int) $row->company_id, 'title' => $label.' '.$row->code.' '.$when, 'message' => $row->title.' · fecha límite '.$row->due_date.'.',
                    'priority' => $priority, 'condition_type' => 'source_open', 'source_type' => $table, 'source_id' => (int) $row->id, 'destination' => $destination.$row->id,
                ]);
            }
        }

        foreach ([['client_requests', ['pending', 'in_progress', 'more_information_required'], 'Solicitud', '/client/document-requests?request='],
            ['client_approval_requests', ['pending', 'viewed'], 'Autorización', '/client/approvals?approval=']] as [$table, $open, $label, $destination]) {
            foreach (DB::table($table)->where($table.'.organization_id', $organization->id)->whereIn('status', $open)->whereNotNull('due_date')->where('due_date', '<=', now()->addDays(3)->toDateString())
                ->get(['id', 'company_id', 'requested_from_user_id', 'code', 'title', 'due_date']) as $row) {
                $days = (int) now()->startOfDay()->diffInDays(Carbon::parse($row->due_date)->startOfDay(), false);
                [$stage, $priority, $when] = match (true) {
                    $days < 0 => ['overdue', 'high', 'vencida'],
                    $days <= 1 => ['1d', 'warning', $days === 0 ? 'vence hoy' : 'vence mañana'],
                    default => ['3d', 'info', 'vence en '.$days.' días'],
                };
                $recipients = $row->requested_from_user_id !== null ? [(int) $row->requested_from_user_id]
                    : DB::table('client_company_access')->where('company_id', $row->company_id)->where('status', 'active')->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
                foreach ($recipients as $recipient) {
                    $created += $this->ensureAutomatic($organization, $recipient, $table.':'.$row->id.':'.$recipient.':'.$stage, [
                        'company_id' => (int) $row->company_id, 'title' => $label.' '.$row->code.' '.$when, 'message' => $row->title.' · fecha límite '.$row->due_date.'.',
                        'priority' => $priority, 'condition_type' => 'source_open', 'source_type' => $table, 'source_id' => (int) $row->id, 'destination' => $destination.$row->id,
                    ]);
                }
            }
        }

        $assignees = fn (string $role) => DB::table('company_assignments')->join('companies', 'companies.id', '=', 'company_assignments.company_id')->where('company_assignments.organization_id', $organization->id)
            ->where('assignment_role', $role)->where('company_assignments.status', 'active')->whereNull('company_assignments.deleted_at')->get(['company_assignments.company_id', 'company_assignments.user_id', 'companies.name']);
        foreach ($assignees('accounts_payable') as $assignment) {
            $due = array_values(array_filter(app(PayableService::class)->documents([(int) $assignment->company_id]), fn (array $row): bool => ! $row['overdue'] && $row['days_to_due'] <= 7 && ! $row['in_proposal'] && $row['committed_cents'] === 0));
            if ($due !== []) {
                $created += $this->ensureAutomatic($organization, (int) $assignment->user_id, 'payables_due:'.$assignment->company_id.':'.$today, [
                    'company_id' => (int) $assignment->company_id, 'title' => count($due).' obligación(es) de '.$assignment->name.' vencen esta semana',
                    'message' => 'Q '.number_format(array_sum(array_column($due, 'outstanding_cents')) / 100, 2).' aún sin propuesta de pago.', 'priority' => 'warning',
                    'condition_type' => 'source_open', 'source_type' => 'purchase_documents', 'condition_payload' => ['document_ids' => array_column($due, 'id'), 'company_ids' => [(int) $assignment->company_id]], 'destination' => '/payables/due-dates?days=7',
                ]);
            }
        }
        foreach ($assignees('accounts_receivable') as $assignment) {
            $due = array_values(array_filter(app(ReceivableService::class)->documents([(int) $assignment->company_id]), fn (array $row): bool => ! $row['overdue'] && $row['days_to_due'] <= 3));
            if ($due !== []) {
                $created += $this->ensureAutomatic($organization, (int) $assignment->user_id, 'receivables_due:'.$assignment->company_id.':'.$today, [
                    'company_id' => (int) $assignment->company_id, 'title' => count($due).' factura(s) de '.$assignment->name.' vencen en 3 días',
                    'message' => 'Buen momento para una gestión preventiva de cobro.', 'priority' => 'info', 'condition_type' => 'source_open', 'source_type' => 'sales_invoices',
                    'condition_payload' => ['invoice_ids' => array_column($due, 'id'), 'company_ids' => [(int) $assignment->company_id]], 'destination' => '/receivables/due-dates',
                ]);
            }
        }

        return $created;
    }

    /**
     * Delivers due reminders after re-checking their condition; resolved ones are skipped, never sent.
     *
     * @return array{delivered: int, skipped: int}
     */
    public function dispatchDue(Organization $organization): array
    {
        $delivered = 0;
        $skipped = 0;
        $due = DB::table('luzma_reminders')->where('luzma_reminders.organization_id', $organization->id)->whereIn('status', ['scheduled', 'snoozed', 'pending'])->where('remind_at', '<=', now())->orderBy('remind_at')->limit(500)->get();
        foreach ($due as $reminder) {
            $check = $this->conditions->evaluate($reminder);
            if (! $check['applies']) {
                DB::table('luzma_reminders')->where('id', $reminder->id)->update(['status' => 'skipped', 'resolved_at' => now(), 'resolution' => $check['detail'], 'updated_at' => now()]);
                $skipped++;

                continue;
            }
            $body = trim(($reminder->message ?? '').' '.$check['detail']);
            $this->notifications->notify($organization->id, (int) $reminder->user_id, null, 'luzma.reminder', 'Recordatorio de Luzma: '.$reminder->title, $body === '' ? null : $body, 'luzma_reminders', (int) $reminder->id);
            DB::table('luzma_reminder_deliveries')->insert(['organization_id' => $organization->id, 'luzma_reminder_id' => $reminder->id, 'channel' => 'in_app', 'status' => 'delivered', 'delivered_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('luzma_reminders')->where('id', $reminder->id)->update(['status' => 'delivered', 'delivered_at' => now(), 'updated_at' => now()]);
            DB::table('audit_logs')->insert(['organization_id' => $organization->id, 'user_id' => null, 'action' => 'luzma.reminder.delivered', 'auditable_type' => 'luzma_reminders', 'auditable_id' => $reminder->id, 'new_values' => json_encode(['kind' => $reminder->kind], JSON_THROW_ON_ERROR), 'occurred_at' => now()]);
            $delivered++;
        }
        // Delivered automatic reminders whose source got resolved close themselves; stale ones expire after a week.
        foreach (DB::table('luzma_reminders')->where('luzma_reminders.organization_id', $organization->id)->where('status', 'delivered')->where('kind', 'automatic')->get() as $reminder) {
            if (! $this->conditions->evaluate($reminder)['applies']) {
                DB::table('luzma_reminders')->where('id', $reminder->id)->update(['status' => 'completed', 'resolved_at' => now(), 'resolution' => 'El origen se resolvió.', 'updated_at' => now()]);
            }
        }
        DB::table('luzma_reminders')->where('luzma_reminders.organization_id', $organization->id)->where('status', 'delivered')->where('kind', 'automatic')->where('delivered_at', '<', now()->subDays(7))
            ->update(['status' => 'expired', 'updated_at' => now()]);

        return ['delivered' => $delivered, 'skipped' => $skipped];
    }

    /** @param array<string, mixed> $data */
    private function ensureAutomatic(Organization $organization, int $userId, string $ruleKey, array $data): int
    {
        if (DB::table('luzma_reminders')->where('luzma_reminders.organization_id', $organization->id)->where('user_id', $userId)->where('rule_key', $ruleKey)->exists()) {
            return 0;
        }
        $user = User::find($userId);
        if ($user === null) {
            return 0;
        }
        /** @var array{kind?: string, company_id?: int|null, title: string, message?: string|null, remind_at: string, condition_type?: string|null, condition_payload?: array<string, mixed>|null, source_type?: string|null, source_id?: int|null, destination?: string|null, priority?: string, rule_key?: string|null, initiated_via?: string} $reminder */
        $reminder = [...$data, 'kind' => 'automatic', 'rule_key' => $ruleKey, 'remind_at' => now()->toIso8601String(), 'initiated_via' => 'luzma'];
        $this->create($organization, $user, $reminder);

        return 1;
    }

    private function snoozeUntil(?string $until): Carbon
    {
        if ($until === null || $until === '1h') {
            return Carbon::now()->addHour();
        }
        if ($until === 'tomorrow') {
            return Carbon::now()->addDay()->setTime(8, 0);
        }
        $date = Carbon::parse($until);
        if ($date->lte(Carbon::now())) {
            throw ValidationException::withMessages(['until' => 'Elige una fecha futura para posponer.']);
        }

        return $date;
    }
}
