<?php

declare(strict_types=1);

namespace App\Services\Luzma;

use App\Models\User;
use App\Services\Platform\GlobalConfigurationService;
use App\Services\Platform\PlatformAuditService;

/**
 * The only thing that turns Luzma off: the switch of the SaaS administrator in the Control Center. Failures of the
 * model, the gateway or the GPU never switch it off; Luzma keeps answering in basic mode until the model is back.
 * The LUZMA_ENABLED environment variable is a hard stop for the server operator and defaults to on.
 */
class LuzmaSwitch
{
    public function __construct(private GlobalConfigurationService $settings, private PlatformAuditService $audit) {}

    public function enabled(): bool
    {
        return (bool) config('luzma.enabled') && (bool) $this->settings->get('luzma.enabled', true);
    }

    public function message(): string
    {
        $message = trim((string) $this->settings->get('luzma.disabled_message', ''));

        return $message !== '' ? $message : 'Luzma IA está en mantenimiento. Volverá a estar disponible pronto.';
    }

    public function set(User $actor, bool $enabled, string $reason): void
    {
        $before = $this->enabled();
        $this->settings->set($actor, 'luzma.enabled', $enabled);
        $this->audit->record($actor, $enabled ? 'luzma.switched_on' : 'luzma.switched_off', 'platform_settings', 'luzma.enabled', ['enabled' => $before], ['enabled' => $enabled], $reason);
    }
}
