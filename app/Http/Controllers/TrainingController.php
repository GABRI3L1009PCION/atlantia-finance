<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\WorkManagement\TrainingService;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
            'starts_on' => 'required|date',
            'start_time' => 'nullable|date_format:H:i',
            'duration_hours' => 'nullable|numeric|min:0.5|max:200',
            'capacity' => 'required|integer|min:1|max:5000',
            'session_url' => 'nullable|url:http,https|max:255',
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
            'code' => ['nullable', 'string', 'max:40', Rule::unique('training_certifications', 'code')->where('organization_id', $organization->id)],
            'attachment' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'generated' => 'nullable|boolean',
        ], ['code.unique' => 'Ese código ya está registrado en otra certificación.']);
        abort_unless(DB::table('organization_user')->where('organization_id', $organization->id)->where('user_id', $data['user_id'])->exists(), 422);

        if ($request->hasFile('attachment')) {
            $data['attachment_path'] = $request->file('attachment')->store('training-certificates/'.$organization->id);
            $data['attachment_name'] = $request->file('attachment')->getClientOriginalName();
        }
        $this->training->createCertification($organization, $request->user(), $data);

        return back()->with('workspace_notice', 'Certificación registrada correctamente.');
    }

    /** The certificate issued by another entity that was attached as evidence. */
    public function certificationFile(Request $request, int $certification): StreamedResponse
    {
        $organization = $this->context->organization($request->user());
        abort_unless($this->authorization->can($organization, $request->user(), 'training.view'), 403);
        $row = DB::table('training_certifications')->where('training_certifications.organization_id', $organization->id)->where('id', $certification)->first(['attachment_path', 'attachment_name']);
        abort_unless($row !== null && $row->attachment_path !== null && Storage::exists((string) $row->attachment_path), 404);

        return Storage::download((string) $row->attachment_path, (string) ($row->attachment_name ?? 'certificado'));
    }

    /** Printable certificate generated with the data of the certification. */
    public function certificate(Request $request, int $certification): Response
    {
        $organization = $this->context->organization($request->user());
        abort_unless($this->authorization->can($organization, $request->user(), 'training.view'), 403);
        $row = DB::table('training_certifications')->join('users', 'users.id', '=', 'training_certifications.user_id')
            ->where('training_certifications.organization_id', $organization->id)->where('training_certifications.id', $certification)
            ->first(['training_certifications.name', 'training_certifications.code', 'training_certifications.issuer', 'training_certifications.issued_on', 'training_certifications.generated', 'users.name as user_name']);
        abort_unless($row !== null && $row->generated, 404);

        return Inertia::render('work/certificate', [
            'certificate' => [
                'person' => (string) $row->user_name,
                'course' => (string) $row->name,
                'issuer' => (string) ($row->issuer ?: $organization->name),
                'code' => $row->code === null ? null : (string) $row->code,
                'issued_on' => (string) $row->issued_on,
                'firm' => $organization->name,
            ],
        ]);
    }

    public function storeNeed(Request $request): RedirectResponse
    {
        $organization = $this->manage($request);
        $data = $request->validate([
            'area' => ['required', Rule::in(self::AREAS)],
            'topic' => 'required|string|max:160',
            'priority' => ['required', Rule::in(['high', 'medium', 'low'])],
            'objective' => 'required|string|max:1000',
            'participants' => 'required|array|min:1|max:500',
            'participants.*' => 'integer|distinct',
            'target_date' => 'nullable|date|after_or_equal:today',
        ], ['participants.required' => 'Selecciona al menos un colaborador.', 'participants.min' => 'Selecciona al menos un colaborador.']);
        $members = DB::table('organization_user')->where('organization_id', $organization->id)->where('status', 'active')->whereIn('user_id', $data['participants'])->count();
        abort_unless($members === count($data['participants']), 422, 'Hay colaboradores que no pertenecen a la firma.');
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
