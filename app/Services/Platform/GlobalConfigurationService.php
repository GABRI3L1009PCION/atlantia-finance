<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\User;
use App\Services\Tenancy\TenantDatabaseContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Global configuration administered from the Control Center instead of being hard-coded. Each setting has a type, a
 * default and a sensitivity: sensitive ones require re-authentication and every change is audited with before/after.
 */
class GlobalConfigurationService
{
    /** @var array<string, array{group: string, label: string, type: string, default: mixed, sensitive?: bool, min?: int, max?: int, options?: list<string>, help?: string}> */
    public const SETTINGS = [
        'brand.name' => ['group' => 'brand', 'label' => 'Nombre comercial', 'type' => 'string', 'default' => 'Atlantia Finance'],
        'brand.support_email' => ['group' => 'support', 'label' => 'Correo de soporte', 'type' => 'email', 'default' => 'soporte@atlantia.systems'],
        'brand.status_url' => ['group' => 'brand', 'label' => 'URL de estado del servicio', 'type' => 'url', 'default' => ''],
        'support.first_response_hours' => ['group' => 'support', 'label' => 'Primera respuesta objetivo (horas)', 'type' => 'int', 'default' => 8, 'min' => 1, 'max' => 168],
        'support.max_access_minutes' => ['group' => 'support', 'label' => 'Duración máxima de un acceso de soporte (minutos)', 'type' => 'int', 'default' => 120, 'min' => 5, 'max' => 480],
        'billing.currency' => ['group' => 'billing', 'label' => 'Moneda de facturación', 'type' => 'select', 'default' => 'GTQ', 'options' => ['GTQ', 'USD']],
        'billing.trial_days' => ['group' => 'billing', 'label' => 'Días de prueba por defecto', 'type' => 'int', 'default' => 14, 'min' => 0, 'max' => 90],
        'billing.invoice_due_days' => ['group' => 'billing', 'label' => 'Días para pagar una factura', 'type' => 'int', 'default' => 5, 'min' => 0, 'max' => 60],
        'billing.reminder_after_days' => ['group' => 'billing', 'label' => 'Recordatorio de pago vencido a los (días)', 'type' => 'int', 'default' => 3, 'min' => 1, 'max' => 30],
        'billing.grace_after_days' => ['group' => 'billing', 'label' => 'Periodo de gracia desde el día', 'type' => 'int', 'default' => 7, 'min' => 1, 'max' => 60],
        'billing.suspend_after_days' => ['group' => 'billing', 'label' => 'Suspender desde el día', 'type' => 'int', 'default' => 15, 'min' => 1, 'max' => 120, 'sensitive' => true, 'help' => 'Días de atraso tras los cuales se suspende una organización. Afecta el acceso de clientes.'],
        'billing.auto_suspend' => ['group' => 'billing', 'label' => 'Suspender automáticamente por falta de pago', 'type' => 'bool', 'default' => true, 'sensitive' => true],
        'billing.downgrade_policy' => ['group' => 'billing', 'label' => 'Política de reducción de plan', 'type' => 'select', 'default' => 'next_cycle', 'options' => ['next_cycle', 'immediate_if_within_limits'], 'help' => 'next_cycle: se aplica al renovar. immediate_if_within_limits: inmediato si el consumo cabe en el nuevo plan.'],
        'security.require_two_factor_for_staff' => ['group' => 'security', 'label' => 'Exigir 2FA a todo el personal SaaS', 'type' => 'bool', 'default' => true, 'sensitive' => true],
        'security.failed_login_alert_threshold' => ['group' => 'security', 'label' => 'Alerta tras intentos fallidos desde una IP (1 hora)', 'type' => 'int', 'default' => 10, 'min' => 3, 'max' => 100],
        'security.staff_session_minutes' => ['group' => 'security', 'label' => 'Inactividad máxima del personal SaaS (minutos)', 'type' => 'int', 'default' => 120, 'min' => 15, 'max' => 720],
        'mail.from_name' => ['group' => 'email', 'label' => 'Remitente de los correos', 'type' => 'string', 'default' => 'Atlantia Finance'],
        'mail.notifications_enabled' => ['group' => 'email', 'label' => 'Enviar correos de la plataforma', 'type' => 'bool', 'default' => true],
        'features.new_organizations_beta' => ['group' => 'features', 'label' => 'Nuevas organizaciones entran al grupo beta', 'type' => 'bool', 'default' => false],
        'luzma.enabled' => ['group' => 'features', 'label' => 'Luzma IA encendida para todas las firmas', 'type' => 'bool', 'default' => true, 'sensitive' => true, 'help' => 'Único interruptor que apaga Luzma. Mientras esté encendida, Luzma sigue respondiendo aunque el modelo falle (modo básico).'],
        'luzma.disabled_message' => ['group' => 'features', 'label' => 'Mensaje cuando Luzma está apagada', 'type' => 'string', 'default' => 'Luzma IA está en mantenimiento. Volverá a estar disponible pronto.'],
        'retention.api_logs_days' => ['group' => 'retention', 'label' => 'Conservar logs de API (días)', 'type' => 'int', 'default' => 90, 'min' => 7, 'max' => 730],
        'retention.health_checks_days' => ['group' => 'retention', 'label' => 'Conservar chequeos de salud (días)', 'type' => 'int', 'default' => 30, 'min' => 1, 'max' => 365],
        'retention.job_runs_days' => ['group' => 'retention', 'label' => 'Conservar historial de jobs (días)', 'type' => 'int', 'default' => 30, 'min' => 1, 'max' => 365],
        'limits.max_upload_mb' => ['group' => 'limits', 'label' => 'Tamaño máximo por archivo (MB)', 'type' => 'int', 'default' => 20, 'min' => 1, 'max' => 200],
        'limits.api_rate_per_minute' => ['group' => 'limits', 'label' => 'Solicitudes de API por minuto por llave', 'type' => 'int', 'default' => 120, 'min' => 10, 'max' => 5000],
    ];

