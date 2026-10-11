<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\WorkManagement\FirmRevenueService;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RevenueController extends Controller
{
    public function __construct(
        private WorkspaceContext $context,
        private WorkAuthorizationService $authorization,
        private FirmRevenueService $revenue,
    ) {}

    /** GET office/revenue: the firm's own income and collections. Whoever holds crm.manage also gets the forms. */
    public function index(Request $request): Response
    {
        $organization = $this->context->organization($request->user());
        $this->authorization->ensureDefaults($organization);
        abort_unless($this->authorization->can($organization, $request->user(), 'crm.view'), 403);
        $filters = $request->validate(['month' => ['nullable', 'regex:/^\d{4}-\d{2}$/']]);

        return Inertia::render('work/revenue', [
            ...$this->revenue->overview($organization, $request->user(), $filters['month'] ?? null),
            'canManage' => $this->authorization->can($organization, $request->user(), 'crm.manage'),
        ]);
    }

    public function generate(Request $request): RedirectResponse
    {
        $organization = $this->manage($request);
        $data = $request->validate(['month' => ['required', 'regex:/^\d{4}-\d{2}$/']]);
        $created = $this->revenue->generateMonth($organization, $request->user(), $data['month']);

        return back()->with('workspace_notice', $created > 0 ? $created.' cobro(s) generado(s) correctamente.' : 'Todos los contratos ya tenían su cobro de ese mes.');
    }

    public function store(Request $request): RedirectResponse
    {
        $organization = $this->manage($request);
        $data = $request->validate([
            'company_id' => 'required|integer',
            'concept' => 'required|string|max:200',
            'amount' => 'required|numeric|min:0.01|max:100000000',
            'issued_on' => 'required|date',
            'due_on' => 'required|date|after_or_equal:issued_on',
        ]);
        $this->revenue->createInvoice($organization, $request->user(), $data);

        return back()->with('workspace_notice', 'Cobro registrado correctamente.');
    }

    public function paid(Request $request, int $invoice): RedirectResponse
    {
        $organization = $this->manage($request);
        $data = $request->validate(['paid_on' => 'nullable|date']);
        $this->revenue->markPaid($organization, $request->user(), $invoice, $data['paid_on'] ?? null);

        return back()->with('workspace_notice', 'Pago registrado correctamente.');
    }

    private function manage(Request $request): Organization
    {
        $organization = $this->context->organization($request->user());
        abort_unless($this->authorization->can($organization, $request->user(), 'crm.manage'), 403, 'Solo el Administrador de la firma gestiona los cobros.');

        return $organization;
    }
}
