<?php

declare(strict_types=1);

namespace App\Services\ClientPortal;

use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use stdClass;
use Throwable;

/**
 * Reads of the client portal for one company: what the office needs from the client, what was delivered, approvals,
 * visible obligations (without amounts that are not final), published reports, messages, calendar and history. Only
 * items addressed to the client are shown: internal tasks, notes and workloads of the office never are.
 */
class ClientPortalService
{
    /** Internal fiscal statuses translated for the client. */
    public const OBLIGATION_LABELS = [
        'pending' => 'En preparación', 'in_progress' => 'En preparación', 'ready_for_review' => 'En revisión', 'changes_requested' => 'En revisión', 'approved' => 'En revisión', 'completed' => 'Completada',
    ];

    public function __construct(
        private ClientAccessService $access,
        private ClientRequestService $requests,
        private ClientDocumentService $documents,
        private PublishedReportService $reports,
        private ClientMessageService $messages,
    ) {}

    /**
     * Everything that requires an action from the client in the company.
     *
     * @return list<array{id: string, type: string, type_label: string, title: string, description: string|null, source_label: string, due_date: string|null, overdue: bool, status: string, status_label: string, action_label: string, destination: string}>
     */
    public function pending(Organization $organization, User $user, int $companyId): array
    {
        $items = [];
        $can = fn (string $permission): bool => $this->access->can($organization, $user, $companyId, $permission);
        foreach ($this->visibleRequests($organization, $user, $companyId)->whereIn('status', ClientRequestService::OPEN) as $row) {
            $items[] = ['id' => 'request-'.$row->id, 'type' => $row->type === 'document' ? 'document_request' : $row->type.'_request', 'type_label' => $row->type === 'document' ? 'Documento' : (ClientRequestService::TYPES[$row->type] ?? 'Solicitud'),
                'title' => (string) $row->title, 'description' => $row->status === 'more_information_required' ? ($row->review_note ?: $row->description) : $row->description,
                'source_label' => $this->origin((string) $row->type), 'due_date' => $row->due_date, 'overdue' => $row->due_date !== null && (string) $row->due_date < now()->toDateString(),
                'status' => (string) $row->status, 'status_label' => ClientRequestService::STATUS_LABELS[$row->status], 'action_label' => $row->type === 'document' ? 'Adjuntar' : 'Responder',
                'destination' => '/client/document-requests?request='.$row->id];
        }
        if ($can('client.approvals.view')) {
            foreach ($this->approvalsQuery($user, $companyId)->whereIn('status', ['pending', 'viewed'])->get() as $row) {
                $items[] = ['id' => 'approval-'.$row->id, 'type' => 'approval_request', 'type_label' => 'Autorización', 'title' => (string) $row->title, 'description' => $row->description,
                    'source_label' => match ($row->type) {
                        'payment_proposal' => 'Tesorería', 'payroll_confirmation' => 'Nómina', 'fiscal_confirmation' => 'Fiscal', default => 'Contabilidad'
                    },
                    'due_date' => $row->due_date, 'overdue' => $row->due_date !== null && (string) $row->due_date < now()->toDateString(), 'status' => (string) $row->status,
                    'status_label' => ClientRequestService::APPROVAL_LABELS[$row->status], 'action_label' => 'Revisar', 'destination' => '/client/approvals?approval='.$row->id];
            }
        }
        foreach (DB::table('audit_evidence_requests')->where('company_id', $companyId)->where('requested_from_user_id', $user->id)->whereIn('status', ['requested', 'more_information_required', 'rejected'])->get() as $row) {
            $items[] = ['id' => 'evidence-'.$row->id, 'type' => 'audit_evidence_request', 'type_label' => 'Evidencia', 'title' => (string) $row->title, 'description' => $row->status === 'requested' ? (string) $row->description : ($row->review_note ?: (string) $row->description),
                'source_label' => 'Auditoría', 'due_date' => (string) $row->due_date, 'overdue' => (string) $row->due_date < now()->toDateString(), 'status' => $row->status === 'requested' ? 'pending' : 'more_information_required',
                'status_label' => $row->status === 'requested' ? 'Pendiente' : 'Requiere información', 'action_label' => 'Adjuntar', 'destination' => '/client/document-requests?evidence='.$row->id];
        }
        usort($items, fn (array $a, array $b): int => [$a['due_date'] === null, (string) $a['due_date']] <=> [$b['due_date'] === null, (string) $b['due_date']]);

        return $items;
    }

