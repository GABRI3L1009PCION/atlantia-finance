<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BackupArchiveService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('backup:verify {archive}')]
#[Description('Verifica el manifiesto y las sumas SHA-256 de un respaldo')]
class BackupVerifyCommand extends Command
{
    public function handle(BackupArchiveService $backups): int
    {
        try {
            $manifest = $backups->verify((string) $this->argument('archive'));
        } catch (Throwable $exception) {
            $this->error('Respaldo inválido: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Integridad aprobada: '.($manifest['backup_id'] ?? 'sin identificador'));
        $this->line('Creado: '.($manifest['created_at'] ?? 'desconocido'));

        return self::SUCCESS;
    }
}
