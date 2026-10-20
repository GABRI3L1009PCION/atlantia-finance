<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Luzma\LuzmaGatewayRestarter;
use App\Services\Luzma\LuzmaSwitch;
use App\Services\Luzma\OllamaLuzmaProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Keeps Luzma alive: every minute it checks the gateway and the model. A dead gateway is restarted with the configured
 * command (throttled), and a model that fell out of memory is loaded again, so users never meet a cold start. It does
 * nothing while the SaaS administrator has switched Luzma off.
 */
class LuzmaWatchdogCommand extends Command
{
    protected $signature = 'luzma:watchdog';

    protected $description = 'Revisa Luzma y la reinicia o la calienta si se detuvo';

    /** Only the local gateway needs watching: hosted providers (Gemini) have nothing to restart or warm up. */
    public function handle(OllamaLuzmaProvider $provider, LuzmaSwitch $switch): int
    {
        if (! $switch->enabled() || ! in_array('ollama', (array) config('luzma.providers', ['ollama']), true)) {
            return self::SUCCESS;
        }

        $health = $provider->health();
        if (! $health['gateway']) {
            return $this->restart();
        }
        app(LuzmaGatewayRestarter::class)->recovered();
        if (! $health['model_loaded'] || ! $health['model_resident']) {
            Log::info('Luzma watchdog: cargando el modelo en memoria.');
            $provider->warmup();
            $this->info('Luzma: modelo calentado.');

            return self::SUCCESS;
        }
        $this->info('Luzma: todo en orden.');

        return self::SUCCESS;
    }

    private function restart(): int
    {
        $restarter = app(LuzmaGatewayRestarter::class);
        if ($restarter->command() === null) {
            Log::warning('Luzma watchdog: el gateway no responde y no hay comando de reinicio configurado.');
            $this->warn('Luzma: el gateway no responde (sin comando de reinicio).');

            return self::FAILURE;
        }
        if (! $restarter->restartAndWait()) {
            return self::FAILURE;
        }
        $this->warn('Luzma: se pidió el reinicio del gateway.');

        return self::SUCCESS;
    }
}
