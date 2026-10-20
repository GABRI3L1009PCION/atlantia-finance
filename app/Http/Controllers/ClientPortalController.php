<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\Audit\AuditEvidenceService;
use App\Services\ClientPortal\ClientAccessService;
use App\Services\ClientPortal\ClientAccountService;
use App\Services\ClientPortal\ClientDocumentService;
use App\Services\ClientPortal\ClientFinanceService;
use App\Services\ClientPortal\ClientMessageService;
use App\Services\ClientPortal\ClientPayrollService;
use App\Services\ClientPortal\ClientPortalService;
use App\Services\ClientPortal\ClientRequestService;
use App\Services\ClientPortal\PublishedReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * External client portal: the client delivers, answers, authorizes when it applies, consults and talks with its
 * accounting office. Every request is resolved against the client's active company access and contact role; the
 * client never reaches internal tasks, notes, other companies or office processes.
 */
class ClientPortalController extends Controller
{
    public function __construct(
        private ClientAccessService $access,
        private ClientPortalService $portal,
        private ClientRequestService $requests,
        private ClientDocumentService $documents,
        private PublishedReportService $reports,
        private ClientMessageService $messages,
    ) {}

    public function dashboard(Request $request): Response
    {
        [$organization, $user, $company] = $this->resolve($request, 'client.dashboard.view');

        return $this->render($request, 'client/dashboard', ['panel' => $this->portal->dashboard($organization, $user, (int) $company->id)]);
    }

    public function switchCompany(Request $request): RedirectResponse
    {
        $user = $request->user();
        $organization = $this->access->organization($user);
        $this->access->switchCompany($organization, $user, $request->integer('company_id'));

        return redirect('/client');
    }

    /** Sales, purchases, balances, bank position and the result of the year of the client's own company. */
    public function finances(Request $request, ClientFinanceService $finance): Response
    {
        [$organization, $user, $company] = $this->resolve($request, 'client.finances.view');
        $companyId = (int) $company->id;
        $canStatements = $this->access->can($organization, $user, $companyId, 'client.financial_reports.view');

        return $this->render($request, 'client/finances', [
            'finance' => $finance->summary($companyId),
            'statements' => $canStatements ? array_values(array_filter($this->reports->forClient($organization, $user, $companyId), fn (array $report): bool => in_array($report['report_type'], PublishedReportService::FINANCIAL, true))) : [],
        ]);
    }

    /** The account with the accounting office: fees charged, paid and pending, plus the payments the client reports. */
    public function account(Request $request, ClientAccountService $account): Response
    {
        [, , $company] = $this->resolve($request, 'client.account.view');

        return $this->render($request, 'client/account', ['account' => $account->statement((int) $company->id)]);
    }

    public function reportPayment(Request $request, ClientAccountService $account): RedirectResponse
    {
        [$organization, $user, $company] = $this->resolve($request, 'client.account.report_payment');
        $data = $request->validate([
            'invoice_id' => 'required|integer', 'amount' => 'required|string|max:13', 'paid_on' => 'required|date_format:Y-m-d|before_or_equal:today',
            'method' => ['required', Rule::in(array_keys(ClientAccountService::METHODS))], 'reference' => 'required|string|min:3|max:120', 'note' => 'nullable|string|max:500',
        ]);
        $account->reportPayment($organization, $user, (int) $company->id, ['invoice_id' => (int) $data['invoice_id'], 'amount' => (string) $data['amount'], 'paid_on' => (string) $data['paid_on'],
            'method' => (string) $data['method'], 'reference' => trim((string) $data['reference']), 'note' => $data['note'] ?? null]);

        return back()->with('workspace_notice', 'Pago informado. Tu oficina lo confirmará cuando lo vea reflejado.');
    }

    /** Pay periods the office is processing for the company, with headcount and totals; never individual salaries. */
    public function payroll(Request $request, ClientPayrollService $payroll): Response
    {
        [$organization, $user, $company] = $this->resolve($request, 'client.payroll.view');
        $companyId = (int) $company->id;

        return $this->render($request, 'client/payroll', [
            'payroll' => $payroll->overview($companyId),
            'requests' => array_values(array_filter($this->portal->requestList($organization, $user, $companyId), fn (array $row): bool => $row['type'] === 'payroll')),
        ]);
    }

