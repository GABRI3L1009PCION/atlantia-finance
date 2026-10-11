<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\WorkManagement\CrmService;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CrmController extends Controller
{
    public function __construct(
        private WorkspaceContext $context,
        private WorkAuthorizationService $authorization,
        private CrmService $crm,
    ) {}

    /** GET office/crm: the firm's commercial portfolio. Whoever holds crm.manage also gets the forms. */
    public function index(Request $request): Response
    {
        $organization = $this->context->organization($request->user());
        $this->authorization->ensureDefaults($organization);
        abort_unless($this->authorization->can($organization, $request->user(), 'crm.view'), 403);

        return Inertia::render('work/crm', [
            ...$this->crm->overview($organization, $request->user()),
            'canManage' => $this->authorization->can($organization, $request->user(), 'crm.manage'),
            'stages' => CrmService::STAGES,
        ]);
    }

    public function storeProspect(Request $request): RedirectResponse
    {
        $organization = $this->manage($request);
        $data = $request->validate([
            'name' => 'required|string|max:160',
            'contact_name' => 'nullable|string|max:120',
            'contact_email' => 'nullable|email|max:190',
            'contact_phone' => 'nullable|string|max:40',
            'stage' => ['required', Rule::in(['prospect', 'contact', 'proposal', 'negotiation'])],
            'estimated_value' => 'nullable|numeric|min:0|max:100000000',
            'expected_close_on' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',
        ]);
        $this->crm->createProspect($organization, $request->user(), $data);

        return back()->with('workspace_notice', 'Prospecto registrado correctamente.');
    }

    public function moveProspect(Request $request, int $prospect): RedirectResponse
    {
        $organization = $this->manage($request);
        $data = $request->validate(['stage' => ['required', Rule::in(['prospect', 'contact', 'proposal', 'negotiation', 'won', 'lost'])]]);
        $this->crm->moveProspect($organization, $request->user(), $prospect, $data['stage']);

        return back()->with('workspace_notice', 'Etapa del prospecto actualizada.');
    }

    public function saveContract(Request $request): RedirectResponse
    {
        $organization = $this->manage($request);
        $data = $request->validate([
            'company_id' => 'required|integer',
            'services' => 'required|string|max:160',
            'monthly_fee' => 'required|numeric|min:0|max:100000000',
            'starts_on' => 'required|date',
            'renews_on' => 'nullable|date|after_or_equal:starts_on',
            'status' => ['required', Rule::in(['active', 'onboarding', 'at_risk', 'cancelled'])],
            'owner_id' => 'nullable|integer',
        ]);
        $this->crm->saveContract($organization, $request->user(), $data);

        return back()->with('workspace_notice', 'Contrato guardado correctamente.');
    }

    public function storeInteraction(Request $request): RedirectResponse
    {
        $organization = $this->manage($request);
        $data = $request->validate([
            'company_id' => 'nullable|integer',
            'crm_prospect_id' => 'nullable|integer',
            'kind' => ['required', Rule::in(['meeting', 'email', 'call', 'note'])],
            'summary' => 'required|string|max:300',
            'next_action' => 'nullable|string|max:200',
            'next_action_on' => 'nullable|date',
        ]);
        $this->crm->logInteraction($organization, $request->user(), $data);

        return back()->with('workspace_notice', 'Interacción registrada correctamente.');
    }

    private function manage(Request $request): Organization
    {
        $organization = $this->context->organization($request->user());
        abort_unless($this->authorization->can($organization, $request->user(), 'crm.manage'), 403, 'Solo el Administrador de la firma gestiona la cartera comercial.');

        return $organization;
    }
}
