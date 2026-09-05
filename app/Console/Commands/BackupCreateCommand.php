<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BackupArchiveService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('backup:create {--destination=} {--database-only} {--upload}')]
#[Description('Crea y verifica un respaldo de PostgreSQL y de los archivos privados')]
class BackupCreateCommand extends Command
{
    public function handle(BackupArchiveService $backups): int
    {
        try {
            $result = $backups->create(
                $this->option('destination') ?: null,
                ! $this->option('database-only'),
                (bool) $this->option('upload'),
            );
        } catch (Throwable $exception) {
            report($exception);
            $this->error('No se creó el respaldo: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Respaldo verificado: '.$result['id']);
        $this->line($result['path']);

        return self::SUCCESS;
    }
}
