<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Security of the platform: privileged staff, two-factor adoption, sessions, failed sign-ins, suspicious IPs,
 * support accesses and sensitive changes. Revoking a session deletes it server-side immediately.
 */
class SecurityCenterService
{
    public function __construct(private PlatformAuditService $audit, private GlobalConfigurationService $settings) {}

    /** @return array<string, mixed> */
    public function overview(): array
    {
        $staff = DB::table('platform_staff')->join('users', 'users.id', '=', 'platform_staff.user_id')->where('platform_staff.status', 'active');
        $threshold = (int) $this->settings->get('security.failed_login_alert_threshold', 10);
        $suspicious = DB::table('login_attempts')->where('succeeded', false)->where('created_at', '>=', now()->subHour())->groupBy('ip')
            ->selectRaw('ip, CAST(COUNT(*) AS BIGINT) as attempts, COUNT(DISTINCT email) as accounts, MAX(created_at) as last_at')->havingRaw('COUNT(*) >= ?', [$threshold])->get();

        return [
            'privileged_users' => (clone $staff)->count(),
            'staff_with_2fa' => (clone $staff)->whereNotNull('users.two_factor_confirmed_at')->count(),
            'staff_without_2fa' => array_values((clone $staff)->whereNull('users.two_factor_confirmed_at')->get(['users.id', 'users.name', 'users.email', 'platform_staff.role'])->map(fn (stdClass $row): array => (array) $row)->all()),
            'tenant_users_with_2fa' => DB::table('users')->whereNotNull('two_factor_confirmed_at')->whereNotIn('id', DB::table('platform_staff')->select('user_id'))->count(),
            'tenant_users' => DB::table('users')->whereNotIn('id', DB::table('platform_staff')->select('user_id'))->count(),
            'offices_enforcing_2fa' => DB::table('offices')->where('require_two_factor', true)->count(),
            'failed_logins_24h' => DB::table('login_attempts')->where('succeeded', false)->where('created_at', '>=', now()->subDay())->count(),
            'suspicious_ips' => array_values($suspicious->map(fn (stdClass $row): array => ['ip' => (string) $row->ip, 'attempts' => (int) $row->attempts, 'accounts' => (int) $row->accounts, 'last_at' => Carbon::parse($row->last_at)->toIso8601String()])->all()),
            'active_support_access' => DB::table('support_access_requests')->where('status', 'active')->where('expires_at', '>', now())->count(),
            'pending_support_access' => DB::table('support_access_requests')->where('status', 'requested')->count(),
            'sensitive_changes_7d' => DB::table('platform_audit_events')->where('sensitive', true)->where('created_at', '>=', now()->subDays(7))->count(),
            'require_2fa_policy' => (bool) $this->settings->get('security.require_two_factor_for_staff', true),
        ];
    }

    /**
     * Security events: failed sign-ins, privileged changes, support accesses, secret and flag changes, suspensions.
     *
     * @return list<array{at: string, type: string, severity: string, title: string, detail: string|null}>
     */
    public function events(): array
    {
        $events = [];
        foreach (DB::table('platform_audit_events')->where(fn ($q) => $q->where('sensitive', true)->orWhereIn('action', ['saas_user.created', 'saas_role.changed', 'support_access.started', 'support_access.ended', 'security.session_revoked']))
            ->orderByDesc('id')->limit(80)->get() as $row) {
            $events[] = ['at' => Carbon::parse($row->created_at)->toIso8601String(), 'type' => (string) $row->action,
                'severity' => in_array($row->action, ['saas_role.changed', 'integration.secret.changed', 'organization.suspended', 'support_access.started'], true) ? 'high' : 'medium',
                'title' => (string) $row->action, 'detail' => trim(($row->actor_name ?? 'Sistema').' · '.($row->reason ?? ''), ' ·')];
        }
        foreach ($this->overview()['suspicious_ips'] as $ip) {
            $events[] = ['at' => $ip['last_at'], 'type' => 'login.failed_burst', 'severity' => 'high', 'title' => 'Múltiples inicios de sesión fallidos', 'detail' => $ip['attempts'].' intentos desde '.$ip['ip'].' contra '.$ip['accounts'].' cuenta(s) en 1 hora'];
        }
        usort($events, fn (array $a, array $b): int => strcmp($b['at'], $a['at']));

        return array_slice($events, 0, 100);
    }

    /**
     * Sessions (staff first). Only metadata: user, role, IP, device and activity.
     *
     * @return list<array<string, mixed>>
     */
    public function sessions(): array
    {
        $staff = DB::table('platform_staff')->pluck('role', 'user_id');

        return array_values(DB::table('sessions')->join('users', 'users.id', '=', 'sessions.user_id')->orderByDesc('sessions.last_activity')->limit(200)
            ->get(['sessions.id', 'sessions.user_id', 'sessions.ip_address', 'sessions.user_agent', 'sessions.last_activity', 'users.name', 'users.email'])
            ->map(fn (stdClass $row): array => ['id' => substr(hash('sha256', (string) $row->id), 0, 16), 'user_id' => (int) $row->user_id, 'user' => (string) $row->name, 'email' => (string) $row->email,
                'role' => $staff[$row->user_id] ?? 'tenant', 'ip' => $row->ip_address, 'device' => $this->device((string) $row->user_agent),
                'last_activity' => Carbon::createFromTimestamp((int) $row->last_activity)->toIso8601String(),
                'status' => Carbon::createFromTimestamp((int) $row->last_activity)->gt(now()->subMinutes((int) config('session.lifetime'))) ? 'active' : 'idle'])->all());
    }

    /** Revokes one session (identified by its hashed id) or every session of a user. */
    public function revoke(User $actor, ?string $sessionHash, ?int $userId, string $reason): int
    {
        if ($sessionHash !== null) {
            $id = DB::table('sessions')->get(['id'])->first(fn (stdClass $row): bool => substr(hash('sha256', (string) $row->id), 0, 16) === $sessionHash)?->id;
            if ($id === null) {
                throw ValidationException::withMessages(['session' => 'La sesión ya no existe.']);
            }
            $count = DB::table('sessions')->where('id', $id)->delete();
        } else {
            $count = DB::table('sessions')->where('user_id', $userId)->delete();
        }
        $this->audit->record($actor, 'security.session_revoked', 'sessions', $sessionHash ?? 'user:'.$userId, null, ['revoked' => $count], $reason, null, [], true);

        return $count;
    }

    /** @return list<array<string, mixed>> */
    public function failedLogins(): array
    {
        return array_values(DB::table('login_attempts')->where('succeeded', false)->orderByDesc('id')->limit(100)->get()
            ->map(fn (stdClass $row): array => ['email' => $row->email === null ? null : SystemLogService::maskEmail((string) $row->email), 'ip' => $row->ip, 'device' => $this->device((string) $row->user_agent),
                'at' => Carbon::parse($row->created_at)->toIso8601String()])->all());
    }

    private function device(string $agent): string
    {
        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge', str_contains($agent, 'Chrome/') => 'Chrome', str_contains($agent, 'Firefox/') => 'Firefox', str_contains($agent, 'Safari/') => 'Safari', default => 'Navegador',
        };
        $os = match (true) {
            str_contains($agent, 'Windows') => 'Windows', str_contains($agent, 'Mac OS') => 'macOS', str_contains($agent, 'Android') => 'Android', str_contains($agent, 'iPhone') || str_contains($agent, 'iPad') => 'iOS', str_contains($agent, 'Linux') => 'Linux', default => '',
        };

        return trim($browser.' · '.$os, ' ·');
    }
}