    public const GROUPS = ['brand' => 'Marca y URLs', 'support' => 'Soporte', 'billing' => 'Facturación', 'security' => 'Seguridad', 'email' => 'Correo', 'features' => 'Funciones', 'retention' => 'Retención', 'limits' => 'Límites del sistema'];

    /** @var array<string, mixed>|null */
    private ?array $values = null;

    public function __construct(private TenantDatabaseContext $tenancy) {}

    public function get(string $key, mixed $fallback = null): mixed
    {
        $values = $this->values ??= $this->tenancy->runAsSystem(fn (): array => DB::table('platform_settings')->pluck('value', 'key')
            ->map(fn ($value) => json_decode((string) $value, true))->all());

        return array_key_exists($key, $values) ? $values[$key] : (self::SETTINGS[$key]['default'] ?? $fallback);
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return array_values(array_map(fn (string $key): array => ['key' => $key, ...self::SETTINGS[$key], 'value' => $this->get($key), 'sensitive' => (bool) (self::SETTINGS[$key]['sensitive'] ?? false)], array_keys(self::SETTINGS)));
    }

    /**
     * Changes one setting. Sensitive settings must already be re-authenticated by the caller.
     */
    public function set(User $actor, string $key, mixed $value): void
    {
        $definition = self::SETTINGS[$key] ?? throw ValidationException::withMessages(['key' => 'Configuración desconocida.']);
        $min = (int) ($definition['min'] ?? PHP_INT_MIN);
        $options = array_map('strval', $definition['options'] ?? []);
        $max = (int) ($definition['max'] ?? PHP_INT_MAX);
        $value = match ($definition['type']) {
            'int' => is_numeric($value) ? (int) $value : throw ValidationException::withMessages(['value' => 'Debe ser un número.']),
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? (string) $value : throw ValidationException::withMessages(['value' => 'Correo inválido.']),
            'url' => $value === '' || $value === null || filter_var($value, FILTER_VALIDATE_URL) !== false ? (string) $value : throw ValidationException::withMessages(['value' => 'URL inválida.']),
            'select' => in_array($value, $options, true) ? (string) $value : throw ValidationException::withMessages(['value' => 'Opción inválida.']),
            default => mb_substr(trim((string) $value), 0, 255),
        };
        if ($definition['type'] === 'int' && ($value < $min || $value > $max)) {
            throw ValidationException::withMessages(['value' => 'Debe estar entre '.$min.' y '.$max.'.']);
        }
        $before = $this->get($key);
        $this->tenancy->runAsSystem(fn () => DB::table('platform_settings')->updateOrInsert(['key' => $key], ['group' => $definition['group'], 'value' => json_encode($value, JSON_THROW_ON_ERROR),
            'sensitive' => (bool) ($definition['sensitive'] ?? false), 'updated_by' => $actor->id, 'updated_at' => now(), 'created_at' => now()]));
        $this->values = null;
        app(PlatformAuditService::class)->record($actor, 'settings.changed', 'platform_settings', $key, ['value' => $before], ['value' => $value], null, null, [], (bool) ($definition['sensitive'] ?? false));
    }

    public function isSensitive(string $key): bool
    {
        return (bool) (self::SETTINGS[$key]['sensitive'] ?? false);
    }
}
