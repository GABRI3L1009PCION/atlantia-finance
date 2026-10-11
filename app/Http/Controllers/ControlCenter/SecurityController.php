<?php

declare(strict_types=1);

namespace App\Http\Controllers\ControlCenter;

use App\Services\Platform\GlobalConfigurationService;
use App\Services\Platform\SecurityCenterService;
use App\Services\Platform\SupportAccessService;
use App\Services\Platform\SupportViewerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Response;

/** Security center, support accesses and the support viewer. */
class SecurityController extends ControlCenterController
{
    public function index(Request $request, SecurityCenterService $security): Response
    {
        $user = $this->staff($request, 'saas.security.view');

        return $this->render($request, 'security', [
            'overview' => $security->overview(), 'events' => $security->events(), 'sessions' => $security->sessions(), 'failed_logins' => $security->failedLogins(),
            'can_manage' => $this->authorization->can($user, 'saas.security.manage'),
        ]);
    }

    public function revokeSession(Request $request, SecurityCenterService $security): RedirectResponse
    {
        $user = $this->staff($request, 'saas.security.manage');
        $reason = $this->confirmSensitive($request, $user);
        $data = $request->validate(['session' => 'nullable|string|size:16', 'user_id' => 'nullable|integer']);
        abort_if(empty($data['session']) && empty($data['user_id']), 422);
        $count = $security->revoke($user, $data['session'] ?? null, isset($data['user_id']) ? (int) $data['user_id'] : null, $reason);

        return back()->with('workspace_notice', $count.' sesión(es) revocada(s).');
    }

    public function supportAccess(Request $request, SupportAccessService $access): Response
    {
        $user = $this->staff($request, 'saas.dashboard.view');
        abort_unless($this->authorization->can($user, 'saas.support_access.request') || $this->authorization->can($user, 'saas.support_access.approve'), 403);

        return $this->render($request, 'support-access', [
            'requests' => $access->list(), 'scopes' => SupportAccessService::SCOPES, 'statuses' => SupportAccessService::STATUS_LABELS,
            'organizations' => DB::table('organizations')->whereNull('deleted_at')->orderBy('name')->get(['id', 'name'])->all(),
            'tickets' => DB::table('support_tickets')->whereNotIn('status', ['closed'])->orderByDesc('id')->limit(100)->get(['id', 'code', 'subject', 'organization_id'])->all(),
            'max_minutes' => (int) app(GlobalConfigurationService::class)->get('support.max_access_minutes', 120),
            'can' => ['request' => $this->authorization->can($user, 'saas.support_access.request'), 'approve' => $this->authorization->can($user, 'saas.support_access.approve')],
            'current_user_id' => $user->id,
        ]);
    }

    public function requestAccess(Request $request, SupportAccessService $access): RedirectResponse
    {
        $user = $this->staff($request, 'saas.support_access.request');
        $data = $request->validate(['organization_id' => 'required|integer', 'support_ticket_id' => 'nullable|integer', 'reason' => 'required|string|min:10|max:1000',
            'scopes' => 'required|array|min:1', 'scopes.*' => ['string', Rule::in(array_keys(SupportAccessService::SCOPES))], 'duration_minutes' => 'required|integer|min:5|max:480']);
        $access->request($user, (int) $data['organization_id'], isset($data['support_ticket_id']) ? (int) $data['support_ticket_id'] : null, (string) $data['reason'], array_values($data['scopes']), (int) $data['duration_minutes']);

        return back()->with('workspace_notice', 'Solicitud de acceso enviada. Otra persona debe aprobarla.');
    }

    public function accessAction(Request $request, int $access, string $action, SupportAccessService $service): RedirectResponse
    {
        if (in_array($action, ['approve', 'reject'], true)) {
            $user = $this->staff($request, 'saas.support_access.approve');
            $service->decide($user, $access, $action === 'approve', $this->confirmSensitive($request, $user));

            return back()->with('workspace_notice', $action === 'approve' ? 'Acceso aprobado. Quien lo solicitó puede iniciarlo.' : 'Acceso rechazado.');
        }
        if ($action === 'start') {
            $user = $this->staff($request, 'saas.support_access.request');
            $this->confirmSensitive($request, $user, false);
            $session = $service->start($user, $access);

            return to_route('controlCenter.supportAccess.view', [$access, ((array) json_decode((string) $session->scopes, true))[0] ?? 'structure'])->with('workspace_notice', 'Modo soporte iniciado. Todo lo que consultes queda auditado.');
        }
        if ($action === 'revoke') {
            $user = $this->staff($request, 'saas.dashboard.view');
            $own = (int) DB::table('support_access_requests')->where('id', $access)->value('requested_by') === $user->id;
            abort_unless($own || $this->authorization->can($user, 'saas.support_access.approve'), 403);
            $service->revoke($user, $access, $this->reason($request));

            return to_route('controlCenter.supportAccess')->with('workspace_notice', 'Acceso de soporte terminado.');
        }
        abort(404);
    }

    public function view(Request $request, int $access, string $scope, SupportViewerService $viewer, SupportAccessService $service): Response
    {
        $user = $this->staff($request, 'saas.support_access.request');
        $data = $viewer->view($user, $access, $scope);
        $session = $service->session($user, $access);
        $organization = DB::table('organizations')->where('id', $session->organization_id)->first(['id', 'name']);

        return $this->render($request, 'support-viewer', [
            'session' => ['id' => (int) $session->id, 'organization' => (string) $organization?->name, 'organization_id' => (int) $session->organization_id, 'reason' => (string) $session->reason,
                'ticket' => $session->support_ticket_id === null ? null : DB::table('support_tickets')->where('id', $session->support_ticket_id)->value('code'),
                'scopes' => (array) json_decode((string) $session->scopes, true), 'expires_at' => (string) $session->expires_at],
            'scope' => $scope, 'scope_labels' => SupportAccessService::SCOPES, 'data' => $data,
        ]);
    }
}
