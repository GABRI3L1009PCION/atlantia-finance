<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\User;
use App\Services\Operations\CommunicationService;
use App\Services\Operations\SupervisorService;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Message center. Everyone reads and answers their own conversations; people with the communications permission can
 * write to the team and clients of the companies they supervise.
 */
class CommunicationController extends Controller
{
    public function __construct(
        private WorkspaceContext $context,
        private WorkAuthorizationService $authorization,
        private CommunicationService $communications,
    ) {}

    public function index(Request $request, SupervisorService $supervisor): Response
    {
        $organization = $this->context->organization($request->user());
        $canSend = $this->authorization->can($organization, $request->user(), 'communications.send');
        $companyIds = $canSend ? $this->companyIds($organization, $request->user(), $supervisor) : [];

        if ($request->integer('message') > 0) {
            $this->communications->markRead($organization->id, $request->user(), $request->integer('message'));
        }

        return Inertia::render('communication/index', [
            'messages' => $this->communications->history($organization->id, $request->user(), $companyIds),
            'recipients' => $canSend ? $this->communications->recipients($organization, $request->user(), $companyIds) : [],
            'companies' => DB::table('companies')->whereIn('id', $companyIds === [] ? [-1] : $companyIds)->orderBy('name')->get(['id', 'name'])->map(fn (object $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name])->all(),
            'types' => CommunicationService::TYPES,
            'canSend' => $canSend,
            'focus' => $request->integer('message') ?: null,
        ]);
    }

    public function store(Request $request, SupervisorService $supervisor): RedirectResponse
    {
        $organization = $this->context->organization($request->user());
        $data = $request->validate([
            'recipient_id' => 'required_without:parent_id|nullable|integer',
            'company_id' => 'nullable|integer',
            'parent_id' => 'nullable|integer',
            'type' => ['required', Rule::in(array_keys(CommunicationService::TYPES))],
            'subject' => 'required|string|max:180',
            'body' => 'required|string|max:5000',
            'due_at' => 'nullable|date',
        ], ['body.required' => 'Escribe el mensaje.', 'subject.required' => 'Escribe un asunto.']);
        $isReply = ! empty($data['parent_id']);
        abort_unless($isReply || $this->authorization->can($organization, $request->user(), 'communications.send'), 403);
        $companyIds = $this->companyIds($organization, $request->user(), $supervisor);

        $this->communications->send($organization, $request->user(), [
            'recipient_id' => (int) ($data['recipient_id'] ?? 0),
            'company_id' => isset($data['company_id']) ? (int) $data['company_id'] : null,
            'parent_id' => $isReply ? (int) $data['parent_id'] : null,
            'type' => $data['type'],
            'subject' => $data['subject'],
            'body' => $data['body'],
            'due_at' => $data['due_at'] ?? null,
        ], $companyIds);

        return back()->with('workspace_notice', 'Mensaje enviado; queda en el historial.');
    }

    /**
     * Companies the user may write about: the supervised ones, otherwise those with an active assignment.
     *
     * @return list<int>
     */
    private function companyIds(Organization $organization, User $user, SupervisorService $supervisor): array
    {
        if ($this->authorization->can($organization, $user, 'supervision.dashboard.view')) {
            return $supervisor->companyIds($organization, $user);
        }

        return array_values(array_unique(array_map('intval', DB::table('company_assignments')->where('company_assignments.organization_id', $organization->id)
            ->where('user_id', $user->id)->where('status', 'active')->whereNull('deleted_at')->pluck('company_id')->all())));
    }

    public function read(Request $request, int $message): RedirectResponse
    {
        $organization = $this->context->organization($request->user());
        $this->communications->markRead($organization->id, $request->user(), $message);

        return back();
    }
}
