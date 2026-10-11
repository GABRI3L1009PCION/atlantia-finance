<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\User;
use App\Services\Tenancy\TenantDatabaseContext;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * SaaS roles and permissions of the Atlantia Systems staff. They are independent of tenant roles: a SaaS_ADMIN
 * administers Atlantia Finance, an OFFICE_ADMIN administers one client organization.
 */
class PlatformAuthorization
{
    /** @var array<string, string> */
    public const PERMISSIONS = [
        'saas.dashboard.view' => 'Ver el Control Center',
        'saas.organizations.view' => 'Ver organizaciones',
        'saas.organizations.manage' => 'Crear y editar organizaciones',
        'saas.organizations.suspend' => 'Suspender, reactivar y archivar organizaciones',
        'saas.offices.view' => 'Ver oficinas',
        'saas.users.view' => 'Ver usuarios SaaS',
        'saas.users.manage' => 'Gestionar usuarios y roles SaaS',
        'saas.plans.view' => 'Ver planes',
        'saas.plans.manage' => 'Gestionar planes y versiones',
        'saas.subscriptions.view' => 'Ver suscripciones',
        'saas.subscriptions.manage' => 'Cambiar, pausar y cancelar suscripciones',
        'saas.billing.view' => 'Ver facturación SaaS',
        'saas.billing.manage' => 'Emitir, anular facturas y otorgar créditos',
        'saas.payments.view' => 'Ver pagos',
        'saas.payments.manage' => 'Registrar y reintentar pagos',
        'saas.features.view' => 'Ver módulos y funciones',
        'saas.features.manage' => 'Gestionar catálogo de funciones y excepciones',
        'saas.usage.view' => 'Ver límites y consumo',
        'saas.flags.view' => 'Ver feature flags',
        'saas.flags.manage' => 'Cambiar feature flags',
        'saas.services.view' => 'Ver servicios y salud',
        'saas.jobs.view' => 'Ver jobs, colas y tareas programadas',
        'saas.jobs.retry' => 'Reintentar jobs seguros',
        'saas.integrations.view' => 'Ver integraciones',
        'saas.integrations.manage' => 'Configurar integraciones y secretos',
        'saas.luzma.view' => 'Ver estado y consumo de Luzma',
        'saas.luzma.manage' => 'Gestionar herramientas de Luzma',
        'saas.luzma.use' => 'Usar Luzma del Control Center',
        'saas.security.view' => 'Ver centro de seguridad',
        'saas.security.manage' => 'Revocar sesiones y responder a eventos de seguridad',
        'saas.support_access.request' => 'Solicitar acceso de soporte',
        'saas.support_access.approve' => 'Aprobar o revocar accesos de soporte',
        'saas.tickets.view' => 'Ver tickets',
        'saas.tickets.manage' => 'Atender tickets',
        'saas.incidents.view' => 'Ver incidentes',
        'saas.incidents.manage' => 'Gestionar y comunicar incidentes',
        'saas.audit.view' => 'Ver auditoría SaaS',
        'saas.logs.view' => 'Ver logs del sistema',
        'saas.settings.view' => 'Ver configuración global',
        'saas.settings.manage' => 'Cambiar configuración global y plantillas',
    ];

