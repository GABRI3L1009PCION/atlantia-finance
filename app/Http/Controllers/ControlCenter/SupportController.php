<?php

declare(strict_types=1);

namespace App\Http\Controllers\ControlCenter;

use App\Services\Platform\IncidentManagementService;
use App\Services\Platform\PlatformHealthService;
use App\Services\Platform\SupportTicketService;
use App\Support\PlatformIssuer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Response;

/** Support tickets and platform incidents. */
class SupportController extends ControlCenterController
{
    public function tickets(Request $request, SupportTicketService $tickets): Response
    {
        $user = $this->staff($request, 'saas.tickets.view');
        $filters = $request->validate(['status' => ['nullable', Rule::in(array_keys(SupportTicketService::STATUS_LABELS))], 'priority' => ['nullable', Rule::in(array_keys(SupportTicketService::PRIORITY_LABELS))],
            'organization_id' => 'nullable|integer', 'assigned_to' => 'nullable|integer']);
        $selected = $request->integer('ticket') ?: null;

        return $this->render($request, 'tickets', [
            'tickets' => $tickets->list($filters), 'filters' => $filters, 'selected' => $selected === null ? null : $tickets->detail($selected, true),
            'statuses' => SupportTicketService::STATUS_LABELS, 'priorities' => SupportTicketService::PRIORITY_LABELS,
            'staff' => DB::table('platform_staff')->join('users', 'users.id', '=', 'platform_staff.user_id')->where('platform_staff.status', 'active')->orderBy('users.name')->get(['users.id', 'users.name'])->all(),
            'organizations' => DB::table('organizations')->where('organizations.id', '<>', PlatformIssuer::organizationId())->whereNull('deleted_at')->orderBy('name')->get(['id', 'name'])->all(),
            'can_manage' => $this->authorization->can($user, 'saas.tickets.manage'), 'can_request_access' => $this->authorization->can($user, 'saas.support_access.request'),
        ]);
    }

    public function replyTicket(Request $request, int $ticket, SupportTicketService $tickets): RedirectResponse
    {
        $user = $this->staff($request, 'saas.tickets.manage');
        $data = $request->validate(['body' => 'required|string|max:5000', 'internal' => 'boolean', 'status' => ['nullable', Rule::in(array_keys(SupportTicketService::STATUS_LABELS))]]);
        $tickets->replyAsStaff($user, $ticket, (string) $data['body'], (bool) ($data['internal'] ?? false), $data['status'] ?? null);

        return back()->with('workspace_notice', ($data['internal'] ?? false) ? 'Nota interna agregada.' : 'Respuesta enviada al cliente.');
    }

    public function updateTicket(Request $request, int $ticket, SupportTicketService $tickets): RedirectResponse
    {
        $user = $this->staff($request, 'saas.tickets.manage');
        $data = $request->validate(['status' => ['nullable', Rule::in(array_keys(SupportTicketService::STATUS_LABELS))], 'priority' => ['nullable', Rule::in(array_keys(SupportTicketService::PRIORITY_LABELS))], 'assigned_to' => 'nullable|integer']);
        $tickets->update($user, $ticket, $data['status'] ?? null, $data['priority'] ?? null, isset($data['assigned_to']) ? (int) $data['assigned_to'] : null);

        return back()->with('workspace_notice', 'Ticket actualizado.');
    }

    public function incidents(Request $request, IncidentManagementService $incidents): Response
    {
        $user = $this->staff($request, 'saas.incidents.view');
        $selected = $request->integer('incident') ?: null;

        return $this->render($request, 'incidents', [
            'incidents' => $incidents->list(), 'selected' => $selected === null ? null : $incidents->detail($selected),
            'statuses' => IncidentManagementService::STATUS_LABELS, 'severities' => IncidentManagementService::SEVERITY_LABELS,
            'services' => array_map(fn (array $service): string => $service['name'], PlatformHealthService::SERVICES),
            'organizations' => DB::table('organizations')->where('organizations.id', '<>', PlatformIssuer::organizationId())->whereNull('deleted_at')->orderBy('name')->get(['id', 'name'])->all(),
            'plans' => DB::table('plans')->orderBy('display_order')->get(['id', 'name'])->all(),
            'can_manage' => $this->authorization->can($user, 'saas.incidents.manage'),
        ]);
    }

    public function storeIncident(Request $request, IncidentManagementService $incidents): RedirectResponse
    {
        $user = $this->staff($request, 'saas.incidents.manage');
        $data = $request->validate(['title' => 'required|string|max:200', 'description' => 'required|string|max:5000', 'severity' => ['required', Rule::in(array_keys(IncidentManagementService::SEVERITY_LABELS))],
            'affected_services' => 'required|array|min:1', 'affected_services.*' => ['string', Rule::in(array_keys(PlatformHealthService::SERVICES))],
            'audience' => ['required', Rule::in(['all', 'affected', 'plan', 'none'])], 'plan_id' => 'nullable|required_if:audience,plan|integer',
            'organization_ids' => 'array', 'organization_ids.*' => 'integer', 'started_at' => 'nullable|date|before_or_equal:now']);
        $organizations = array_values(array_map('intval', $data['organization_ids'] ?? []));
        if ($data['audience'] === 'plan') {
            $organizations = array_values(array_map('intval', DB::table('subscriptions')->join('subscription_items', 'subscription_items.subscription_id', '=', 'subscriptions.id')
                ->join('plan_prices', 'plan_prices.id', '=', 'subscription_items.plan_price_id')->where('plan_prices.plan_id', $data['plan_id'])
                ->whereIn('subscriptions.status', ['active', 'trialing', 'past_due', 'grace_period'])->distinct()->pluck('subscriptions.organization_id')->all()));
        }
        $id = $incidents->create($user, (string) $data['title'], (string) $data['description'], (string) $data['severity'], array_values($data['affected_services']),
            $data['audience'] === 'plan' ? 'affected' : (string) $data['audience'], $organizations, $data['started_at'] ?? null);

        return to_route('controlCenter.incidents', ['incident' => $id])->with('workspace_notice', 'Incidente registrado. Publica una actualización para comunicarlo a los clientes.');
    }

    public function updateIncident(Request $request, int $incident, IncidentManagementService $incidents): RedirectResponse
    {
        $user = $this->staff($request, 'saas.incidents.manage');
        if ($request->has('postmortem')) {
            $incidents->postmortem($user, $incident, (string) $request->validate(['postmortem' => 'required|string|min:20|max:20000'])['postmortem']);

            return back()->with('workspace_notice', 'Postmortem registrado.');
        }
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(IncidentManagementService::STATUS_LABELS))], 'message' => 'required|string|max:5000', 'publish' => 'boolean']);
        $incidents->update($user, $incident, (string) $data['status'], (string) $data['message'], (bool) ($data['publish'] ?? false));

        return back()->with('workspace_notice', ($data['publish'] ?? false) ? 'Actualización publicada a los clientes.' : 'Actualización interna registrada.');
    }
}
