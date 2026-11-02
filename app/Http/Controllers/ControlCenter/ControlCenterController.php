<?php

declare(strict_types=1);

namespace App\Http\Controllers\ControlCenter;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Platform\PlatformAuthorization;
use App\Services\Platform\SensitiveActionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;

/**
 * Shared base of the Atlantia Control Center screens: the SaaS role, permissions (for the dynamic menu), badges and
 * the re-authentication of sensitive actions. The backend computes every state; the screens only present it.
 */
abstract class ControlCenterController extends Controller
{
    public function __construct(protected PlatformAuthorization $authorization, protected SensitiveActionGuard $guard) {}

    protected function staff(Request $request, string $permission): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $this->authorization->ensure($user, $permission);

        return $user;
    }

    /** Re-authenticates a sensitive action (password + 2FA code) and returns its reason. */
    protected function confirmSensitive(Request $request, User $user, bool $requireReason = true): string
    {
        return $this->guard->confirm($user, $request->only(['password', 'code', 'reason']), $requireReason);
    }

    protected function reason(Request $request): string
    {
        return (string) $request->validate(['reason' => 'required|string|min:5|max:1000'])['reason'];
    }

    /** @param array<string, mixed> $props */
    protected function render(Request $request, string $component, array $props): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $staff = $this->authorization->staff($user);

        return Inertia::render('control-center/'.$component, [
            'platform' => [
                'role' => $staff?->role, 'role_label' => PlatformAuthorization::ROLES[(string) $staff?->role]['label'] ?? null, 'permissions' => $this->authorization->permissions($user),
                'two_factor' => $user->two_factor_confirmed_at !== null,
                'badges' => [
                    'tickets' => DB::table('support_tickets')->whereIn('status', ['open', 'waiting_internal'])->count(),
                    'incidents' => DB::table('system_incidents')->whereNotIn('status', ['resolved', 'postmortem'])->count(),
                    'support_access' => DB::table('support_access_requests')->where('status', 'requested')->count(),
                    'failed_jobs' => DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count(),
                    'past_due' => DB::table('subscriptions')->whereIn('status', ['past_due', 'grace_period'])->count(),
                ],
                'active_support_access' => DB::table('support_access_requests')->join('organizations', 'organizations.id', '=', 'support_access_requests.organization_id')
                    ->where('requested_by', $user->id)->where('support_access_requests.status', 'active')->where('expires_at', '>', now())
                    ->get(['support_access_requests.id', 'organizations.name as organization', 'support_access_requests.expires_at'])->all(),
            ],
            'reveal' => $request->session()->get('platform_reveal'),
            ...$this->withOrganizationPublicIds($props),
        ]);
    }

    /**
     * Next to every organization_id the screens receive, adds that organization's public id (organization_public_id):
     * links are built with it, so the sequential id never shows up in a URL. One query covers the whole page.
     *
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    private function withOrganizationPublicIds(array $props): array
    {
        $ids = [];
        $collect = function (mixed $value) use (&$collect, &$ids): void {
            if ($value instanceof stdClass) {
                $value = get_object_vars($value);
            }
            if (! is_array($value)) {
                return;
            }
            if (isset($value['organization_id']) && is_numeric($value['organization_id'])) {
                $ids[(int) $value['organization_id']] = true;
            }
            foreach ($value as $item) {
                $collect($item);
            }
        };
        $collect($props);
        if ($ids === []) {
            return $props;
        }
        $publicIds = DB::table('organizations')->whereIn('id', array_keys($ids))->pluck('public_id', 'id')->all();
        $attach = function (mixed $value) use (&$attach, $publicIds): mixed {
            if ($value instanceof stdClass) {
                foreach (get_object_vars($value) as $key => $item) {
                    $value->{$key} = $attach($item);
                }
                if (isset($value->organization_id) && is_numeric($value->organization_id)) {
                    $value->organization_public_id = $publicIds[(int) $value->organization_id] ?? null;
                }

                return $value;
            }
            if (! is_array($value)) {
                return $value;
            }
            $value = array_map($attach, $value);
            if (isset($value['organization_id']) && is_numeric($value['organization_id'])) {
                $value['organization_public_id'] = $publicIds[(int) $value['organization_id']] ?? null;
            }

            return $value;
        };

        /** @var array<string, mixed> */
        return $attach($props);
    }
}