    /** @return array<string, mixed> */
    public function dashboard(Organization $organization, User $user, int $companyId): array
    {
        $pending = $this->pending($organization, $user, $companyId);
        $can = fn (string $permission): bool => $this->access->can($organization, $user, $companyId, $permission);
        $obligations = $can('client.obligations.view') ? $this->obligations($companyId) : null;

        return [
            'metrics' => [
                'pending_items' => count($pending),
                'requested_documents' => count(array_filter($pending, fn (array $item): bool => in_array($item['type'], ['document_request', 'audit_evidence_request'], true))),
                'pending_approvals' => count(array_filter($pending, fn (array $item): bool => $item['type'] === 'approval_request')),
                'upcoming_obligations' => $obligations === null || $obligations['error'] ? null : count(array_filter($obligations['rows'], fn (array $row): bool => $row['status'] !== 'completed')),
                'documents_submitted_this_month' => DB::table('client_documents')->where('company_id', $companyId)->where('uploaded_by', $user->id)->where('created_at', '>=', now()->startOfMonth())->count(),
                'unread_messages' => $can('client.messages.view') ? $this->messages->unreadForClient($companyId) : null,
            ],
            'attention_items' => array_slice($pending, 0, 6),
            'upcoming_dates' => array_slice(array_values(array_filter($this->calendar($organization, $user, $companyId, now()->subDays(3), now()->addDays(45)), fn (array $e): bool => $e['date'] >= now()->toDateString())), 0, 5),
            'recent_documents' => $can('client.documents.view') ? array_slice($this->documentList($companyId), 0, 5) : [],
            'published_reports' => $can('client.reports.view') ? array_slice($this->reports->forClient($organization, $user, $companyId), 0, 5) : [],
            'obligations' => $obligations === null ? null : ['error' => $obligations['error'], 'rows' => array_slice($obligations['rows'], 0, 5)],
            'recent_messages' => $can('client.messages.view') ? array_slice($this->messages->conversations($companyId, true, -1), 0, 4) : [],
            'last_updated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Visible fiscal obligations: dates and a simplified status, never an amount that is not final.
     *
     * @return array{error: bool, rows: list<array<string, mixed>>}
     */
    public function obligations(int $companyId): array
    {
        try {
            $open = DB::table('client_requests')->where('company_id', $companyId)->where('source_type', 'fiscal_obligations')->whereIn('status', ClientRequestService::OPEN)
                ->groupBy('source_id')->selectRaw('source_id, CAST(COUNT(*) AS BIGINT) as total')->pluck('total', 'source_id');
            $rows = DB::table('fiscal_obligations')->where('company_id', $companyId)->whereNotIn('status', ['cancelled', 'not_applicable'])->where('due_on', '>=', now()->subMonths(3)->toDateString())
                ->orderBy('due_on')->limit(60)->get(['id', 'name', 'reference_period', 'due_on', 'status', 'completed_at', 'filed_on']);

            return ['error' => false, 'rows' => array_values($rows->map(function (stdClass $row) use ($open): array {
                $missing = (int) ($open[$row->id] ?? 0);
                $status = $row->status === 'completed' ? 'completed' : ($missing > 0 ? 'information_pending' : (string) $row->status);

                return ['id' => (int) $row->id, 'name' => (string) $row->name, 'period' => (string) $row->reference_period, 'due_date' => (string) $row->due_on, 'status' => $status === 'information_pending' ? 'information_pending' : ($row->status === 'completed' ? 'completed' : 'in_progress'),
                    'status_label' => $missing > 0 ? 'Pendiente de información' : (self::OBLIGATION_LABELS[$row->status] ?? 'En preparación'), 'documentation' => $missing > 0 ? 'Faltan '.$missing : 'Completa',
                    'missing' => $missing, 'filed_on' => $row->filed_on, 'note' => $row->status === 'completed' ? null : 'La obligación está en preparación; los montos se informan cuando estén aprobados.'];
            })->all())];
        } catch (Throwable $exception) {
            Log::warning('Client obligations failed', ['company_id' => $companyId, 'error' => $exception->getMessage()]);

            return ['error' => true, 'rows' => []];
        }
    }

    /** @return list<array<string, mixed>> */
    public function documentList(int $companyId): array
    {
        return array_values(DB::table('client_documents')->where('company_id', $companyId)->where('status', '!=', 'archived')->orderByDesc('id')->limit(300)->get()->map(fn (stdClass $row): array => $this->documents->row($row))->all());
    }

    /** @return list<array<string, mixed>> */
    public function requestList(Organization $organization, User $user, int $companyId): array
    {
        return array_values($this->visibleRequests($organization, $user, $companyId)->map(fn (stdClass $row): array => [...$this->requests->requestRow($row), 'origin' => $this->origin((string) $row->type)])->all());
    }

    /** @return list<array<string, mixed>> */
    public function evidenceRequests(User $user, int $companyId): array
    {
        return array_values(DB::table('audit_evidence_requests')->where('company_id', $companyId)->where('requested_from_user_id', $user->id)->whereNotIn('status', ['cancelled'])->orderByDesc('id')->get()
            ->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'code' => (string) $row->code, 'title' => (string) $row->title, 'description' => (string) $row->description, 'due_date' => (string) $row->due_date,
                'status' => (string) $row->status, 'status_label' => ['requested' => 'Pendiente', 'submitted' => 'Entregado', 'under_review' => 'En revisión', 'accepted' => 'Aceptado', 'rejected' => 'Requiere corrección', 'more_information_required' => 'Requiere información'][$row->status] ?? (string) $row->status,
                'review_note' => in_array($row->status, ['rejected', 'more_information_required'], true) ? $row->review_note : null, 'open' => in_array($row->status, ['requested', 'more_information_required', 'rejected'], true)])->all());
    }

