<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\WorkManagement\TrainingService;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TrainingController extends Controller
{
    private const AREAS = ['Contabilidad', 'Fiscal', 'Nómina', 'Sistemas', 'Normativas', 'Auditoría', 'Habilidades blandas'];

    public function __construct(
        private WorkspaceContext $context,
        private WorkAuthorizationService $authorization,
        private TrainingService $training,
    ) {}

    /** GET office/training: management reads the overview; whoever holds training.manage also gets the forms. */
    public function index(Request $request): Response
    {
        $organization = $this->context->organization($request->user());
        $this->authorization->ensureDefaults($organization);
        abort_unless($this->authorization->can($organization, $request->user(), 'training.view'), 403);

        return Inertia::render('work/training', [
            ...$this->training->overview($organization),
            'canManage' => $this->authorization->can($organization, $request->user(), 'training.manage'),
            'areaOptions' => self::AREAS,
        ]);
    }

    public function storeCourse(Request $request): RedirectResponse
    {
        $organization = $this->manage($request);
        $data = $request->validate([
            'title' => 'required|string|max:160',
            'area' => ['required', Rule::in(self::AREAS)],
            'modality' => ['required', Rule::in(['virtual', 'presencial', 'híbrido'])],
            'starts_on' => 'nullable|date',
            'capacity' => 'nullable|integer|min:1|max:5000',
            'status' => ['nullable', Rule::in(['scheduled', 'open', 'in_progress', 'completed', 'cancelled'])],
            'description' => 'nullable|string|max:2000',
        ]);
        $this->training->createCourse($organization, $request->user(), $data);

        return back()->with('workspace_notice', 'Curso registrado correctamente.');
    }

    public function enroll(Request $request, int $course): RedirectResponse
    {
        $organization = $this->manage($request);
        $data = $request->validate(['user_ids' => 'required|array|min:1', 'user_ids.*' => 'integer']);
        $added = $this->training->enroll($organization, $request->user(), $course, array_values(array_map('intval', $data['user_ids'])));

        return back()->with('workspace_notice', $added.' participante(s) inscrito(s) correctamente.');
    }

    public function progress(Request $request, int $enrollment): RedirectResponse
    {
        $organization = $this->manage($request);
        $data = $request->validate(['progress' => 'required|integer|min:0|max:100', 'satisfaction' => 'nullable|integer|min:0|max:100']);
        $this->training->updateProgress($organization, $request->user(), $enrollment, (int) $data['progress'], isset($data['satisfaction']) ? (int) $data['satisfaction'] : null);

        return back()->with('workspace_notice', 'Avance actualizado correctamente.');
    }

    public function storeCertification(Request $request): RedirectResponse
    {
        $organization = $this->manage($request);
        $data = $request->validate([
            'user_id' => 'required|integer',
            'name' => 'required|string|max:160',
            'issuer' => 'nullable|string|max:120',
            'issued_on' => 'required|date',
            'expires_on' => 'nullable|date|after_or_equal:issued_on',
        ]);
        abort_unless(DB::table('organization_user')->where('organization_id', $organization->id)->where('user_id', $data['user_id'])->exists(), 422);
        $this->training->createCertification($organization, $request->user(), $data);

        return back()->with('workspace_notice', 'Certificación registrada correctamente.');
    }

    public function storeNeed(Request $request): RedirectResponse
    {
        $organization = $this->manage($request);
        $data = $request->validate([
            'area' => ['required', Rule::in(self::AREAS)],
            'topic' => 'required|string|max:160',
            'priority' => ['required', Rule::in(['high', 'medium', 'low'])],
            'employees' => 'nullable|integer|min:0|max:5000',
        ]);
        $this->training->createNeed($organization, $request->user(), $data);

        return back()->with('workspace_notice', 'Necesidad de capacitación registrada.');
    }

    public function updateNeed(Request $request, int $need): RedirectResponse
    {
        $organization = $this->manage($request);
        $data = $request->validate(['status' => ['required', Rule::in(['pending', 'planned', 'done'])]]);
        $this->training->updateNeed($organization, $request->user(), $need, $data['status']);

        return back()->with('workspace_notice', 'Necesidad actualizada.');
    }

    private function manage(Request $request): Organization
    {
        $organization = $this->context->organization($request->user());
        abort_unless($this->authorization->can($organization, $request->user(), 'training.manage'), 403, 'Solo el Administrador de la firma gestiona la capacitación.');

        return $organization;
    }
}
