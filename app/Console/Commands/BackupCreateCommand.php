<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BackupArchiveService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

#[Signature('backup:create {--destination=} {--database-only} {--upload}')]
#[Description('Crea y verifica un respaldo de PostgreSQL y de los archivos privados')]
class BackupCreateCommand extends Command
{
    public function handle(BackupArchiveService $backups): int
    {
        $startedAt = now();
        $includeFiles = ! $this->option('database-only');

        try {
            $result = $backups->create(
                $this->option('destination') ?: null,
                $includeFiles,
                (bool) $this->option('upload'),
            );
        } catch (Throwable $exception) {
            report($exception);
            DB::table('backup_runs')->insert([
                'status' => 'failed', 'includes_files' => $includeFiles, 'uploaded' => (bool) $this->option('upload'),
                'error' => mb_substr($exception->getMessage(), 0, 500), 'started_at' => $startedAt, 'finished_at' => now(),
            ]);
            $this->error('No se creó el respaldo: '.$exception->getMessage());

            return self::FAILURE;
        }

        DB::table('backup_runs')->insert([
            'backup_id' => $result['id'], 'status' => 'succeeded', 'includes_files' => $includeFiles, 'uploaded' => (bool) $this->option('upload'),
            'size_bytes' => collect(File::files($result['path']))->sum(fn ($file): int => $file->getSize()),
            'started_at' => $startedAt, 'finished_at' => now(),
        ]);
        $this->info('Respaldo verificado: '.$result['id']);
        $this->line($result['path']);

        return self::SUCCESS;
    }
}
