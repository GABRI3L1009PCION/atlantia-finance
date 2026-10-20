<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Services\WorkManagement\TaskWaitService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;
use Throwable;

/**
 * Everything the workspace of one fiscal obligation shows: its state, the checklist with a backend-computed progress,
 * the calculation read from the real books, observations and the immutable history. The screen draws it; it does not
 * decide anything.
 */
class FiscalObligationWorkspaceService
{
    private const TIMELINE = [
        'fiscal.obligation.generated' => 'Obligación generada',
        'fiscal.obligation.started' => 'Iniciada',
        'fiscal.check.completed' => 'Requisito completado',
        'fiscal.check.reopened' => 'Requisito reabierto',
        'fiscal.obligation.submitted' => 'Enviada a revisión',
        'fiscal.obligation.changes_requested' => 'Cambios solicitados',
        'fiscal.obligation.recalculated' => 'Devuelta a preparación para recalcular',
        'fiscal.obligation.approved' => 'Aprobada',
        'fiscal.obligation.completed' => 'Presentación registrada',
        'fiscal.evidence.attached' => 'Constancia adjunta',
    ];

    public function __construct(
        private FiscalObligationChecklistService $checklist,
        private VatBookService $vatBooks,
        private TaskWaitService $waits,
    ) {}

    /** @return array<string, mixed> */
    public function payload(stdClass $obligation): array
    {
        $row = DB::table('fiscal_obligations')->join('companies', 'companies.id', '=', 'fiscal_obligations.company_id')
            ->leftJoin('users as preparer', 'preparer.id', '=', 'fiscal_obligations.assigned_to')->leftJoin('users as reviewer', 'reviewer.id', '=', 'fiscal_obligations.reviewer_id')
            ->where('fiscal_obligations.id', $obligation->id)->first(['fiscal_obligations.*', 'companies.name as company_name', 'companies.public_id as company_public_id', 'preparer.name as preparer_name', 'reviewer.name as reviewer_name']);
        abort_if($row === null, 404);
        $today = now()->startOfDay();
        $due = Carbon::parse((string) $row->due_on)->startOfDay();
        $open = ! in_array($row->status, ['completed', 'cancelled', 'not_applicable'], true);
        $summary = $this->checklist->summary($row);
        $task = DB::table('work_tasks')->where('work_tasks.organization_id', $row->organization_id)->where('automation_key', 'fiscal_obligation:'.$row->id)->first(['id', 'status']);

        return [
            'obligation' => [
                'id' => (int) $row->id, 'name' => (string) $row->name, 'period' => (string) $row->reference_period, 'type' => (string) $row->obligation_type,
                'status' => (string) $row->status, 'status_label' => FiscalObligationWorkflowService::STATUS_LABELS[$row->status] ?? (string) $row->status,
                'company' => ['id' => (int) $row->company_id, 'name' => (string) $row->company_name, 'public_id' => (string) $row->company_public_id],
                'due_on' => (string) $row->due_on, 'internal_due_on' => (string) $row->internal_due_on,
                'days_overdue' => $open && $due->lt($today) ? (int) $due->diffInDays($today) : 0, 'days_to_due' => $due->gte($today) ? (int) $today->diffInDays($due) : 0,
                'preparer' => $row->preparer_name === null ? null : (string) $row->preparer_name, 'reviewer' => $row->reviewer_name === null ? null : (string) $row->reviewer_name,
                'started_at' => $row->started_at === null ? null : (string) $row->started_at, 'submitted_at' => $row->submitted_at === null ? null : (string) $row->submitted_at,
                'approved_at' => $row->approved_at === null ? null : (string) $row->approved_at,
                'last_observation' => $row->last_observation === null ? null : (string) $row->last_observation,
                'filing' => $row->status === 'completed' ? ['reference' => (string) $row->filing_reference, 'filed_on' => (string) $row->filed_on, 'evidence_name' => $row->evidence_name === null ? null : (string) $row->evidence_name] : null,
            ],
            'task' => $task === null ? null : ['id' => (int) $task->id, 'status' => (string) $task->status],
            'waits' => $task === null ? [] : $this->waits->waitsOf((int) $task->id),
            'readiness' => $summary,
            'calculation' => $row->obligation_type === 'iva' ? $this->calculation($row) : null,
            'observations' => $this->observations((int) $row->organization_id, (int) $row->id, (string) $row->company_public_id),
            'timeline' => $this->timeline((int) $row->organization_id, (int) $row->id),
        ];
    }

