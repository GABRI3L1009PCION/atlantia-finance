<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class BackupArchiveService
{
    /** @return array{id: string, path: string, manifest: array<string, mixed>} */
    public function create(?string $destination = null, bool $includeFiles = true, bool $upload = false): array
    {
        $this->ensurePostgreSql();
        $root = $this->destination($destination);
        $id = 'atlantia-backup-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(8));
        $path = $root.DIRECTORY_SEPARATOR.$id;
        File::makeDirectory($path, 0750, true);

        try {
            $databaseDump = $path.DIRECTORY_SEPARATOR.'database.dump';
            $this->run($this->databaseCommand((string) config('backup.pg_dump_binary'), $databaseDump), $this->databaseEnvironment());

            $files = ['database.dump' => $this->checksum($databaseDump)];
            if ($includeFiles) {
                $storageArchive = $path.DIRECTORY_SEPARATOR.'storage-app.tar.gz';
                $this->run([
                    (string) config('backup.tar_binary'), '-czf', $storageArchive,
                    '-C', storage_path('app'), '.',
                ]);
                $files['storage-app.tar.gz'] = $this->checksum($storageArchive);
            }

            $manifest = [
                'format' => 1,
                'backup_id' => $id,
                'created_at' => now()->toIso8601String(),
                'application' => (string) config('app.name'),
                'environment' => (string) app()->environment(),
                'database_driver' => 'pgsql',
                'database_name' => (string) config('database.connections.pgsql.database'),
                'migration_count' => Schema::hasTable('migrations') ? DB::table('migrations')->count() : 0,
                'migration_batch' => Schema::hasTable('migrations') ? (int) DB::table('migrations')->max('batch') : 0,
                'files' => $files,
            ];
            File::put($path.DIRECTORY_SEPARATOR.'manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            $this->verify($path);
            if ($upload) {
                $this->upload($path, $id);
            }
            $this->prune($root);

            return ['id' => $id, 'path' => $path, 'manifest' => $manifest];
        } catch (\Throwable $exception) {
            File::deleteDirectory($path);

            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    public function verify(string $path): array
    {
        $resolved = realpath($path);
        if ($resolved === false || ! is_dir($resolved)) {
            throw new RuntimeException('El directorio del respaldo no existe.');
        }

        $manifestPath = $resolved.DIRECTORY_SEPARATOR.'manifest.json';
        if (! is_file($manifestPath)) {
            throw new RuntimeException('El respaldo no contiene manifest.json.');
        }

        $manifest = json_decode((string) File::get($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        if (($manifest['format'] ?? null) !== 1 || ! is_array($manifest['files'] ?? null)) {
            throw new RuntimeException('El manifiesto del respaldo no es compatible.');
        }

        foreach ($manifest['files'] as $filename => $expectedChecksum) {
            if (! is_string($filename) || basename($filename) !== $filename) {
                throw new RuntimeException('El manifiesto contiene una ruta insegura.');
            }
            $file = $resolved.DIRECTORY_SEPARATOR.$filename;
            if (! is_file($file) || ! hash_equals((string) $expectedChecksum, $this->checksum($file))) {
                throw new RuntimeException('Falló la verificación de integridad de '.$filename.'.');
            }
        }

        return $manifest;
    }

    /** @return array{database: string, files_restored: bool, safety_archive: string|null} */
    public function restore(string $path, string $targetDatabase, bool $withFiles = false): array
    {
        $this->ensurePostgreSql();
        $this->verify($path);
        $resolved = (string) realpath($path);
        $dump = $resolved.DIRECTORY_SEPARATOR.'database.dump';
        $this->run($this->databaseCommand((string) config('backup.pg_restore_binary'), $dump, $targetDatabase), $this->databaseEnvironment());

        $safetyArchive = null;
        if ($withFiles) {
            $archive = $resolved.DIRECTORY_SEPARATOR.'storage-app.tar.gz';
            if (! is_file($archive)) {
                throw new RuntimeException('El respaldo no contiene los archivos privados de la aplicación.');
            }
            $safetyArchive = dirname($resolved).DIRECTORY_SEPARATOR.'pre-restore-storage-'.now()->format('Ymd-His').'.tar.gz';
            $this->run([(string) config('backup.tar_binary'), '-czf', $safetyArchive, '-C', storage_path('app'), '.']);
            $this->run([(string) config('backup.tar_binary'), '-xzf', $archive, '-C', storage_path('app')]);
        }

        return ['database' => $targetDatabase, 'files_restored' => $withFiles, 'safety_archive' => $safetyArchive];
    }

    private function ensurePostgreSql(): void
    {
        if (config('database.default') !== 'pgsql') {
            throw new RuntimeException('Los respaldos operativos requieren la conexión PostgreSQL de staging o producción.');
        }
    }

    private function destination(?string $destination): string
    {
        $path = rtrim((string) ($destination ?: config('backup.destination')), '\\/');
        if ($path === '' || str_contains(str_replace('\\', '/', $path), '/public')) {
            throw new RuntimeException('El destino del respaldo debe ser privado y no puede estar dentro de public.');
        }
        File::ensureDirectoryExists($path, 0750, true);
        $resolved = realpath($path);
        if ($resolved === false) {
            throw new RuntimeException('No fue posible resolver el destino del respaldo.');
        }

        return $resolved;
    }

    /** @return list<string> */
    private function databaseCommand(string $binary, string $file, ?string $targetDatabase = null): array
    {
        $connection = config('database.connections.pgsql');
        if ($targetDatabase === null) {
            return array_values(array_filter([
                $binary, '--format=custom', '--no-owner', '--no-acl', '--enable-row-security', '--file='.$file,
                filled($connection['host'] ?? null) ? '--host='.$connection['host'] : null,
                filled($connection['port'] ?? null) ? '--port='.$connection['port'] : null,
                filled($connection['username'] ?? null) ? '--username='.$connection['username'] : null,
                (string) $connection['database'],
            ]));
        }

        return array_values(array_filter([
            $binary, '--clean', '--if-exists', '--no-owner', '--no-acl', '--exit-on-error', '--enable-row-security',
            filled($connection['host'] ?? null) ? '--host='.$connection['host'] : null,
            filled($connection['port'] ?? null) ? '--port='.$connection['port'] : null,
            filled($connection['username'] ?? null) ? '--username='.$connection['username'] : null,
            '--dbname='.$targetDatabase, $file,
        ]));
    }

    /**
     * Backups run with platform access so forced Row Level Security exposes every organization's rows.
     *
     * @return array<string, string>
     */
    private function databaseEnvironment(): array
    {
        return [
            'PGPASSWORD' => (string) config('database.connections.pgsql.password'),
            'PGOPTIONS' => '-c atlantia.system=on',
        ];
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    private function run(array $command, array $environment = []): void
    {
        $process = new Process($command, null, $environment, null, 3600);
        $process->mustRun();
    }

    private function checksum(string $file): string
    {
        $checksum = hash_file('sha256', $file);
        if ($checksum === false) {
            throw new RuntimeException('No fue posible calcular la integridad del respaldo.');
        }

        return $checksum;
    }

    private function upload(string $path, string $id): void
    {
        $uri = rtrim((string) config('backup.s3_uri'), '/');
        if (! str_starts_with($uri, 's3://')) {
            throw new RuntimeException('BACKUP_S3_URI debe apuntar a una ruta privada s3://.');
        }
        $this->run([(string) config('backup.aws_binary'), 's3', 'cp', $path, $uri.'/'.$id, '--recursive', '--sse', 'AES256']);
    }

    private function prune(string $root): void
    {
        $cutoff = now()->subDays(max(1, (int) config('backup.retention_days')))->getTimestamp();
        foreach (File::directories($root) as $directory) {
            $name = basename($directory);
            if (preg_match('/^atlantia-backup-\d{8}-\d{6}-[a-z0-9]{8}$/', $name) === 1 && File::lastModified($directory) < $cutoff) {
                File::deleteDirectory($directory);
            }
        }
    }
}