    public function company(Request $request): Response
    {
        [, , $company] = $this->resolve($request, 'client.company.view');

        return $this->render($request, 'client/company', ['profile' => $this->portal->company((int) $company->id), 'fields' => ClientRequestService::CHANGE_FIELDS]);
    }

    public function changeRequest(Request $request): RedirectResponse
    {
        [$organization, $user, $company] = $this->resolve($request, 'client.company.change_request');
        $data = $request->validate(['field' => ['required', Rule::in(array_keys(ClientRequestService::CHANGE_FIELDS))], 'value' => 'required|string|max:255', 'reason' => 'nullable|string|max:500']);
        $this->requests->requestDataChange($organization, $user, (int) $company->id, (string) $data['field'], (string) $data['value'], $data['reason'] ?? null);

        return back()->with('workspace_notice', 'Solicitud de cambio enviada; tu oficina contable la validará.');
    }

    public function pending(Request $request): Response
    {
        [$organization, $user, $company] = $this->resolve($request, 'client.pending.view');

        return $this->render($request, 'client/pending', ['items' => $this->portal->pending($organization, $user, (int) $company->id)]);
    }

    public function calendar(Request $request): Response
    {
        [$organization, $user, $company] = $this->resolve($request, 'client.calendar.view');
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month')) ? Carbon::parse($request->query('month').'-01') : now()->startOfMonth();

        return $this->render($request, 'client/calendar', ['month' => $month->format('Y-m'), 'events' => $this->portal->calendar($organization, $user, (int) $company->id, $month->copy()->startOfMonth(), $month->copy()->endOfMonth())]);
    }

    public function documentsIndex(Request $request): Response
    {
        [, , $company] = $this->resolve($request, 'client.documents.view');

        return $this->render($request, 'client/documents', ['documents' => $this->portal->documentList((int) $company->id), 'categories' => ClientDocumentService::CATEGORIES]);
    }

    public function upload(Request $request): RedirectResponse
    {
        [$organization, $user, $company] = $this->resolve($request, 'client.documents.upload');
        $data = $request->validate(['file' => 'required|file|max:20480|mimes:pdf,jpg,jpeg,png,xlsx,xls,csv,docx,doc,txt,xml,zip', 'category' => ['required', Rule::in(array_keys(ClientDocumentService::CATEGORIES))], 'title' => 'required|string|max:180', 'period' => 'nullable|string|max:60']);
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);
        $this->documents->upload($organization, $user, (int) $company->id, $file, (string) $data['category'], (string) $data['title'], $data['period'] ?? null);

