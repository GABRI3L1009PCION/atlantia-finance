<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\ClientPortal\ClientAccessService;
use App\Services\ClientPortal\ClientDocumentService;
use App\Services\ClientPortal\ClientMessageService;
use App\Services\ClientPortal\ClientRequestService;
use App\Services\ClientPortal\PublishedReportService;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The office side of the client portal: what the office asks of each client company, what the client delivered and
 * answered, approvals, reports published to the client, conversations (with internal notes) and data changes.
 */
class OfficeClientPortalController extends Controller
{
    public function __construct(
        private WorkspaceContext $context,
        private WorkAuthorizationService $authorization,
        private ClientAccessService $access,
        private ClientRequestService $requests,
        private ClientDocumentService $documents,
        private PublishedReportService $reports,
        private ClientMessageService $messages,
    ) {}

    public function index(Request $request): Response
    {
        [$organization, $user, $companyIds] = $this->resolve($request);
        $companyId = in_array($request->integer('company_id'), $companyIds, true) ? $request->integer('company_id') : ($companyIds[0] ?? null);
        $tab = $request->string('tab')->toString() ?: 'requests';
        $can = fn (string $permission): bool => $this->authorization->can($organization, $user, $permission);
        $data = [];
        if ($companyId !== null) {
            $data = [
                'contacts' => $this->access->contacts($companyId),
                'requests' => DB::table('client_requests')->where('company_id', $companyId)->orderByDesc('id')->limit(300)->get()->map(fn (stdClass $r): array => [...$this->requests->requestRow($r), 'internal_note' => $r->internal_note,
                    'requested_from' => $r->requested_from_user_id === null ? 'Todos los contactos' : (string) DB::table('users')->where('id', $r->requested_from_user_id)->value('name')])->all(),
                'documents' => DB::table('client_documents')->where('company_id', $companyId)->orderByDesc('id')->limit(300)->get()->map(fn (stdClass $d): array => $this->documents->row($d, true))->all(),
                'approvals' => DB::table('client_approval_requests')->where('company_id', $companyId)->orderByDesc('id')->limit(200)->get()->map(fn (stdClass $a): array => [...$this->requests->approvalRow($a), 'internal_note' => $a->internal_note])->all(),
                'reports' => DB::table('published_reports')->where('company_id', $companyId)->orderByDesc('id')->limit(200)->get()->map(fn (stdClass $r): array => $this->reports->officeRow($r))->all(),
                'conversations' => $this->messages->conversations($companyId, false, $request->integer('conversation') ?: null),
                'changes' => DB::table('company_data_change_requests')->join('users', 'users.id', '=', 'company_data_change_requests.requested_by')->where('company_id', $companyId)->orderByDesc('company_data_change_requests.id')->limit(50)
                    ->get(['company_data_change_requests.*', 'users.name'])->map(fn (stdClass $c): array => ['id' => (int) $c->id, 'field' => ClientRequestService::CHANGE_FIELDS[$c->field] ?? $c->field, 'current' => $c->current_value,
                        'requested' => (string) $c->requested_value, 'reason' => $c->reason, 'status' => (string) $c->status, 'by' => (string) $c->name, 'at' => (string) $c->created_at])->all(),
                'sources' => [
                    'fiscal_obligations' => DB::table('fiscal_obligations')->where('company_id', $companyId)->whereNotIn('status', ['completed', 'cancelled'])->orderBy('due_on')->limit(50)->get(['id', 'name', 'reference_period'])->map(fn (stdClass $o): array => ['id' => (int) $o->id, 'label' => $o->name.' · '.$o->reference_period])->all(),
                    'payroll_periods' => DB::table('payroll_periods')->where('company_id', $companyId)->whereNotIn('status', ['closed', 'cancelled'])->orderByDesc('starts_on')->limit(12)->get(['id', 'name'])->map(fn (stdClass $p): array => ['id' => (int) $p->id, 'label' => 'Nómina '.$p->name])->all(),
                    'payment_instructions' => DB::table('payment_instructions')->where('company_id', $companyId)->whereIn('status', ['pending_approval', 'approved', 'scheduled'])->orderByDesc('id')->limit(30)->get(['id', 'supplier_name', 'amount_cents'])->map(fn (stdClass $p): array => ['id' => (int) $p->id, 'label' => $p->supplier_name.' · Q '.number_format((int) $p->amount_cents / 100, 2)])->all(),
                ],
            ];
            if ($request->integer('conversation') > 0) {
                $this->messages->markRead($request->integer('conversation'), false);
            }
        }

        return Inertia::render('client-portal-office/index', [
            'companies' => DB::table('companies')->whereIn('id', $companyIds ?: [-1])->orderBy('name')->get(['id', 'name'])->map(fn (stdClass $c): array => ['id' => (int) $c->id, 'name' => (string) $c->name])->all(),
            'companyId' => $companyId, 'tab' => $tab, ...$data,
            'clientUsers' => $can('office.companies.assign') ? $this->clientUsers($organization) : [],
            'options' => ['types' => ClientRequestService::TYPES, 'approval_types' => ClientRequestService::APPROVAL_TYPES, 'report_types' => PublishedReportService::TYPES, 'generated' => array_keys(PublishedReportService::GENERATED),
                'contact_roles' => ClientAccessService::CONTACT_ROLES, 'categories' => ClientDocumentService::CATEGORIES],
            'can' => ['requests' => $can('client_portal.requests.manage'), 'documents' => $can('client_portal.documents.review'), 'approvals' => $can('client_portal.approvals.request'), 'publish' => $can('client_portal.reports.publish'),
                'messages' => $can('client_portal.messages.manage'), 'company' => $can('client_portal.company_data.review'), 'contacts' => $can('office.companies.assign'), 'user_id' => $user->id],
        ]);
    }

