<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Lifecycle states of organizations and subscriptions and which of them still grant access to Atlantia Finance.
 * Past-due and grace-period tenants keep working while billing is resolved; suspended, cancelled, expired or
 * archived ones do not (their data is preserved, never deleted).
 */
final class PlatformStatus
{
    public const ORGANIZATION_LABELS = [
        'pending_payment' => 'Pendiente de pago', 'trial' => 'Prueba', 'active' => 'Activa', 'past_due' => 'Pago vencido',
        'grace_period' => 'Periodo de gracia', 'suspended' => 'Suspendida', 'cancelled' => 'Cancelada', 'archived' => 'Archivada',
    ];

    public const SUBSCRIPTION_LABELS = [
        'incomplete' => 'Incompleta', 'trialing' => 'En prueba', 'active' => 'Activa', 'past_due' => 'Pago vencido',
        'grace_period' => 'Periodo de gracia', 'paused' => 'Pausada', 'cancelled' => 'Cancelada', 'expired' => 'Vencida',
    ];

    /** Organization states that can use the application. */
    public const ORGANIZATION_ACCESS = ['active', 'trial', 'past_due', 'grace_period'];

    /** Subscription states that keep the application available. */
    public const SUBSCRIPTION_ACCESS = ['active', 'trialing', 'past_due', 'grace_period'];
}
