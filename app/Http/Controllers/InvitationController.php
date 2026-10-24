<?php

namespace App\Http\Controllers;

use App\Concerns\PasswordValidationRules;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\WorkspaceInvitation;
use App\Services\Platform\LegalDocumentService;
use App\Services\WorkManagement\OfficeInvitationService;
use App\Services\WorkspaceContext;
use App\Support\PlatformStatus;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class InvitationController extends Controller
{
    use PasswordValidationRules;

    /** Reserves one seat and records a hashed, expiring invitation; no roles supplied by the browser are trusted. */
    public function store(Request $request, WorkspaceContext $context): RedirectResponse
    {
        $organization = $context->organization($request->user(), true);
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate(['email' => ['required', 'email', 'max:255']], ['email.email' => 'Escribe un correo válido.', 'email.required' => 'Escribe el correo del colaborador.']);
        $token = Str::random(64);
        $invitation = DB::transaction(function () use ($organization, $context, $request, $data, $token): Invitation {
            $organization = Organization::query()->lockForUpdate()->findOrFail($organization->id);
            $office = $organization->offices()->where('is_primary', true)->where('status', 'active')->first();
            abort_if($office === null, 409, 'Completa primero la configuración de la oficina.');
            $pending = $organization->invitations()->where('status', 'pending')->where('expires_at', '>', now());
            if ($organization->users()->whereRaw('lower(email) = ?', [$data['email']])->exists() || (clone $pending)->where('email', $data['email'])->exists()) {
                throw ValidationException::withMessages(['email' => 'Este correo ya pertenece al equipo o tiene una invitación vigente.']);
            }
            if ($organization->users()->wherePivot('status', 'active')->count() + $pending->count() >= $context->limit($organization, 'users')) {
                throw ValidationException::withMessages(['email' => 'No hay cupos disponibles. Las invitaciones pendientes también reservan un cupo.']);
            }
            $invitation = $organization->invitations()->create([
                'public_id' => (string) Str::uuid(), 'invited_by' => $request->user()->id,
                'email' => $data['email'], 'role' => 'member', 'token_hash' => hash('sha256', $token),
                'status' => 'pending', 'expires_at' => now()->addDays(3),
            ]);
            $invitation->offices()->attach($office->id);

            return $invitation;
        });
        $url = URL::temporarySignedRoute('invitations.show', $invitation->expires_at, ['invitation' => $invitation->public_id, 'token' => $token]);
        try {
            Notification::route('mail', $invitation->email)->notify(new WorkspaceInvitation($organization->name, $url, $invitation->expires_at));
        } catch (\Throwable $exception) {
            $invitation->update(['status' => 'canceled']);
            report($exception);
            throw ValidationException::withMessages(['email' => 'No pudimos enviar el correo. El cupo quedó libre; intenta nuevamente.']);
        }

        return to_route('dashboard')->with('workspace_notice', 'Invitación enviada. El enlace vence en 72 horas.');
    }

    public function destroy(Request $request, string $invitation, WorkspaceContext $context): RedirectResponse
    {
        $organization = $context->organization($request->user(), true);
        DB::transaction(function () use ($organization, $invitation): void {
            Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $record = $organization->invitations()->where('public_id', $invitation)->firstOrFail();
            if ($record->status === 'pending') {
                $record->update(['status' => 'canceled']);
            }
        });

        return to_route('dashboard')->with('workspace_notice', 'Invitación revocada.');
    }

    private function validInvitation(Request $request, string $id): Invitation
    {
        $invitation = Invitation::query()->where('public_id', $id)->firstOrFail();
        abort_unless(hash_equals($invitation->token_hash, hash('sha256', (string) $request->query('token'))), 403);
        abort_unless($invitation->status === 'pending' && $invitation->expires_at->isFuture(), 410, 'Esta invitación ya no está disponible. Solicita una nueva al propietario.');

        return $invitation;
    }

    public function show(Request $request, string $invitation): Response
    {
        $record = $this->validInvitation($request, $invitation);
        $existing = User::query()->whereRaw('lower(email) = ?', [$record->email])->exists();
        if ($existing && ! $request->user()) {
            $request->session()->put('url.intended', $request->fullUrl());
        }
        abort_if($request->user() && Str::lower($request->user()->email) !== $record->email, 403, 'Inicia sesión con el correo invitado.');

        return Inertia::render('auth/accept-invitation', [
            'organizationName' => $record->organization->name, 'email' => $record->email,
            'existingAccount' => $existing, 'signedIn' => (bool) $request->user(), 'acceptUrl' => $request->fullUrl(),
            'asOwner' => $record->role === 'owner',
        ]);
    }

    /** Consumes the invitation once and attaches member access only to the reserved organization and offices. */
    public function accept(Request $request, string $invitation, WorkspaceContext $context): RedirectResponse
    {
        $record = $this->validInvitation($request, $invitation);
        $existing = User::query()->whereRaw('lower(email) = ?', [$record->email])->first();
        abort_if($existing && (! $request->user() || $request->user()->id !== $existing->id), 403, 'Inicia sesión con el correo invitado.');
        abort_if($request->user() && Str::lower($request->user()->email) !== $record->email, 403);
        $data = $existing ? [] : $request->validate([
            'name' => ['required', 'string', 'max:255'], 'password' => $this->passwordRules(), 'terms' => ['accepted'],
        ]);
        DB::transaction(function () use ($record, $context, $existing, $data): void {
            $organization = Organization::query()->lockForUpdate()->findOrFail($record->organization_id);
            $record = Invitation::query()->lockForUpdate()->findOrFail($record->id);
            abort_unless($record->status === 'pending' && $record->expires_at->isFuture(), 410);
            abort_unless(in_array($organization->status, PlatformStatus::ORGANIZATION_ACCESS, true) && $organization->subscriptions()->whereIn('status', PlatformStatus::SUBSCRIPTION_ACCESS)->exists(), 403);
            $seats = app(OfficeInvitationService::class);
            if (! $seats->isClientInvitation($record) && $seats->seatsInUse($organization) > $context->limit($organization, 'users')) {
                throw ValidationException::withMessages(['invitation' => 'El plan ya no tiene cupos suficientes. Contacta al propietario.']);
            }
            abort_if($existing && $organization->users()->whereKey($existing->id)->exists(), 409);
            $user = $existing ?? User::query()->create(['name' => $data['name'], 'email' => $record->email, 'password' => $data['password']]);
            if ($existing === null) {
                app(LegalDocumentService::class)->acceptCurrent($user, 'invitation', request()->ip(), request()->userAgent());
            }
            if (! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
                event(new Verified($user));
            }
            // The initial owner of an organization onboarded from the Control Center is invited with the owner role.
            $isOwner = $record->role === 'owner';
            $organization->users()->attach($user->id, ['role' => $isOwner ? 'owner' : 'member', 'status' => 'active', 'joined_at' => now(), 'invited_by' => $record->invited_by]);
            foreach ($record->offices()->where('offices.organization_id', $organization->id)->where('status', 'active')->get() as $office) {
                $office->users()->syncWithoutDetaching([$user->id => ['role' => $isOwner ? 'manager' : 'member', 'status' => 'active', 'assigned_at' => now()]]);
            }
            app(OfficeInvitationService::class)->applyAcceptance($organization, $record, $user);
            $record->update(['status' => 'accepted', 'accepted_at' => now()]);
        });

        if ($request->user()) {
            // An existing user who joins another firm lands directly in it; the switcher lets them go back.
            $request->user()->forceFill(['current_organization_id' => $record->organization_id])->save();

            return to_route('dashboard');
        }

        return to_route('login')->with('status', 'Invitación aceptada. Inicia sesión para acceder a tu equipo.');
    }
}