    /** @return array{sales_vat_cents: int, purchase_vat_cents: int, previous_credit_cents: int, payable_cents: int, carry_forward_cents: int, sales: int, purchases: int, book_status: string}|null */
    private function calculation(stdClass $row): ?array
    {
        try {
            $book = $this->vatBooks->book((int) $row->company_id, (int) $row->reference_year, (int) $row->reference_month);

            return [...$book['settlement'], 'sales' => count($book['sales']), 'purchases' => count($book['purchases']), 'book_status' => (string) $book['status']];
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<array<string, mixed>> */
    private function observations(int $organizationId, int $obligationId, string $companyPublicId): array
    {
        $labels = ['open' => 'Abierta', 'reopened' => 'Reabierta', 'in_progress' => 'En corrección', 'ready_for_review' => 'Corregida, por validar', 'resolved' => 'Resuelta', 'cancelled' => 'Cancelada'];

        return array_values(DB::table('review_observations')->leftJoin('users as author', 'author.id', '=', 'review_observations.created_by')->where('review_observations.organization_id', $organizationId)
            ->where(fn ($query) => $query->where(fn ($own) => $own->where('related_type', 'fiscal_obligations')->where('related_id', $obligationId))->orWhere(fn ($own) => $own->where('origin_type', 'fiscal_obligations')->where('origin_id', $obligationId)))
            ->orderByDesc('review_observations.id')->limit(50)
            ->get(['review_observations.id', 'review_observations.message', 'review_observations.severity', 'review_observations.status', 'review_observations.resolution', 'review_observations.related_type', 'review_observations.related_id', 'review_observations.related_label', 'review_observations.created_at', 'review_observations.resolved_at', 'author.name as author'])
            ->map(fn (stdClass $o): array => ['id' => (int) $o->id, 'code' => 'OBS-'.str_pad((string) $o->id, 4, '0', STR_PAD_LEFT), 'message' => (string) $o->message, 'severity' => (string) $o->severity,
                'status' => (string) $o->status, 'status_label' => $labels[$o->status] ?? (string) $o->status, 'resolution' => $o->resolution === null ? null : (string) $o->resolution,
                'related_label' => $o->related_type === 'fiscal_obligations' || $o->related_label === null ? null : (string) $o->related_label, 'destination' => $this->destination($companyPublicId, (string) $o->related_type, $o->related_id === null ? null : (int) $o->related_id), 'author' => $o->author === null ? null : (string) $o->author, 'created_at' => (string) $o->created_at, 'resolved_at' => $o->resolved_at === null ? null : (string) $o->resolved_at])->all());
    }

    /** Where the record an observation points to is corrected: its own screen, never a copy inside the task. */
    private function destination(string $companyPublicId, string $type, ?int $id): ?string
    {
        $publicId = $id === null ? null : match ($type) {
            'purchase_documents', 'sales_invoices', 'journal_entries' => DB::table($type)->where('id', $id)->value('public_id'),
            default => null,
        };
        if ($publicId === null) {
            return null;
        }

        return match ($type) {
            'purchase_documents' => '/accounting/companies/'.$companyPublicId.'/purchases/documents/'.$publicId,
            'sales_invoices' => '/accounting/companies/'.$companyPublicId.'/sales/invoices/'.$publicId,
            default => '/accounting/companies/'.$companyPublicId.'#libro-diario',
        };
    }

    /** @return list<array{at: string, label: string, actor: string, detail: string|null}> */
    private function timeline(int $organizationId, int $obligationId): array
    {
        return array_values(DB::table('audit_logs')->leftJoin('users as actor', 'actor.id', '=', 'audit_logs.user_id')->where('audit_logs.organization_id', $organizationId)
            ->where('audit_logs.auditable_type', 'fiscal_obligations')->where('audit_logs.auditable_id', $obligationId)->whereIn('audit_logs.action', array_keys(self::TIMELINE))
            ->orderBy('audit_logs.id')->get(['audit_logs.action', 'audit_logs.new_values', 'audit_logs.occurred_at', 'actor.name as actor_name'])
            ->map(function (stdClass $event): array {
                $values = json_decode((string) $event->new_values, true);
                $values = is_array($values) ? $values : [];
                $detail = match ($event->action) {
                    'fiscal.check.completed', 'fiscal.check.reopened' => isset($values['check']) ? (string) $values['check'] : null,
                    'fiscal.obligation.changes_requested' => isset($values['message']) ? (string) $values['message'] : null,
                    'fiscal.obligation.completed' => isset($values['reference']) ? 'Referencia '.$values['reference'] : null,
                    'fiscal.obligation.approved' => ($values['self_reviewed'] ?? false) ? 'Aprobada por quien la preparó ('.($values['review_policy'] ?? 'política de oficina pequeña').')' : (isset($values['comment']) ? (string) $values['comment'] : null),
                    default => null,
                };

                return ['at' => (string) $event->occurred_at, 'label' => self::TIMELINE[$event->action], 'actor' => $event->actor_name === null ? 'Sistema' : (string) $event->actor_name, 'detail' => $detail];
            })->all());
    }
}
