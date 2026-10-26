<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\Notifications\ClientMailIdentityService;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkspaceContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** The firm's address for its clients' replies: saved, confirmed by a link, tested and removed. */
class ClientEmailController extends Controller
{
    public function __construct(
        private WorkspaceContext $context,
        private WorkAuthorizationService $authorization,
        private ClientMailIdentityService $identities,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $organization = $this->manage($request);
        $data = $request->validate(['email' => 'required|email:rfc|max:190']);
        $this->identities->requestVerification($organization, $request->user(), $data['email']);

        return back()->with('workspace_notice', 'Te enviamos un enlace a '.mb_strtolower(trim($data['email'])).'. Ábrelo para confirmar el correo.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->identities->remove($this->manage($request), $request->user());

        return back()->with('workspace_notice', 'Correo de la firma quitado. Las respuestas llegarán a quien envíe cada mensaje.');
    }

    public function test(Request $request): RedirectResponse
    {
        $email = $this->identities->sendTest($this->manage($request), $request->user());

        return back()->with('workspace_notice', 'Enviamos un correo de prueba a '.$email.'. Así lo verán tus clientes.');
    }

    /** GET client-email/verify/{organization}/{hash}: the signed link of the confirmation e-mail; it works without signing in. */
    public function verify(int $organization, string $hash): View
    {
        return view('client-email-verified', ['verified' => $this->identities->verify($organization, $hash)]);
    }

    private function manage(Request $request): Organization
    {
        $organization = $this->context->organization($request->user());
        abort_unless($this->authorization->can($organization, $request->user(), 'office.settings.manage'), 403, 'Solo el administrador de la firma configura este correo.');

        return $organization;
    }
}
