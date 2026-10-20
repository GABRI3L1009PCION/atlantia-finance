<?php

declare(strict_types=1);

namespace App\Services\Luzma;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Brings Luzma's gateway back when it stops answering. The scheduled watchdog uses it every minute, and the request that
 * finds the gateway dead uses it too, so Luzma heals even when the scheduler or the autostart task is not running.
 */
class LuzmaGatewayRestarter
{
    private const THROTTLE_KEY = 'luzma.watchdog.restart';

    public function command(): ?string
    {
        $configured = trim((string) config('luzma.restart_command'));
        if ($configured !== '') {
            return $configured;
        }
        $script = base_path('services/atlantia-ai/start-luzma.ps1');

        return PHP_OS_FAMILY === 'Windows' && is_file($script)
            ? 'powershell -NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File "'.$script.'"'
            : null;
    }

    /** Forgets the throttle, called once the gateway answers again. */
    public function recovered(): void
    {
        Cache::forget(self::THROTTLE_KEY);
    }

    /** One attempt every two minutes is enough: the gateway needs a moment to come up. */
    public function allowed(): bool
    {
        return $this->command() !== null && Cache::add(self::THROTTLE_KEY, true, 120);
    }

    /** Runs the command and waits for it (the scheduled watchdog). The start script returns in seconds. */
    public function restartAndWait(): bool
    {
        if (! $this->allowed()) {
            return false;
        }
        Log::warning('Luzma: el gateway no responde; reiniciando.');
        $process = Process::fromShellCommandline((string) $this->command())->setTimeout(60);
        $process->run();
        if (! $process->isSuccessful()) {
            Log::error('Luzma: el comando de reinicio falló: '.trim($process->getErrorOutput()));
        }

        return true;
    }

    /** Starts the command detached and returns at once (the web request that found the gateway dead). */
    public function kick(): bool
    {
        if (! $this->allowed()) {
            return false;
        }
        Log::warning('Luzma: una consulta encontró el gateway apagado; pidiendo su reinicio.');
        $command = (string) $this->command();
        if (! app()->environment('testing')) {
            $this->detach($command);
        }

        return true;
    }

    protected function detach(string $command): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $handle = popen('start "" /B '.$command, 'r');
            if ($handle !== false) {
                pclose($handle);
            }

            return;
        }
        exec($command.' > /dev/null 2>&1 &');
    }
}
