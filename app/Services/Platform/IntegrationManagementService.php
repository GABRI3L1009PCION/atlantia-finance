<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Platform providers (payments, mail, storage, FEL, Luzma…). Secrets are never shown: only a masked hint
 * (sk_••••••••ABCD). Replacing a secret requires re-authentication by the caller and is audited without the value.
 * A secret stored here takes precedence over the environment configuration.
 */
class IntegrationManagementService
{
    /** @var array<string, array{name: string, category: string, config: array<string, string>, secret: string|null, health: string|null}> */
    public const PROVIDERS = [
        'recurrente' => ['name' => 'Recurrente (pagos)', 'category' => 'payments', 'config' => ['url' => 'services.recurrente.url'], 'secret' => 'services.recurrente.secret', 'health' => 'payments'],
        'recurrente_webhook' => ['name' => 'Recurrente · firma de webhooks', 'category' => 'payments', 'config' => [], 'secret' => 'services.recurrente.webhook_secret', 'health' => 'payments'],
        'mail' => ['name' => 'Correo saliente', 'category' => 'email', 'config' => ['mailer' => 'mail.default', 'host' => 'mail.mailers.smtp.host', 'from' => 'mail.from.address'], 'secret' => 'mail.mailers.smtp.password', 'health' => 'mail'],
        'storage' => ['name' => 'Almacenamiento de archivos', 'category' => 'storage', 'config' => ['disk' => 'filesystems.default'], 'secret' => null, 'health' => 'storage'],
        'fel' => ['name' => 'Certificador FEL', 'category' => 'fel', 'config' => ['provider' => 'fel.provider', 'environment' => 'fel.environment', 'url' => 'fel.api_url'], 'secret' => 'fel.api_key', 'health' => 'fel'],
        'luzma' => ['name' => 'Luzma (FastAPI + Ollama)', 'category' => 'ai', 'config' => ['gateway' => 'luzma.gateway_url'], 'secret' => null, 'health' => 'luzma_gateway'],
        'maps' => ['name' => 'Mapas', 'category' => 'maps', 'config' => [], 'secret' => 'services.maps.key', 'health' => null],
    ];

    public const STATUS_LABELS = ['active' => 'Activa', 'degraded' => 'Degradada', 'down' => 'Caída', 'disabled' => 'Deshabilitada', 'misconfigured' => 'Mal configurada'];

    public function __construct(private PlatformAuditService $audit, private PlatformHealthService $health) {}

    /** Applies secrets stored from the Control Center over the environment configuration (boot time). */
    public static function applyStoredSecrets(): void
    {
        try {
            foreach (DB::table('platform_integrations')->whereNotNull('secret')->where('enabled', true)->get(['key', 'secret']) as $row) {
                $path = self::PROVIDERS[$row->key]['secret'] ?? null;
                if ($path !== null) {
                    config([$path => Crypt::decryptString((string) $row->secret)]);
                }
            }
        } catch (\Throwable) {
            // Before migrations or without database access the environment configuration stays in effect.
        }
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $health = collect($this->health->snapshot())->keyBy('key');
        $stored = DB::table('platform_integrations')->get()->keyBy('key');

        return array_map(function (string $key) use ($health, $stored): array {
            $definition = self::PROVIDERS[$key];
            $row = $stored[$key] ?? null;
            $secret = $definition['secret'] === null ? null : (string) config($definition['secret']);
            $configured = $definition['secret'] === null || filled($secret);
            $check = $definition['health'] === null ? null : $health->get($definition['health']);
            $enabled = $row === null || (bool) $row->enabled;
            $status = ! $enabled ? 'disabled' : (! $configured ? 'misconfigured' : match ($check['status'] ?? 'healthy') {
                'down' => 'down', 'degraded' => 'degraded', default => 'active',
            });

            return ['key' => $key, 'name' => $definition['name'], 'category' => $definition['category'], 'enabled' => $enabled, 'status' => $status, 'status_label' => self::STATUS_LABELS[$status],
                'config' => array_map(fn (string $path): string => (string) (config($path) ?? '—'), $definition['config']), 'has_secret' => $definition['secret'] !== null,
                'secret_hint' => $definition['secret'] === null ? null : ($row->secret_hint ?? (filled($secret) ? PlatformAuditService::maskValue($secret) : null)),
                'secret_source' => $row?->secret !== null ? 'control_center' : (filled($secret) ? 'environment' : null),
                'secret_rotated_at' => $row?->secret_rotated_at === null ? null : Carbon::parse($row->secret_rotated_at)->toIso8601String(),
                'last_check' => $check['last_check'] ?? null, 'detail' => $check['latest_error'] ?? null];
        }, array_keys(self::PROVIDERS));
    }

    /** Replaces a provider secret (re-authenticated by the caller). The value is never logged. */
    public function updateSecret(User $actor, string $key, string $secret, string $reason): void
    {
        $definition = self::PROVIDERS[$key] ?? throw ValidationException::withMessages(['key' => 'Integración desconocida.']);
        if ($definition['secret'] === null) {
            throw ValidationException::withMessages(['key' => 'Esta integración no usa secretos.']);
        }
        if (mb_strlen($secret) < 8) {
            throw ValidationException::withMessages(['secret' => 'El secreto es demasiado corto.']);
        }
        $previous = DB::table('platform_integrations')->where('key', $key)->first();
        DB::table('platform_integrations')->updateOrInsert(['key' => $key], ['name' => $definition['name'], 'category' => $definition['category'], 'enabled' => $previous->enabled ?? true,
            'secret' => Crypt::encryptString($secret), 'secret_hint' => PlatformAuditService::maskValue($secret), 'secret_rotated_at' => now(), 'updated_by' => $actor->id,
            'updated_at' => now(), 'created_at' => $previous->created_at ?? now()]);
        config([$definition['secret'] => $secret]);
        $this->audit->record($actor, 'integration.secret.changed', 'platform_integrations', $key, ['secret_hint' => $previous->secret_hint ?? null],
            ['secret_hint' => PlatformAuditService::maskValue($secret)], $reason, null, [], true);
    }

    public function setEnabled(User $actor, string $key, bool $enabled, string $reason): void
    {
        $definition = self::PROVIDERS[$key] ?? throw ValidationException::withMessages(['key' => 'Integración desconocida.']);
        $previous = DB::table('platform_integrations')->where('key', $key)->first();
        DB::table('platform_integrations')->updateOrInsert(['key' => $key], ['name' => $definition['name'], 'category' => $definition['category'], 'enabled' => $enabled, 'updated_by' => $actor->id,
            'updated_at' => now(), 'created_at' => $previous->created_at ?? now()]);
        $this->audit->record($actor, $enabled ? 'integration.enabled' : 'integration.disabled', 'platform_integrations', $key, ['enabled' => $previous->enabled ?? true], ['enabled' => $enabled], $reason, null, [], true);
    }
}