    public function storeRequest(Request $request): RedirectResponse
    {
        [$organization, $user] = $this->resolve($request);
        $data = $request->validate(['company_id' => 'required|integer', 'type' => ['required', Rule::in(array_keys(ClientRequestService::TYPES))], 'title' => 'required|string|max:180', 'description' => 'nullable|string|max:5000',
            'document_type' => ['nullable', Rule::in(array_keys(ClientDocumentService::CATEGORIES))], 'period_label' => 'nullable|string|max:60', 'requested_from_user_id' => 'nullable|integer', 'due_date' => 'nullable|date',
            'source_type' => ['nullable', Rule::in(array_keys(ClientRequestService::SOURCES))], 'source_id' => 'nullable|integer|required_with:source_type', 'internal_note' => 'nullable|string|max:2000']);
        $this->requests->create($organization, $user, ['company_id' => (int) $data['company_id'], 'type' => (string) $data['type'], 'title' => (string) $data['title'], 'description' => $data['description'] ?? null,
            'document_type' => $data['document_type'] ?? null, 'period_label' => $data['period_label'] ?? null, 'requested_from_user_id' => isset($data['requested_from_user_id']) ? (int) $data['requested_from_user_id'] : null,
            'due_date' => isset($data['due_date']) ? Carbon::parse($data['due_date'])->toDateString() : null, 'source_type' => $data['source_type'] ?? null, 'source_id' => isset($data['source_id']) ? (int) $data['source_id'] : null,
            'internal_note' => $data['internal_note'] ?? null]);

        return back()->with('workspace_notice', 'Solicitud enviada al cliente.');
    }

    public function requestAction(Request $request, int $clientRequest, string $action): RedirectResponse
    {
        [$organization, $user] = $this->resolve($request);
        $this->requests->review($organization, $user, $clientRequest, $action, $request->validate(['note' => 'nullable|string|max:2000'])['note'] ?? null);

        return back()->with('workspace_notice', 'Solicitud actualizada.');
    }

    public function documentAction(Request $request, int $document, string $action): RedirectResponse
    {
        [$organization, $user] = $this->resolve($request);
        $data = $request->validate(['note' => 'nullable|string|max:2000', 'internal_note' => 'nullable|string|max:2000']);
        $this->documents->review($organization, $user, $document, $action, $data['note'] ?? null, $data['internal_note'] ?? null);

        return back()->with('workspace_notice', 'Documento actualizado.');
    }