    /** @return list<array<string, mixed>> */
    public function approvalList(User $user, int $companyId): array
    {
        return array_values($this->approvalsQuery($user, $companyId)->orderByDesc('id')->get()->map(fn (stdClass $row): array => $this->requests->approvalRow($row))->all());
    }

    /**
     * Electronic invoices of the company (only certified or cancelled ones).
     *
     * @return list<array<string, mixed>>
     */
    public function fel(int $companyId, bool $canDownload): array
    {
        return array_values(DB::table('fel_documents')->where('company_id', $companyId)->whereIn('status', ['certified', 'cancelled'])->orderByDesc('issued_on')->limit(300)
            ->get(['id', 'issued_on', 'document_type', 'series', 'number', 'fel_series', 'fel_number', 'authorization_number', 'currency', 'total_cents', 'status', 'receiver_snapshot', 'pdf_path', 'cancelled_at', 'cancellation_reason'])
            ->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'issued_on' => (string) $row->issued_on, 'type' => (string) $row->document_type, 'number' => trim(($row->fel_series ?? $row->series).'-'.($row->fel_number ?? $row->number), '-'),
                'authorization' => $row->authorization_number, 'receiver' => (string) ((json_decode((string) $row->receiver_snapshot, true)['name'] ?? '') ?: '—'), 'currency' => (string) $row->currency, 'total_cents' => (int) $row->total_cents,
                'status' => (string) $row->status, 'status_label' => $row->status === 'certified' ? 'Certificado' : 'Anulado', 'cancellation_reason' => $row->cancellation_reason,
                'download' => $canDownload && $row->pdf_path !== null ? '/client/fel/'.$row->id.'/pdf' : null])->all());
    }

    /**
     * Dates relevant to the client only.
     *
     * @return list<array{date: string, type: string, title: string, subtitle: string, priority: string, destination: string}>
     */
    public function calendar(Organization $organization, User $user, int $companyId, CarbonInterface $from, CarbonInterface $to): array
    {
        $range = [$from->toDateString(), $to->toDateString()];
        $events = [];
        foreach ($this->pending($organization, $user, $companyId) as $item) {
            if ($item['due_date'] !== null && $item['due_date'] >= $range[0] && $item['due_date'] <= $range[1]) {
                $events[] = ['date' => (string) $item['due_date'], 'type' => $item['type'] === 'approval_request' ? 'review' : 'task', 'title' => $item['title'], 'subtitle' => $item['source_label'].' · '.$item['type_label'], 'priority' => $item['overdue'] ? 'critical' : 'high', 'destination' => $item['destination']];
            }
        }
        if ($this->access->can($organization, $user, $companyId, 'client.obligations.view')) {
            foreach ($this->obligations($companyId)['rows'] as $row) {
                if ($row['due_date'] >= $range[0] && $row['due_date'] <= $range[1]) {
                    $events[] = ['date' => $row['due_date'], 'type' => 'statutory', 'title' => $row['name'], 'subtitle' => 'Fiscal · '.$row['period'].' · '.$row['status_label'], 'priority' => 'normal', 'destination' => '/client/obligations'];
                }
            }
        }
        foreach ($this->access->can($organization, $user, $companyId, 'client.reports.view') ? $this->reports->forClient($organization, $user, $companyId) : [] as $report) {
            $date = substr((string) $report['published_at'], 0, 10);
            if ($date >= $range[0] && $date <= $range[1]) {
                $events[] = ['date' => $date, 'type' => 'internal', 'title' => 'Reporte publicado: '.$report['title'], 'subtitle' => $report['period'], 'priority' => 'normal', 'destination' => '/client/reports'];
            }
        }
        usort($events, fn (array $a, array $b): int => strcmp($a['date'], $b['date']));

        return $events;
    }

    /**
     * Client history built from the client's own processes (never the internal audit log).
     *
     * @return list<array{at: string, kind: string, title: string, detail: string|null}>
     */
    public function history(Organization $organization, User $user, int $companyId): array
    {
        $events = [];
        foreach (DB::table('client_documents')->where('company_id', $companyId)->orderByDesc('id')->limit(100)->get() as $doc) {
            $events[] = ['at' => (string) $doc->created_at, 'kind' => 'document', 'title' => 'Documento enviado: '.$doc->title, 'detail' => ClientDocumentService::CATEGORIES[$doc->category] ?? null];
            if ($doc->reviewed_at !== null && in_array($doc->status, ['accepted', 'processed', 'rejected', 'more_information_required'], true)) {
                $events[] = ['at' => (string) $doc->reviewed_at, 'kind' => 'document', 'title' => 'Documento '.mb_strtolower(ClientDocumentService::CLIENT_LABELS[$doc->status]).': '.$doc->title, 'detail' => $doc->review_note];
            }
        }
        foreach ($this->visibleRequests($organization, $user, $companyId) as $request) {
            $events[] = ['at' => (string) $request->created_at, 'kind' => 'request', 'title' => 'Te solicitaron: '.$request->title, 'detail' => $request->due_date === null ? null : 'Fecha límite '.Carbon::parse($request->due_date)->format('d/m/Y')];
            if ($request->responded_at !== null) {
                $events[] = ['at' => (string) $request->responded_at, 'kind' => 'request', 'title' => 'Respondiste: '.$request->title, 'detail' => null];
            }
            if ($request->status === 'more_information_required' && $request->reviewed_at !== null) {
                $events[] = ['at' => (string) $request->reviewed_at, 'kind' => 'request', 'title' => 'Información adicional solicitada: '.$request->title, 'detail' => $request->review_note];
            }
            if ($request->completed_at !== null) {
                $events[] = ['at' => (string) $request->completed_at, 'kind' => 'request', 'title' => 'Solicitud completada: '.$request->title, 'detail' => null];
            }
        }
        foreach ($this->approvalsQuery($user, $companyId)->whereNotNull('responded_at')->get() as $approval) {
            $events[] = ['at' => (string) $approval->responded_at, 'kind' => 'approval', 'title' => 'Autorización '.mb_strtolower(ClientRequestService::APPROVAL_LABELS[$approval->status] ?? $approval->status).': '.$approval->title, 'detail' => $approval->response];
        }
        foreach ($this->access->can($organization, $user, $companyId, 'client.reports.view') ? $this->reports->forClient($organization, $user, $companyId) : [] as $report) {
            $events[] = ['at' => (string) $report['published_at'], 'kind' => 'report', 'title' => 'Reporte publicado: '.$report['title'].' v'.$report['version'], 'detail' => $report['period']];
        }
        if ($this->access->can($organization, $user, $companyId, 'client.obligations.view')) {
            foreach (DB::table('fiscal_obligations')->where('company_id', $companyId)->where('status', 'completed')->whereNotNull('completed_at')->orderByDesc('completed_at')->limit(30)->get(['name', 'reference_period', 'completed_at']) as $obligation) {
                $events[] = ['at' => (string) $obligation->completed_at, 'kind' => 'obligation', 'title' => 'Obligación completada: '.$obligation->name, 'detail' => (string) $obligation->reference_period];
            }
        }
        if ($this->access->can($organization, $user, $companyId, 'client.messages.view')) {
            foreach (DB::table('client_messages')->join('client_conversations', 'client_conversations.id', '=', 'client_messages.client_conversation_id')->where('client_messages.company_id', $companyId)
                ->where('sender_side', 'office')->where('is_internal', false)->orderByDesc('client_messages.id')->limit(30)->get(['client_messages.created_at', 'client_conversations.subject']) as $message) {
                $events[] = ['at' => (string) $message->created_at, 'kind' => 'message', 'title' => 'Mensaje recibido: '.$message->subject, 'detail' => null];
            }
        }
        usort($events, fn (array $a, array $b): int => strcmp($b['at'], $a['at']));

        return array_map(fn (array $e): array => [...$e, 'at' => Carbon::parse($e['at'])->toIso8601String()], array_slice($events, 0, 150));
    }

    /** @return array<string, mixed> */
    public function company(int $companyId): array
    {
        $company = DB::table('companies')->join('offices', 'offices.id', '=', 'companies.office_id')->where('companies.id', $companyId)
            ->first(['companies.*', 'offices.name as office_name']) ?? abort(404);
        $accountant = DB::table('company_assignments')->join('users', 'users.id', '=', 'company_assignments.user_id')->where('company_id', $companyId)->where('assignment_role', 'accountant_in_charge')
            ->where('company_assignments.status', 'active')->whereNull('company_assignments.deleted_at')->orderByDesc('is_primary')->first(['users.name', 'users.email']);
        $period = DB::table('accounting_periods')->where('company_id', $companyId)->where('starts_on', '<=', now()->toDateString())->where('ends_on', '>=', now()->toDateString())->value('name');
        $open = DB::table('client_requests')->where('company_id', $companyId)->whereIn('status', ClientRequestService::OPEN)->count();

        return ['id' => (int) $company->id, 'name' => (string) $company->name, 'trade_name' => $company->trade_name, 'tax_id' => (string) $company->tax_id, 'address' => $company->address, 'phone' => $company->phone,
            'email' => $company->email, 'contact_name' => $company->contact_name, 'legal_representative' => $company->legal_representative, 'office' => (string) $company->office_name,
            'accountant' => $accountant?->name, 'accountant_email' => $accountant?->email, 'period' => $period, 'status_label' => $open > 0 ? 'Información pendiente de entregar ('.$open.')' : 'Al día con la oficina',
            'pending_changes' => DB::table('company_data_change_requests')->where('company_id', $companyId)->orderByDesc('id')->limit(20)->get(['field', 'requested_value', 'status', 'review_note', 'created_at'])
                ->map(fn (stdClass $c): array => ['field' => ClientRequestService::CHANGE_FIELDS[$c->field] ?? $c->field, 'value' => (string) $c->requested_value, 'status' => ['pending' => 'Pendiente de validar', 'approved' => 'Aplicado', 'rejected' => 'Rechazado'][$c->status] ?? $c->status,
                    'note' => $c->review_note, 'at' => (string) $c->created_at])->all()];
    }

    /** @return Collection<int, stdClass> */
    private function visibleRequests(Organization $organization, User $user, int $companyId): Collection
    {
        $types = array_values(array_filter(array_keys(ClientRequestService::TYPES), fn (string $type): bool => $this->access->can($organization, $user, $companyId, $this->requests->viewPermission($type))));

        return DB::table('client_requests')->where('company_id', $companyId)->whereIn('type', $types === [] ? ['-'] : $types)
            ->where(fn ($q) => $q->whereNull('requested_from_user_id')->orWhere('requested_from_user_id', $user->id))->whereNotIn('status', ['cancelled'])->orderByDesc('id')->limit(300)->get();
    }

    private function approvalsQuery(User $user, int $companyId): Builder
    {
        return DB::table('client_approval_requests')->where('company_id', $companyId)->where(fn ($q) => $q->whereNull('requested_from_user_id')->orWhere('requested_from_user_id', $user->id))->where('status', '!=', 'cancelled');
    }

    private function origin(string $type): string
    {
        return match ($type) {
            'fiscal' => 'Fiscal',
            'payroll' => 'Nómina',
            default => 'Contabilidad',
        };
    }
}
