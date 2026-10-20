<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\Auth\PasswordHelpService;
use App\Services\Auth\TemporaryPassword;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** "I forgot my password": the person asks, the administrator of their office decides. */
class PasswordHelpController extends Controller
{
    public function __construct(private PasswordHelpService $help, private WorkspaceContext $context, private WorkAuthorizationService $authorization) {}

    /** Public form. The answer is always the same, whether or not the address belongs to an account. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:190'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);
        $this->help->submit((string) $data['name'], (string) $data['email'], (string) $data['reason'], $request->ip());

        return back()->with('status', 'password-help-sent');
    }

    public function index(Request $request): Response
    {
        $organization = $this->organization($request);

        return Inertia::render('work/password-requests', [
            'requests' => $this->help->list($organization, $request->user()),
            'credentials' => TemporaryPassword::open($request->session()->get('password_help_credentials')),
        ]);
    }

    public function approve(Request $request, int $passwordRequest): RedirectResponse
    {
        $organization = $this->organization($request);

        return back()->with('password_help_credentials', TemporaryPassword::seal($this->help->approve($organization, $request->user(), $passwordRequest)));
    }

    public function reject(Request $request, int $passwordRequest): RedirectResponse
    {
        $organization = $this->organization($request);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $this->help->reject($organization, $request->user(), $passwordRequest, $data['note'] ?? null);

        return back()->with('workspace_notice', 'Solicitud rechazada; la persona recibirá un aviso por correo.');
    }

    private function organization(Request $request): Organization
    {
        $organization = $this->context->organization($request->user());
        $this->authorization->ensureDefaults($organization);
        abort_unless($this->authorization->can($organization, $request->user(), 'office.users.manage') || $this->authorization->can($organization, $request->user(), 'office.companies.assign'), 403);

        return $organization;
    }
}