    public function downloadDocument(Request $request, int $document): HttpResponse
    {
        [$organization, $user] = $this->resolve($request);

        return $this->documents->download($organization, $user, $document, $request->integer('version') ?: null);
    }

    public function storeApproval(Request $request): RedirectResponse
    {
        [$organization, $user] = $this->resolve($request);
        $data = $request->validate(['company_id' => 'required|integer', 'type' => ['required', Rule::in(array_keys(ClientRequestService::APPROVAL_TYPES))], 'title' => 'required|string|max:180', 'description' => 'nullable|string|max:5000',
            'requested_from_user_id' => 'nullable|integer', 'due_date' => 'nullable|date', 'source_type' => ['nullable', Rule::in(array_keys(ClientRequestService::SOURCES))], 'source_id' => 'nullable|integer|required_with:source_type',
            'internal_note' => 'nullable|string|max:2000']);
        $this->requests->requestApproval($organization, $user, ['company_id' => (int) $data['company_id'], 'type' => (string) $data['type'], 'title' => (string) $data['title'], 'description' => $data['description'] ?? null,
            'requested_from_user_id' => isset($data['requested_from_user_id']) ? (int) $data['requested_from_user_id'] : null, 'due_date' => isset($data['due_date']) ? Carbon::parse($data['due_date'])->toDateString() : null,
            'source_type' => $data['source_type'] ?? null, 'source_id' => isset($data['source_id']) ? (int) $data['source_id'] : null, 'internal_note' => $data['internal_note'] ?? null]);

        return back()->with('workspace_notice', 'Autorización solicitada al cliente.');
    }

    public function cancelApproval(Request $request, int $approval): RedirectResponse
    {
        [$organization, $user] = $this->resolve($request);
        $this->requests->cancelApproval($organization, $user, $approval, (string) $request->validate(['note' => 'required|string|min:5|max:500'])['note']);

        return back()->with('workspace_notice', 'Autorización cancelada.');
    }

    public function storeReport(Request $request): RedirectResponse
    {
        [$organization, $user] = $this->resolve($request);
        $data = $request->validate(['company_id' => 'required|integer', 'report_type' => ['required', Rule::in(array_keys(PublishedReportService::TYPES))], 'period_start' => 'required|date', 'period_end' => 'required|date', 'file' => 'nullable|file|mimes:pdf|max:20480']);
        $file = $request->file('file');
        $this->reports->prepare($organization, $user, (int) $data['company_id'], (string) $data['report_type'], Carbon::parse($data['period_start'])->toDateString(), Carbon::parse($data['period_end'])->toDateString(), $file instanceof UploadedFile ? $file : null);

        return back()->with('workspace_notice', 'Reporte preparado en borrador; envíalo a revisión para que otra persona lo apruebe.');
    }

    public function reportAction(Request $request, int $report, string $action): RedirectResponse
    {
        [$organization, $user] = $this->resolve($request);
        $this->reports->action($organization, $user, $report, $action, $request->validate(['note' => 'nullable|string|max:500'])['note'] ?? null);

        return back()->with('workspace_notice', ['submit' => 'Reporte enviado a revisión.', 'approve' => 'Reporte aprobado.', 'publish' => 'Reporte publicado al cliente.', 'revoke' => 'Reporte revocado.'][$action] ?? 'Reporte actualizado.');
    }

    public function downloadReport(Request $request, int $report): HttpResponse
    {
        [$organization, $user] = $this->resolve($request);

        return $this->reports->download($organization, $user, $report, false);
    }

    public function sendMessage(Request $request): RedirectResponse
    {
        [$organization, $user] = $this->resolve($request);
        $data = $request->validate(['company_id' => 'required|integer', 'conversation_id' => 'nullable|integer', 'subject' => 'nullable|string|max:180', 'body' => 'required|string|max:5000', 'internal' => 'nullable|boolean']);
        if (! empty($data['conversation_id'])) {
            $this->messages->reply($organization, $user, (int) $data['conversation_id'], (string) $data['body'], (bool) ($data['internal'] ?? false));
            $id = (int) $data['conversation_id'];
        } else {
            $id = $this->messages->startByOffice($organization, $user, (int) $data['company_id'], (string) ($data['subject'] ?: 'Mensaje de la oficina'), (string) $data['body'], null, null);
        }

        return redirect('/work/client-portal?company_id='.$data['company_id'].'&tab=messages&conversation='.$id)->with('workspace_notice', 'Mensaje registrado.');
    }