    /** @var array<string, array{label: string, description: string, permissions: list<string>}> */
    public const ROLES = [
        'saas_owner' => ['label' => 'SaaS Owner', 'description' => 'Control total de la plataforma, incluidos los roles SaaS y los secretos.', 'permissions' => ['*']],
        'saas_admin' => ['label' => 'SaaS Admin', 'description' => 'Administra organizaciones, planes, operación y soporte; no asigna el rol de Owner.', 'permissions' => [
            'saas.dashboard.view', 'saas.organizations.view', 'saas.organizations.manage', 'saas.organizations.suspend', 'saas.offices.view', 'saas.users.view', 'saas.users.manage',
            'saas.plans.view', 'saas.plans.manage', 'saas.subscriptions.view', 'saas.subscriptions.manage', 'saas.billing.view', 'saas.billing.manage', 'saas.payments.view', 'saas.payments.manage',
            'saas.features.view', 'saas.features.manage', 'saas.usage.view', 'saas.flags.view', 'saas.flags.manage', 'saas.services.view', 'saas.jobs.view', 'saas.jobs.retry',
            'saas.integrations.view', 'saas.luzma.view', 'saas.luzma.manage', 'saas.luzma.use', 'saas.security.view', 'saas.support_access.request', 'saas.support_access.approve',
            'saas.tickets.view', 'saas.tickets.manage', 'saas.incidents.view', 'saas.incidents.manage', 'saas.audit.view', 'saas.logs.view', 'saas.settings.view', 'saas.settings.manage']],
        'saas_operations' => ['label' => 'SaaS Operaciones', 'description' => 'Salud de servicios, jobs, integraciones, Luzma e incidentes.', 'permissions' => [
            'saas.dashboard.view', 'saas.organizations.view', 'saas.offices.view', 'saas.usage.view', 'saas.services.view', 'saas.jobs.view', 'saas.jobs.retry', 'saas.integrations.view',
            'saas.luzma.view', 'saas.luzma.manage', 'saas.luzma.use', 'saas.incidents.view', 'saas.incidents.manage', 'saas.tickets.view', 'saas.logs.view', 'saas.flags.view']],
        'saas_billing' => ['label' => 'SaaS Facturación', 'description' => 'Planes, suscripciones, facturación y pagos.', 'permissions' => [
            'saas.dashboard.view', 'saas.organizations.view', 'saas.plans.view', 'saas.subscriptions.view', 'saas.subscriptions.manage', 'saas.billing.view', 'saas.billing.manage',
            'saas.payments.view', 'saas.payments.manage', 'saas.usage.view', 'saas.features.view', 'saas.luzma.use']],
        'saas_support' => ['label' => 'SaaS Soporte', 'description' => 'Tickets, accesos de soporte solicitados y consulta de organizaciones.', 'permissions' => [
            'saas.dashboard.view', 'saas.organizations.view', 'saas.offices.view', 'saas.usage.view', 'saas.tickets.view', 'saas.tickets.manage', 'saas.support_access.request',
            'saas.incidents.view', 'saas.luzma.use']],
        'saas_security' => ['label' => 'SaaS Seguridad', 'description' => 'Centro de seguridad, sesiones, auditoría, logs y aprobación de accesos de soporte.', 'permissions' => [
            'saas.dashboard.view', 'saas.organizations.view', 'saas.users.view', 'saas.security.view', 'saas.security.manage', 'saas.support_access.approve', 'saas.audit.view',
            'saas.logs.view', 'saas.integrations.view', 'saas.incidents.view', 'saas.luzma.use']],
        'saas_analyst' => ['label' => 'SaaS Analista', 'description' => 'Solo lectura de métricas comerciales, consumo y estado.', 'permissions' => [
            'saas.dashboard.view', 'saas.organizations.view', 'saas.offices.view', 'saas.plans.view', 'saas.subscriptions.view', 'saas.billing.view', 'saas.payments.view',
            'saas.features.view', 'saas.usage.view', 'saas.flags.view', 'saas.services.view', 'saas.luzma.view', 'saas.luzma.use']],
    ];

    /** @var array<int, stdClass|false> */
    private array $staff = [];

    public function __construct(private TenantDatabaseContext $tenancy) {}

    /** The active platform staff record of the user, or null (tenant users never have one). */
    public function staff(?User $user): ?stdClass
    {
        if ($user === null) {
            return null;
        }
        if (! array_key_exists($user->id, $this->staff)) {
            $this->staff[$user->id] = $this->tenancy->runAsSystem(fn () => DB::table('platform_staff')->where('user_id', $user->id)->where('status', 'active')->first()) ?? false;
        }

        return $this->staff[$user->id] === false ? null : $this->staff[$user->id];
    }

    public function forget(int $userId): void
    {
        unset($this->staff[$userId]);
    }

    public function isStaff(?User $user): bool
    {
        return $this->staff($user) !== null;
    }

    public function role(?User $user): ?string
    {
        return $this->staff($user)?->role;
    }

    public function can(?User $user, string $permission): bool
    {
        $role = $this->role($user);
        if ($role === null) {
            return false;
        }
        $permissions = self::ROLES[$role]['permissions'] ?? [];

        return $permissions === ['*'] || in_array($permission, $permissions, true);
    }

    public function ensure(?User $user, string $permission): void
    {
        abort_unless($this->can($user, $permission), 403, 'Tu rol SaaS no permite esta acción.');
    }

    /** @return array<string, bool> */
    public function permissions(?User $user): array
    {
        return collect(array_keys(self::PERMISSIONS))->mapWithKeys(fn (string $permission): array => [$permission => $this->can($user, $permission)])->all();
    }
}
