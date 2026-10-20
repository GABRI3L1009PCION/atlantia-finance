<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\Organization;
use App\Models\User;
use App\Notifications\PasswordHelpResolved;
use App\Services\AccountingService;
use App\Services\WorkManagement\WorkAuthorizationService;
use App\Services\WorkManagement\WorkNotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use stdClass;

/**
 * Forgotten passwords go through the administrator of the person's office instead of an automatic link: anyone could type
 * somebody else's address. The administrator decides; on approval a temporary password is issued and sent to the person.
 * Owners of a firm and platform staff have no administrator above them and keep the emailed reset link.
 */
class PasswordHelpService
{
    private const CLIENT_PERMISSION = 'office.companies.assign';

    private const MEMBER_PERMISSION = 'office.users.manage';

    public function __construct(
        private WorkAuthorizationService $authorization,
        private WorkNotificationService $notifications,
        private AccountingService $accounting,
    ) {}

    /** Registers the request. Always silent about whether the address exists, so the form cannot be used to probe accounts. */
    public function submit(string $claimedName, string $email, string $reason, ?string $ip): void
    {
        $email = Str::lower(trim($email));
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();
        if ($user === null) {
            return;
        }

        $membership = $this->membership($user);
        $firms = DB::table('organization_user')->where('user_id', $user->id)->where('status', 'active')->count();
        if ($membership === null || $firms > 1 || DB::table('platform_staff')->where('user_id', $user->id)->exists() || $membership->role === 'owner') {
            Password::broker()->sendResetLink(['email' => $user->email]);

            return;
        }

        $organization = Organization::query()->findOrFail((int) $membership->organization_id);
        $kind = $this->isClient($organization, $user) ? 'client' : 'member';
        $officeId = $this->officeOf($organization, $user, $kind);
        if (DB::table('password_reset_requests')->where('user_id', $user->id)->where('status', 'pending')->where('created_at', '>', now()->subDay())->exists()) {
            return;
        }

        $id = (int) DB::table('password_reset_requests')->insertGetId([
            'organization_id' => $organization->id, 'office_id' => $officeId, 'user_id' => $user->id, 'kind' => $kind, 'claimed_name' => mb_substr(trim($claimedName), 0, 190), 'email' => $email,
            'reason' => trim($reason), 'status' => 'pending', 'ip_address' => $ip, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->accounting->audit($organization, $user, 'password_help.requested', 'password_reset_requests', $id, ['kind' => $kind], $officeId);

        foreach ($this->recipients($organization, $kind, $officeId) as $recipientId) {
            $this->notifications->notify($organization->id, $recipientId, $user->id, 'password_help.requested', 'Solicitud de cambio de contraseña de '.$user->name, mb_substr(trim($reason), 0, 200), 'password_reset_requests', $id);
        }
    }

    /**
     * Requests the administrator may decide, newest pending first.
     *
     * @return list<array<string, mixed>>
     */
    public function list(Organization $organization, User $admin): array
    {
        return array_values(DB::table('password_reset_requests')->join('users', 'users.id', '=', 'password_reset_requests.user_id')->leftJoin('offices', 'offices.id', '=', 'password_reset_requests.office_id')
            ->where('password_reset_requests.organization_id', $organization->id)->orderByRaw("case when password_reset_requests.status = 'pending' then 0 else 1 end")->orderByDesc('password_reset_requests.id')->limit(200)
            ->get(['password_reset_requests.*', 'users.name as account_name', 'offices.name as office_name'])
            ->filter(fn (stdClass $row): bool => $this->canDecide($organization, $admin, $row))
            ->map(fn (stdClass $row): array => [
                'id' => (int) $row->id, 'kind' => (string) $row->kind, 'account_name' => (string) $row->account_name, 'claimed_name' => (string) $row->claimed_name, 'email' => (string) $row->email,
                'reason' => (string) $row->reason, 'status' => (string) $row->status, 'office' => $row->office_name === null ? null : (string) $row->office_name, 'ip' => $row->ip_address,
                'note' => $row->review_note, 'requested_at' => Carbon::parse($row->created_at)->toIso8601String(),
                'name_matches' => $this->normalize((string) $row->claimed_name) === $this->normalize((string) $row->account_name),
            ])->values()->all());
    }

    public function pendingCount(Organization $organization, User $admin): int
    {
        return count(array_filter($this->list($organization, $admin), fn (array $row): bool => $row['status'] === 'pending'));
    }

    /**
     * The approver sees the temporary password only for client accounts. A team member receives it solely by email, so an
     * administrator can never sign in as a colleague.
     *
     * @return array{name: string, email: string, password: string}
     */
    public function approve(Organization $organization, User $admin, int $requestId): array
    {
        $request = $this->pending($organization, $admin, $requestId);
        $user = User::query()->findOrFail((int) $request->user_id);
        TemporaryPassword::assertIssuableBy($organization, $user);
        $this->assertNotMoreprivileged($organization, $admin, $user);
        $password = TemporaryPassword::issue($user);
        DB::table('password_reset_requests')->where('id', $requestId)->update(['status' => 'approved', 'reviewed_by' => $admin->id, 'reviewed_at' => now(), 'updated_at' => now()]);
        $this->accounting->audit($organization, $admin, 'password_help.approved', 'password_reset_requests', $requestId, ['user_id' => $user->id], $request->office_id === null ? null : (int) $request->office_id);
        Notification::route('mail', $user->email)->notify(new PasswordHelpResolved(true, $this->officeName($request), $password));

        return ['name' => (string) $user->name, 'email' => (string) $user->email, 'password' => $request->kind === 'client' ? $password : ''];
    }

    /** Whoever lacks the right to assign high-privilege roles cannot reset the password of an account that holds one. */
    private function assertNotMoreprivileged(Organization $organization, User $admin, User $target): void
    {
        $codes = DB::table('user_role_assignments')->join('workspace_roles', 'workspace_roles.id', '=', 'user_role_assignments.workspace_role_id')->where('user_role_assignments.organization_id', $organization->id)
            ->where('user_role_assignments.user_id', $target->id)->where('user_role_assignments.status', 'active')->whereNull('user_role_assignments.deleted_at')->pluck('workspace_roles.code');
        $holdsHigh = $codes->contains(fn ($code): bool => $this->authorization->isHighPrivilegeRole($organization, (string) $code));
        abort_if($holdsHigh && ! $this->authorization->can($organization, $admin, 'roles.assign_high_privilege'), 403, 'Esta cuenta tiene un rol de dirección o administración; solo quien puede asignar esos roles puede restablecerla.');
    }

    public function reject(Organization $organization, User $admin, int $requestId, ?string $note): void
    {
        $request = $this->pending($organization, $admin, $requestId);
        DB::table('password_reset_requests')->where('id', $requestId)->update(['status' => 'rejected', 'reviewed_by' => $admin->id, 'reviewed_at' => now(), 'review_note' => $note, 'updated_at' => now()]);
        $this->accounting->audit($organization, $admin, 'password_help.rejected', 'password_reset_requests', $requestId, ['user_id' => (int) $request->user_id], $request->office_id === null ? null : (int) $request->office_id);
        Notification::route('mail', (string) $request->email)->notify(new PasswordHelpResolved(false, $this->officeName($request), null, $note));
    }

    private function pending(Organization $organization, User $admin, int $requestId): stdClass
    {
        $request = DB::table('password_reset_requests')->where('organization_id', $organization->id)->where('id', $requestId)->lockForUpdate()->first();
        abort_if($request === null, 404);
        abort_unless($this->canDecide($organization, $admin, $request), 403, 'Esta solicitud no pertenece a una oficina que administras.');
        abort_unless($request->status === 'pending', 422, 'Esta solicitud ya fue resuelta.');

        return $request;
    }

    private function canDecide(Organization $organization, User $admin, stdClass $request): bool
    {
        $granted = $this->authorization->officesWithPermission($organization, $admin, $request->kind === 'client' ? self::CLIENT_PERMISSION : self::MEMBER_PERMISSION);

        return $granted === null || ($request->office_id !== null && in_array((int) $request->office_id, $granted, true));
    }

    /** @return list<int> */
    private function recipients(Organization $organization, string $kind, ?int $officeId): array
    {
        $permission = $kind === 'client' ? self::CLIENT_PERMISSION : self::MEMBER_PERMISSION;
        $candidates = DB::table('organization_user')->where('organization_user.organization_id', $organization->id)->where('status', 'active')->where('role', 'owner')->pluck('user_id')
            ->merge($officeId === null ? [] : DB::table('office_user')->where('office_id', $officeId)->where('status', 'active')->pluck('user_id'))->unique();
        $recipients = [];
        foreach (User::query()->whereIn('id', $candidates->all())->get() as $candidate) {
            $granted = $this->authorization->officesWithPermission($organization, $candidate, $permission);
            if ($granted === null || ($officeId !== null && in_array($officeId, $granted, true))) {
                $recipients[] = (int) $candidate->id;
            }
        }

        return $recipients;
    }

    private function membership(User $user): ?stdClass
    {
        $rows = DB::table('organization_user')->where('user_id', $user->id)->where('status', 'active')->orderByRaw('organization_id = ? desc', [(int) $user->current_organization_id])->orderBy('organization_id')->get();

        return $rows->first();
    }

    private function isClient(Organization $organization, User $user): bool
    {
        return DB::table('user_role_assignments')->join('workspace_roles', 'workspace_roles.id', '=', 'user_role_assignments.workspace_role_id')->where('user_role_assignments.organization_id', $organization->id)
            ->where('user_role_assignments.user_id', $user->id)->where('workspace_roles.code', 'client_user')->where('user_role_assignments.status', 'active')->whereNull('user_role_assignments.deleted_at')->exists();
    }

    private function officeOf(Organization $organization, User $user, string $kind): ?int
    {
        $id = $kind === 'client'
            ? DB::table('client_company_access')->join('companies', 'companies.id', '=', 'client_company_access.company_id')->where('client_company_access.organization_id', $organization->id)
                ->where('client_company_access.user_id', $user->id)->where('client_company_access.status', 'active')->value('companies.office_id')
            : DB::table('office_user')->join('offices', 'offices.id', '=', 'office_user.office_id')->where('offices.organization_id', $organization->id)->where('office_user.user_id', $user->id)
                ->where('office_user.status', 'active')->whereNull('offices.deleted_at')->value('office_user.office_id');

        return $id === null ? null : (int) $id;
    }

    private function officeName(stdClass $request): string
    {
        return $request->office_id === null ? 'tu oficina' : (string) (DB::table('offices')->where('id', $request->office_id)->value('name') ?? 'tu oficina');
    }

    private function normalize(string $value): string
    {
        return Str::of(Str::ascii($value))->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->toString();
    }
}
