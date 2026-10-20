<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\Auth\TemporaryPassword;
use App\Services\ClientPortal\ClientAccessAdminService;
use App\Services\ClientPortal\ClientAccessService;
use App\Services\WorkManagement\OfficeInvitationService;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Office administration of who enters the client portal: invitations, roles, revocation, activity and two-step policy. */
class ClientAccessAdminController extends Controller
{
    public function __construct(
        private WorkspaceContext $context,
        private WorkAuthorizationService $authorization,
        private ClientAccessAdminService $admin,
        private OfficeInvitationService $invitations,
    ) {}

    public function index(Request $request): Response
    {
        $organization = $this->organization($request);

        return Inertia::render('work/client-access', [
            ...$this->admin->overview($organization, $request->user(), $request->integer('office_id') ?: null),
            'credentials' => TemporaryPassword::open($request->session()->get('client_credentials')),
        ]);
    }

    /** Creates the client's account with a temporary password; the password is shown once to the administrator. */
    public function store(Request $request): RedirectResponse
    {
        $organization = $this->organization($request);
        $data = $request->validate(['name' => 'required|string|max:120', 'email' => 'required|email:rfc|max:190', 'company_id' => 'required|integer',
            'contact_role' => ['required', Rule::in(array_keys(ClientAccessService::CONTACT_ROLES))]]);
        $result = $this->admin->createContact($organization, $request->user(), ['name' => (string) $data['name'], 'email' => (string) $data['email'], 'company_id' => (int) $data['company_id'], 'contact_role' => (string) $data['contact_role']]);

        if ($result['linked']) {
            return back()->with('workspace_notice', 'Ese cliente ya tenía cuenta; ahora también tiene acceso a esta empresa.');
        }

        return back()->with('client_credentials', TemporaryPassword::seal(['name' => (string) $data['name'], 'email' => $result['email'], 'password' => (string) $result['temporary_password']]));
    }

    public function resetPassword(Request $request, int $access): RedirectResponse
    {
        $organization = $this->organization($request);
        $password = $this->admin->resetTemporaryPassword($organization, $request->user(), $access);
        $contact = DB::table('client_company_access')->join('users', 'users.id', '=', 'client_company_access.user_id')->where('client_company_access.id', $access)->first(['users.name', 'users.email']);

        return back()->with('client_credentials', TemporaryPassword::seal(['name' => (string) ($contact->name ?? ''), 'email' => (string) ($contact->email ?? ''), 'password' => $password]));
    }

    public function invite(Request $request): RedirectResponse
    {
        $organization = $this->organization($request);
        $data = $request->validate(['name' => 'required|string|max:120', 'email' => 'required|email:rfc|max:190', 'company_id' => 'required|integer',
            'contact_role' => ['required', Rule::in(array_keys(ClientAccessService::CONTACT_ROLES))]]);
        $result = $this->invitations->inviteClient($organization, $request->user(), ['name' => (string) $data['name'], 'email' => (string) $data['email'], 'company_id' => (int) $data['company_id'], 'contact_role' => (string) $data['contact_role']]);

        return back()->with('workspace_notice', $result['status'] === 'linked' ? 'El contacto ya existía y ahora tiene acceso a la empresa.' : 'Invitación enviada. El enlace vence en 7 días.');
    }

    public function resend(Request $request, string $invitation): RedirectResponse
    {
        $this->invitations->resend($this->organization($request), $request->user(), $invitation);

        return back()->with('workspace_notice', 'Invitación reenviada con un enlace nuevo.');
    }

    public function cancel(Request $request, string $invitation): RedirectResponse
    {
        $this->invitations->cancel($this->organization($request), $request->user(), $invitation);

        return back()->with('workspace_notice', 'Invitación cancelada.');
    }

    public function role(Request $request, int $access): RedirectResponse
    {
        $data = $request->validate(['contact_role' => ['required', Rule::in(array_keys(ClientAccessService::CONTACT_ROLES))]]);
        $this->admin->changeRole($this->organization($request), $request->user(), $access, (string) $data['contact_role']);

        return back()->with('workspace_notice', 'Rol del contacto actualizado.');
    }

    public function status(Request $request, int $access, string $status): RedirectResponse
    {
        $this->admin->setStatus($this->organization($request), $request->user(), $access, $status === 'revoke' ? 'revoked' : 'active');

        return back()->with('workspace_notice', $status === 'revoke' ? 'Acceso revocado; el contacto ya no puede entrar a esta empresa.' : 'Acceso restablecido.');
    }

    public function activity(Request $request, int $access): JsonResponse
    {
        return response()->json(['activity' => $this->admin->activity($this->organization($request), $request->user(), $access)]);
    }

    public function policy(Request $request, int $office): RedirectResponse
    {
        $data = $request->validate(['required' => 'required|boolean']);
        $this->admin->setTwoFactorPolicy($this->organization($request), $request->user(), $office, (bool) $data['required']);

        return back()->with('workspace_notice', $data['required'] ? 'Los contactos de clientes de esta oficina deberán activar la verificación en dos pasos.' : 'Ya no se exige la verificación en dos pasos a los contactos de clientes.');
    }

    private function organization(Request $request): Organization
    {
        $organization = $this->context->organization($request->user());
        $this->authorization->ensureDefaults($organization);
        abort_unless($this->authorization->can($organization, $request->user(), 'office.companies.assign'), 403);

        return $organization;
    }
}