    public function changeAction(Request $request, int $change, string $action): RedirectResponse
    {
        [$organization, $user] = $this->resolve($request);
        $this->requests->reviewDataChange($organization, $user, $change, $action === 'approve', $request->validate(['note' => 'nullable|string|max:500'])['note'] ?? null);

        return back()->with('workspace_notice', $action === 'approve' ? 'Cambio aplicado a los datos de la empresa.' : 'Cambio rechazado.');
    }

    /** Links an external client user to a company with a contact role (office administration only). */
    public function storeContact(Request $request, AccountingService $accounting): RedirectResponse
    {
        [$organization, $user, $companyIds] = $this->resolve($request);
        abort_unless($this->authorization->can($organization, $user, 'office.companies.assign'), 403);
        $data = $request->validate(['company_id' => 'required|integer', 'user_id' => 'required|integer', 'contact_role' => ['required', Rule::in(array_keys(ClientAccessService::CONTACT_ROLES))], 'status' => ['nullable', Rule::in(['active', 'revoked'])]]);
        abort_unless(in_array((int) $data['company_id'], $companyIds, true), 403);
        abort_unless(collect($this->clientUsers($organization))->contains('id', (int) $data['user_id']), 422, 'Solo se vinculan usuarios con rol de cliente externo.');
        DB::table('client_company_access')->updateOrInsert(['company_id' => (int) $data['company_id'], 'user_id' => (int) $data['user_id']],
            ['organization_id' => $organization->id, 'contact_role' => $data['contact_role'], 'status' => $data['status'] ?? 'active', 'granted_by' => $user->id, 'updated_at' => now(), 'created_at' => now()]);
        $accounting->audit($organization, $user, 'client_portal.contact.saved', 'companies', (int) $data['company_id'], ['user_id' => (int) $data['user_id'], 'role' => $data['contact_role'], 'status' => $data['status'] ?? 'active']);

        return back()->with('workspace_notice', 'Acceso del contacto actualizado.');
    }

    /** @return list<array{id: int, name: string, email: string}> */
    private function clientUsers(Organization $organization): array
    {
        return array_values(DB::table('user_role_assignments')->join('workspace_roles', 'workspace_roles.id', '=', 'user_role_assignments.workspace_role_id')->join('users', 'users.id', '=', 'user_role_assignments.user_id')
            ->where('user_role_assignments.organization_id', $organization->id)->where('workspace_roles.code', 'client_user')->where('user_role_assignments.status', 'active')->whereNull('user_role_assignments.deleted_at')
            ->orderBy('users.name')->get(['users.id', 'users.name', 'users.email'])->unique('id')->map(fn (stdClass $u): array => ['id' => (int) $u->id, 'name' => (string) $u->name, 'email' => (string) $u->email])->all());
    }

    /**
     * @return array{0: Organization, 1: User, 2: list<int>}
     */
    private function resolve(Request $request): array
    {
        $user = $request->user();
        $organization = $this->context->organization($user);
        $this->authorization->ensureDefaults($organization);
        abort_unless($this->authorization->can($organization, $user, 'client_portal.view'), 403);
        $owner = DB::table('organization_user')->where('organization_user.organization_id', $organization->id)->where('user_id', $user->id)->where('role', 'owner')->where('status', 'active')->exists();
        $ids = $owner ? DB::table('companies')->where('companies.organization_id', $organization->id)->pluck('id')
            : DB::table('company_assignments')->where('company_assignments.organization_id', $organization->id)->where('user_id', $user->id)->where('status', 'active')->whereNull('deleted_at')->pluck('company_id');

        return [$organization, $user, array_values(array_unique(array_map('intval', $ids->all())))];
    }
}