        return back()->with('workspace_notice', 'Documento recibido; tu oficina contable lo revisará.');
    }

    public function replace(Request $request, int $document): RedirectResponse
    {
        [$organization, $user, $company] = $this->resolve($request, 'client.documents.replace');
        $this->ownDocument((int) $company->id, $document);
        $data = $request->validate(['file' => 'required|file|max:20480|mimes:pdf,jpg,jpeg,png,xlsx,xls,csv,docx,doc,txt,xml,zip', 'reason' => 'required|string|max:500']);
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);
        $this->documents->replace($organization, $user, $document, $file, (string) $data['reason']);

        return back()->with('workspace_notice', 'Nueva versión entregada; la anterior se conserva.');
    }

    public function downloadDocument(Request $request, int $document): HttpResponse
    {
        [$organization, $user, $company] = $this->resolve($request, 'client.documents.view');
        $this->ownDocument((int) $company->id, $document);

        return $this->documents->download($organization, $user, $document, $request->integer('version') ?: null);
    }

    public function documentRequests(Request $request): Response
    {
        [$organization, $user, $company] = $this->resolve($request, 'client.document_requests.view');

        return $this->render($request, 'client/document-requests', [
            'requests' => $this->portal->requestList($organization, $user, (int) $company->id),
            'evidence' => $this->portal->evidenceRequests($user, (int) $company->id),
            'focus' => ['request' => $request->integer('request') ?: null, 'evidence' => $request->integer('evidence') ?: null],
        ]);
    }

    public function respondRequest(Request $request, int $clientRequest): RedirectResponse
    {
        [$organization, $user, $company] = $this->resolve($request, 'client.pending.view');
        abort_unless(DB::table('client_requests')->where('id', $clientRequest)->where('company_id', $company->id)->exists(), 404);
        $data = $request->validate(['response' => 'nullable|string|max:5000', 'files' => 'nullable|array|max:10', 'files.*' => 'file|max:20480|mimes:pdf,jpg,jpeg,png,xlsx,xls,csv,docx,doc,txt,xml,zip']);
        $files = array_values(array_filter((array) $request->file('files', []), fn ($file): bool => $file instanceof UploadedFile));
        $this->requests->respond($organization, $user, $clientRequest, (string) ($data['response'] ?? ''), $files);

        return back()->with('workspace_notice', 'Respuesta enviada a tu oficina contable.');
    }

    public function submitEvidence(Request $request, int $evidence, AuditEvidenceService $service): RedirectResponse
    {
        [$organization, $user, $company] = $this->resolve($request, 'client.pending.view');
        abort_unless(DB::table('audit_evidence_requests')->where('id', $evidence)->where('company_id', $company->id)->where('requested_from_user_id', $user->id)->exists(), 404);
        $data = $request->validate(['note' => 'required|string|min:3|max:5000', 'file' => 'required|file|max:20480|mimes:pdf,jpg,jpeg,png,xlsx,xls,csv,docx,doc,txt,xml,zip']);
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);
        $service->submit($organization, $user, $evidence, (string) $data['note'], $file, null, null);

        return back()->with('workspace_notice', 'Evidencia entregada a auditoría.');
    }

    public function approvals(Request $request): Response
    {
        [$organization, $user, $company] = $this->resolve($request, 'client.approvals.view');
        if ($request->integer('approval') > 0) {
            $row = DB::table('client_approval_requests')->where('company_id', $company->id)->where('id', $request->integer('approval'))->first();
            if ($row !== null) {
                $this->requests->markViewed($organization, $user, $row);
            }
        }

        return $this->render($request, 'client/approvals', ['approvals' => $this->portal->approvalList($user, (int) $company->id), 'focus' => $request->integer('approval') ?: null]);
    }

    public function respondApproval(Request $request, int $approval): RedirectResponse
    {
        [$organization, $user, $company] = $this->resolve($request, 'client.approvals.respond');
        abort_unless(DB::table('client_approval_requests')->where('id', $approval)->where('company_id', $company->id)->exists(), 404);
        $data = $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected', 'more_information_required'])], 'response' => 'nullable|string|max:2000']);
        $this->requests->respondApproval($organization, $user, $approval, (string) $data['decision'], $data['response'] ?? null);

        return back()->with('workspace_notice', 'Respuesta registrada. Tu oficina contable continúa el proceso.');
    }

    public function obligations(Request $request): Response
    {
        [, , $company] = $this->resolve($request, 'client.obligations.view');

        return $this->render($request, 'client/obligations', ['obligations' => $this->portal->obligations((int) $company->id)]);
    }

    public function fel(Request $request): Response
    {
        [$organization, $user, $company] = $this->resolve($request, 'client.fel.view');
        abort_unless($this->access->planHas($organization, 'fel'), 403, 'La facturación electrónica no está incluida en el plan.');

        return $this->render($request, 'client/fel', ['documents' => $this->portal->fel((int) $company->id, $this->access->can($organization, $user, (int) $company->id, 'client.fel.documents.download'))]);
    }

    public function felPdf(Request $request, int $document): HttpResponse
    {
        [$organization, $user, $company] = $this->resolve($request, 'client.fel.documents.download');
        abort_unless($this->access->planHas($organization, 'fel'), 403);
        $row = DB::table('fel_documents')->where('company_id', $company->id)->where('id', $document)->whereIn('status', ['certified', 'cancelled'])->first(['pdf_path', 'series', 'number']) ?? abort(404);
        abort_unless($row->pdf_path !== null && Storage::disk('local')->exists((string) $row->pdf_path), 404);
        app(AccountingService::class)->audit($organization, $user, 'client.fel.downloaded', 'fel_documents', $document, ['company_id' => (int) $company->id]);

        return Storage::disk('local')->download((string) $row->pdf_path, 'FEL-'.$row->series.'-'.$row->number.'.pdf');
    }

    public function reportsIndex(Request $request): Response
    {
        [$organization, $user, $company] = $this->resolve($request, 'client.reports.view');

        return $this->render($request, 'client/reports', ['reports' => $this->reports->forClient($organization, $user, (int) $company->id)]);
    }

    public function downloadReport(Request $request, int $report): HttpResponse
    {
        [$organization, $user, $company] = $this->resolve($request, 'client.reports.download');
        abort_unless(DB::table('published_reports')->where('id', $report)->where('company_id', $company->id)->exists(), 404);

        return $this->reports->download($organization, $user, $report, true);
    }

    public function history(Request $request): Response
    {
        [$organization, $user, $company] = $this->resolve($request, 'client.history.view');

        return $this->render($request, 'client/history', ['events' => $this->portal->history($organization, $user, (int) $company->id)]);
    }

    public function messagesIndex(Request $request): Response
    {
        [, , $company] = $this->resolve($request, 'client.messages.view');
        $open = $request->integer('conversation') ?: null;
        if ($open !== null) {
            abort_unless(DB::table('client_conversations')->where('id', $open)->where('company_id', $company->id)->exists(), 404);
            $this->messages->markRead($open, true);
        }

        return $this->render($request, 'client/messages', ['conversations' => $this->messages->conversations((int) $company->id, true, $open), 'open' => $open]);
    }

    public function sendMessage(Request $request): RedirectResponse
    {
        [$organization, $user, $company] = $this->resolve($request, 'client.messages.send');
        $data = $request->validate(['conversation_id' => 'nullable|integer', 'subject' => 'nullable|string|max:180', 'body' => 'required|string|max:5000']);
        if (! empty($data['conversation_id'])) {
            abort_unless(DB::table('client_conversations')->where('id', $data['conversation_id'])->where('company_id', $company->id)->exists(), 404);
            $this->messages->reply($organization, $user, (int) $data['conversation_id'], (string) $data['body']);
            $id = (int) $data['conversation_id'];
        } else {
            $id = $this->messages->startByClient($organization, $user, (int) $company->id, (string) ($data['subject'] ?: 'Consulta'), (string) $data['body']);
        }

        return redirect('/client/messages?conversation='.$id)->with('workspace_notice', 'Mensaje enviado.');
    }

    public function preferences(Request $request): Response
    {
        [$organization, $user, $company] = $this->resolve($request, 'client.dashboard.view');
        $role = $this->access->companies($organization, $user)[(int) $company->id] ?? 'viewer';

        return $this->render($request, 'client/preferences', ['contact_role' => ClientAccessService::CONTACT_ROLES[$role] ?? $role,
            'reminders' => DB::table('luzma_reminders')->where('luzma_reminders.organization_id', $organization->id)->where('user_id', $user->id)->whereIn('status', ['scheduled', 'snoozed', 'delivered', 'pending'])
                ->orderBy('remind_at')->limit(30)->get(['title', 'remind_at', 'status'])->map(fn (stdClass $r): array => ['title' => (string) $r->title, 'remind_at' => (string) $r->remind_at, 'status' => (string) $r->status])->all()]);
    }

    /** A notification about a client process opens the portal for the client and the office desk for staff. */
    public function open(Request $request, string $kind, int $id): RedirectResponse
    {
        $user = $request->user();
        $organization = $this->access->organization($user);
        $table = ['request' => 'client_requests', 'approval' => 'client_approval_requests', 'document' => 'client_documents', 'report' => 'published_reports', 'conversation' => 'client_conversations', 'change' => 'company_data_change_requests'][$kind] ?? abort(404);
        $companyId = (int) (DB::table($table)->where($table.'.organization_id', $organization->id)->where('id', $id)->value('company_id') ?? abort(404));
        if (isset($this->access->companies($organization, $user)[$companyId])) {
            session(['client_portal.company_id' => $companyId]);

            return redirect(match ($kind) {
                'request' => '/client/document-requests?request='.$id,
                'approval' => '/client/approvals?approval='.$id,
                'document' => '/client/documents',
                'report' => '/client/reports',
                'conversation' => '/client/messages?conversation='.$id,
                default => '/client/company',
            });
        }

        return redirect('/work/client-portal?company_id='.$companyId.'&tab='.['request' => 'requests', 'approval' => 'approvals', 'document' => 'documents', 'report' => 'reports', 'conversation' => 'messages', 'change' => 'company'][$kind]);
    }

    private function ownDocument(int $companyId, int $documentId): void
    {
        abort_unless(DB::table('client_documents')->where('id', $documentId)->where('company_id', $companyId)->exists(), 404);
    }

    /**
     * @return array{0: Organization, 1: User, 2: stdClass}
     */
    private function resolve(Request $request, string $permission): array
    {
        $user = $request->user();
        $organization = $this->access->organization($user);
        $company = $this->access->currentCompany($organization, $user);
        $this->access->ensure($organization, $user, (int) $company->id, $permission);

        return [$organization, $user, $company];
    }

    /** @param array<string, mixed> $props */
    private function render(Request $request, string $component, array $props): Response
    {
        $user = $request->user();
        $organization = $this->access->organization($user);
        $company = $this->access->currentCompany($organization, $user);
        $permissions = $this->access->permissions($organization, $user, (int) $company->id);
        $companyId = (int) $company->id;
        $period = DB::table('accounting_periods')->where('company_id', $companyId)->where('starts_on', '<=', now()->toDateString())->where('ends_on', '>=', now()->toDateString())->value('name');
        $pending = $permissions['client.pending.view'] ? count($this->portal->pending($organization, $user, $companyId)) : 0;

        return Inertia::render($component, [
            'context' => [
                'company' => ['id' => $companyId, 'public_id' => (string) $company->public_id, 'name' => (string) $company->name, 'tax_id' => (string) $company->tax_id],
                'companies' => DB::table('companies')->whereIn('id', array_keys($this->access->companies($organization, $user)) ?: [-1])->orderBy('name')->get(['id', 'name', 'tax_id'])
                    ->map(fn (stdClass $c): array => ['id' => (int) $c->id, 'name' => (string) $c->name, 'tax_id' => (string) $c->tax_id])->all(),
                'period' => $period, 'fel_enabled' => $permissions['client.fel.view'] && $this->access->planHas($organization, 'fel'),
                'badges' => [
                    'pending' => $pending,
                    'document_requests' => DB::table('client_requests')->where('company_id', $companyId)->whereIn('status', ClientRequestService::OPEN)->where(fn ($q) => $q->whereNull('requested_from_user_id')->orWhere('requested_from_user_id', $user->id))->count()
                        + DB::table('audit_evidence_requests')->where('company_id', $companyId)->where('requested_from_user_id', $user->id)->whereIn('status', ['requested', 'more_information_required', 'rejected'])->count(),
                    'approvals' => $permissions['client.approvals.view'] ? DB::table('client_approval_requests')->where('company_id', $companyId)->whereIn('status', ['pending', 'viewed'])->where(fn ($q) => $q->whereNull('requested_from_user_id')->orWhere('requested_from_user_id', $user->id))->count() : 0,
                    'messages' => $permissions['client.messages.view'] ? $this->messages->unreadForClient($companyId) : 0,
                ],
            ],
            'permissions' => $permissions,
            ...$props,
        ]);
    }
}
