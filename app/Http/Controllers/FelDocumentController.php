<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\GenerateInvoicePdfJob;
use App\Jobs\SendInvoiceEmailJob;
use App\Models\FelDocument;
use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\Fel\FelAccountingService;
use App\Services\Fel\FelCalculator;
use App\Services\Fel\FelCancellationService;
use App\Services\Fel\FelCertifiers;
use App\Services\Fel\FelDocumentService;
use App\Services\Fel\FelDocumentStatus;
use App\Services\Fel\FelIssuerSettings;
use App\Services\Fel\FelPdfService;
use App\Services\Fel\SatRegistrationService;
use App\Services\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FelDocumentController extends Controller
{
    public function __construct(
        private WorkspaceContext $context,
        private AccountingService $accounting,
        private FelDocumentService $documents,
        private FelIssuerSettings $issuers,
    ) {}

    public function index(Request $request, string $company): Response
    {
        [$organization, $record] = $this->scope($request, $company);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:draft,awaiting_sat,pending,processing,certified,failed,cancelled'],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'customer' => ['nullable', 'integer'], 'min' => ['nullable', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/D'],
            'max' => ['nullable', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/D'],
        ]);
        $query = FelDocument::forCompany($record->id);
        foreach (['status' => 'status', 'customer' => 'customer_id'] as $filter => $column) {
            if (isset($filters[$filter])) {
                $query->where($column, $filters[$filter]);
            }
        }
        if (! empty($filters['search'])) {
            $query->where('number', ctype_digit($filters['search']) ? (int) $filters['search'] : -1);
        }
        if (isset($filters['from'])) {
            $query->whereDate('issued_on', '>=', $filters['from']);
        }
        if (isset($filters['to'])) {
            $query->whereDate('issued_on', '<=', $filters['to']);
        }
        if (isset($filters['min'])) {
            $query->where('total_cents', '>=', $this->accounting->cents($filters['min']));
        }
        if (isset($filters['max'])) {
            $query->where('total_cents', '<=', $this->accounting->cents($filters['max']));
        }
        $month = FelDocument::forCompany($record->id)->whereBetween('issued_on', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()]);
        $stats = [
            'documents' => (clone $month)->count(), 'total' => (int) (clone $month)->where('status', 'certified')->sum('total_cents'),
            'pending' => (clone $month)->whereIn('status', ['pending', 'processing', 'awaiting_sat'])->count(),
            'certified' => (clone $month)->where('status', 'certified')->count(),
            'failed' => (clone $month)->where('status', 'failed')->count(), 'cancelled' => (clone $month)->where('status', 'cancelled')->count(),
        ];

        return Inertia::render('accounting/fel/index', [
            'company' => $record, 'companies' => $this->accounting->companies($organization, $request->user())->get(),
            'documents' => $query->latest('id')->paginate(15)->withQueryString(), 'stats' => $stats, 'filters' => $filters,
            'customers' => DB::table('sales_customers')->where('company_id', $record->id)->orderBy('name')->get(),
            'canWrite' => $this->canWrite($organization, $record->id, $request->user()), 'notice' => $this->noticeFor($this->issuers->forCompany($record)['provider']),
            'canManage' => $this->accounting->canModule($organization, $record->id, $request->user(), 'fel.settings'),
            'issuer' => $this->issuers->forCompany($record),
            'certification' => $this->issuers->certifierFor($record),
            'certifiers' => collect(FelCertifiers::CERTIFIERS)->map(fn (array $definition, string $code): array => ['code' => $code, ...$definition])->values()->all(),
            'vatAffiliations' => FelCertifiers::VAT_AFFILIATIONS,
            'isPlatform' => $company === $this->issuers->platformCompanyUuid(),
            'today' => now()->toDateString(),
        ]);
    }

    public function quote(Request $request, string $company, FelCalculator $calculator): JsonResponse
    {
        [, $record] = $this->scope($request, $company, 'fel.manage');
        $data = $request->validate(FelDocumentService::rules());
        DB::table('sales_customers')->where('company_id', $record->id)->where('id', $data['customer_id'])->firstOrFail();
        $result = $calculator->calculate($record->id, $data['issued_on'], $data['lines']);
        unset($result['fiscal_profile']);

        return response()->json($result);
    }

    public function create(Request $request, string $company): Response
    {
        [, $record] = $this->scope($request, $company, 'fel.manage');

        return $this->form($record);
    }

    public function edit(Request $request, string $company, string $document): Response
    {
        [, $record] = $this->scope($request, $company, 'fel.manage');
        $doc = $this->document($record, $document);
        abort_unless($doc->status === FelDocumentStatus::Draft, 422);

        return $this->form($record, $doc->load('lines'));
    }

    public function store(Request $request, string $company): RedirectResponse
    {
        [$organization, $record] = $this->scope($request, $company, 'fel.manage');
        $doc = $this->documents->save($organization, $request->user(), $record, $request->validate(FelDocumentService::rules()));

        return to_route('accounting.fel.show', [$company, $doc->public_id]);
    }

    public function update(Request $request, string $company, string $document): RedirectResponse
    {
        [$organization, $record] = $this->scope($request, $company, 'fel.manage');
        $doc = $this->documents->save($organization, $request->user(), $record, $request->validate(FelDocumentService::rules()), $document);

        return to_route('accounting.fel.show', [$company, $doc->public_id]);
    }

    public function show(Request $request, string $company, string $document): Response
    {
        [$organization, $record] = $this->scope($request, $company);
        $doc = $this->document($record, $document)->load('lines');

        return Inertia::render('accounting/fel/show', [
            'company' => $record, 'document' => $doc, 'notice' => $this->noticeFor($doc->provider),
            'canCertify' => $this->accounting->canModule($organization, $record->id, $request->user(), 'fel.certify'),
            'satPortalUrl' => config('fel.sat_portal_url'), 'assignees' => $this->assignees($record->id)->values(),
            'canWrite' => $this->canWrite($organization, $record->id, $request->user()),
            'history' => DB::table('audit_logs')->where('organization_id', $organization->id)
                ->where('auditable_type', 'fel_documents')->where('auditable_id', $doc->id)->orderByDesc('id')
                ->get(['action', 'user_id', 'occurred_at']),
            'entry' => DB::table('accounting_source_entries')->where('company_id', $record->id)->where('source_type', 'fel_document')
                ->where('source_id', $doc->id)->where('event', 'origin')->value('journal_entry_id'),
        ]);
    }

    public function certify(Request $request, string $company, string $document): RedirectResponse
    {
        [, $record] = $this->scope($request, $company, 'fel.certify');
        $this->documents->requestCertification($this->document($record, $document), $request->user()->id);

        return back();
    }

    public function pdf(Request $request, string $company, string $document, FelPdfService $pdf): StreamedResponse
    {
        [, $record] = $this->scope($request, $company);
        $doc = $this->document($record, $document);
        $path = $pdf->path($doc);
        abort_unless(in_array($doc->status, [FelDocumentStatus::Certified, FelDocumentStatus::Cancelled], true)
            && $doc->pdf_path === $path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, ($doc->provider === 'mock' ? 'FEL-PRUEBA-' : 'FEL-').$doc->number.'.pdf', [
            'Cache-Control' => 'private, no-store', 'Content-Type' => 'application/pdf',
        ]);
    }

    public function email(Request $request, string $company, string $document): RedirectResponse
    {
        [, $record] = $this->scope($request, $company, 'fel.manage');
        $doc = $this->document($record, $document);
        abort_unless($doc->status === FelDocumentStatus::Certified && $doc->pdf_path && ! $doc->email_sent_at, 422);
        SendInvoiceEmailJob::dispatch($record->id, $document)->afterCommit();

        return back();
    }

    public function regeneratePdf(Request $request, string $company, string $document): RedirectResponse
    {
        [, $record] = $this->scope($request, $company, 'fel.manage');
        $doc = $this->document($record, $document);
        abort_unless(in_array($doc->status, [FelDocumentStatus::Certified, FelDocumentStatus::Cancelled], true), 422);
        GenerateInvoicePdfJob::dispatch($record->id, $document)->afterCommit();

        return back();
    }

    public function cancel(Request $request, string $company, string $document, FelCancellationService $service): RedirectResponse
    {
        [$organization, $record] = $this->scope($request, $company, 'fel.cancel');
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $service->cancel($this->document($record, $document), $organization, $request->user(), $data['reason']);

        return back();
    }

    /** The firm issued the document in the SAT portal: registers the XML it gave back, or its key data. */
    public function satRegister(Request $request, string $company, string $document, SatRegistrationService $service): RedirectResponse
    {
        [, $record] = $this->scope($request, $company, 'fel.manage');
        $service->register($this->document($record, $document), $request->user(), [
            'authorization_number' => $request->input('authorization_number'), 'fel_series' => $request->input('fel_series'),
            'fel_number' => $request->input('fel_number'), 'certified_at' => $request->input('certified_at'),
        ], $request->file('sat_xml'));

        return back()->with('accounting_notice', 'Emisión registrada. El documento ya figura como emitido en la SAT.');
    }

    public function satReturn(Request $request, string $company, string $document, SatRegistrationService $service): RedirectResponse
    {
        [, $record] = $this->scope($request, $company, 'fel.manage');
        $service->returnToDraft($this->document($record, $document), $request->user());

        return back()->with('accounting_notice', 'El documento volvió a borrador para corregirlo.');
    }

    /** The accountant hands the portal step to an assistant of the company as a work task. */
    public function satAssign(Request $request, string $company, string $document, SatRegistrationService $service): RedirectResponse
    {
        [$organization, $record] = $this->scope($request, $company, 'fel.certify');
        $data = $request->validate(['user_id' => ['required', 'integer']]);
        abort_unless($this->assignees($record->id)->contains('id', (int) $data['user_id']), 422);
        $service->assignTask($this->document($record, $document), $organization, $request->user(), (int) $data['user_id']);

        return back()->with('accounting_notice', 'Tarea asignada. La persona recibió el aviso para emitirla en la SAT.');
    }

    public function accounting(Request $request, string $company, string $document, FelAccountingService $service): RedirectResponse
    {
        [$organization, $record] = $this->scope($request, $company, 'fel.accounting');
        $this->context->requireFeature($organization, 'basic_accounting');
        $service->draft($this->document($record, $document), $organization, $request->user());

        return back();
    }

    /**
     * People assigned to the company who can carry out the SAT step.
     *
     * @return Collection<int, array{id: int, name: string}>
     */
    private function assignees(int $companyId): Collection
    {
        return DB::table('company_assignments')->join('users', 'users.id', '=', 'company_assignments.user_id')
            ->where('company_assignments.company_id', $companyId)->where('company_assignments.status', 'active')->whereNull('company_assignments.deleted_at')
            ->whereIn('company_assignments.assignment_role', ['accounting_assistant', 'accountant_in_charge', 'tax_specialist'])
            ->distinct()->orderBy('users.name')->get(['users.id', 'users.name'])
            ->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name]);
    }

    /** The test banner only belongs to simulated documents; the ones issued in the SAT portal are real. */
    private function noticeFor(string $provider): ?string
    {
        return $provider === 'mock' ? config('fel.notice') : null;
    }

    private function document(stdClass $company, string $uuid): FelDocument
    {
        return FelDocument::forCompany($company->id)->where('public_id', $uuid)->firstOrFail();
    }

    private function form(stdClass $company, ?FelDocument $document = null): Response
    {
        return Inertia::render('accounting/fel/create', ['company' => $company, 'document' => $document,
            'customers' => DB::table('sales_customers')->where('company_id', $company->id)->orderBy('name')->get(),
            'invoices' => DB::table('sales_invoices')->where('company_id', $company->id)->where('status', 'issued')->orderByDesc('number')->limit(500)->get(['id', 'number', 'sales_customer_id', 'total_cents', 'issued_on']),
            'today' => now()->toDateString(), 'notice' => $this->noticeFor($this->issuers->forCompany($company)['provider']),
            'satManual' => $this->issuers->forCompany($company)['provider'] === 'sat_manual']);
    }

    /** @return array{Organization, stdClass} */
    private function scope(Request $request, string $company, string $permission = 'fel.view'): array
    {
        $organization = $this->context->organization($request->user());
        $this->context->requireFeature($organization, 'basic_invoicing');
        $record = $this->accounting->company($organization, $request->user(), $company, ! $this->isReadPermission($permission));
        $this->accounting->assertModule($organization, $record->id, $request->user(), $permission);

        return [$organization, $record];
    }

    private function canWrite(Organization $organization, int $companyId, User $user): bool
    {
        return $this->accounting->canModule($organization, $companyId, $user, 'fel.manage');
    }

    private function isReadPermission(string $permission): bool
    {
        return str_ends_with($permission, '.view') || str_ends_with($permission, '.export');
    }
}
