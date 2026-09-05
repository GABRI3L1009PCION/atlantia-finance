<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BackupArchiveService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('backup:restore {archive} {--target-database=} {--confirm-database=} {--with-files} {--force}')]
#[Description('Restaura un respaldo verificado en una base PostgreSQL indicada explícitamente')]
class BackupRestoreCommand extends Command
{
    public function handle(BackupArchiveService $backups): int
    {
        $target = trim((string) $this->option('target-database'));
        if ($target === '' || $target !== trim((string) $this->option('confirm-database'))) {
            $this->error('Debes indicar y repetir exactamente --target-database y --confirm-database.');

            return self::FAILURE;
        }
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('En producción debes agregar --force después de tomar una ventana de mantenimiento.');

            return self::FAILURE;
        }

        try {
            $result = $backups->restore(
                (string) $this->argument('archive'),
                $target,
                (bool) $this->option('with-files'),
            );
        } catch (Throwable $exception) {
            report($exception);
            $this->error('La restauración no se completó: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Base restaurada: '.$result['database']);
        if ($result['files_restored']) {
            $this->line('Copia de seguridad previa de archivos: '.$result['safety_archive']);
        }

        return self::SUCCESS;
    }
}
