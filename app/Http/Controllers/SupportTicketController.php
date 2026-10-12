<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Platform\SupportTicketService;
use App\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Support of Atlantia Systems from the client organization side: open tickets, follow the conversation (never the
 * staff's internal notes) and answer.
 */
class SupportTicketController extends Controller
{
    public function __construct(private WorkspaceContext $workspace, private SupportTicketService $tickets) {}

    public function index(Request $request): Response
    {
        $organization = $this->workspace->organization($request->user());
        $selected = $request->integer('ticket') ?: null;

        return Inertia::render('support/index', [
            'tickets' => $this->tickets->forOrganization((int) $organization->id),
            'selected' => $selected === null ? null : $this->tickets->detail($selected, false, (int) $organization->id),
            'modules' => SupportTicketService::MODULES,
            'priorities' => array_intersect_key(SupportTicketService::PRIORITY_LABELS, array_flip(['low', 'normal', 'high', 'urgent'])),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $organization = $this->workspace->organization($request->user());
        $data = $request->validate(['subject' => 'required|string|max:200', 'description' => 'required|string|max:5000', 'module' => ['required', Rule::in(array_keys(SupportTicketService::MODULES))],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])]]);
        $id = $this->tickets->open($organization, $request->user(), (string) $data['subject'], (string) $data['description'], (string) $data['module'], (string) $data['priority']);

        return to_route('support.index', ['ticket' => $id])->with('workspace_notice', 'Recibimos tu solicitud. Te responderemos por aquí.');
    }

    public function reply(Request $request, int $ticket): RedirectResponse
    {
        $organization = $this->workspace->organization($request->user());
        $this->tickets->replyAsCustomer($organization, $request->user(), $ticket, (string) $request->validate(['body' => 'required|string|max:5000'])['body']);

        return back()->with('workspace_notice', 'Mensaje enviado al equipo de soporte.');
    }
}
