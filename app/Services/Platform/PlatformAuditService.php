<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\User;
use App\Services\Tenancy\TenantDatabaseContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Append-only audit of the Control Center (SaaSAuditEvent). Secrets are masked before being stored; the table itself
 * rejects updates and deletes at the database level.
 */
class PlatformAuditService
{
    private const SECRET_KEYS = ['secret', 'password', 'token', 'api_key', 'apikey', 'key_value', 'webhook_secret', 'client_secret', 'private_key'];

    public function __construct(private TenantDatabaseContext $tenancy, private PlatformAuthorization $authorization) {}

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>  $metadata
     */
    public function record(?User $actor, string $action, ?string $entityType = null, string|int|null $entityId = null, ?array $before = null, ?array $after = null,
        ?string $reason = null, ?int $organizationId = null, array $metadata = [], bool $sensitive = false): int
    {
        $request = request();

        return $this->tenancy->runAsSystem(fn (): int => DB::table('platform_audit_events')->insertGetId([
            'public_id' => (string) Str::uuid(), 'actor_id' => $actor?->id, 'actor_name' => $actor?->name, 'actor_role' => $this->authorization->role($actor),
            'action' => $action, 'entity_type' => $entityType, 'entity_id' => $entityId === null ? null : (string) $entityId, 'organization_id' => $organizationId,
            'before' => $before === null ? null : json_encode($this->mask($before), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'after' => $after === null ? null : json_encode($this->mask($after), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'reason' => $reason, 'ip' => $request->ip(), 'session_id' => $request->hasSession() ? substr(hash('sha256', $request->session()->getId()), 0, 32) : null,
            'correlation_id' => CorrelationId::current(), 'metadata' => $metadata === [] ? null : json_encode($this->mask($metadata), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'sensitive' => $sensitive, 'created_at' => now(),
        ]));
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function mask(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $this->mask($value);
            } elseif ($this->isSecretKey((string) $key) && $value !== null && $value !== '') {
                $values[$key] = self::maskValue((string) $value);
            }
        }

        return $values;
    }

    /** sk_live_abc…1234 → sk_••••••••1234 */
    public static function maskValue(string $value): string
    {
        $prefix = preg_match('/^([a-z]{2,8}_)/i', $value, $match) === 1 ? $match[1] : '';

        return $prefix.'••••••••'.(strlen($value) > 8 ? substr($value, -4) : '');
    }

    private function isSecretKey(string $key): bool
    {
        $key = strtolower($key);
        foreach (self::SECRET_KEYS as $secret) {
            if (str_contains($key, $secret)) {
                return true;
            }
        }

        return false;
    }
}
