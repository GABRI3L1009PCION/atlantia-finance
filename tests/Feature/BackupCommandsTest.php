<?php

use App\Services\BackupArchiveService;
use Illuminate\Support\Facades\File;

test('backup verifier accepts checksums and rejects altered archives', function () {
    $directory = storage_path('framework/testing/backup-'.uniqid());
    File::ensureDirectoryExists($directory);
    File::put($directory.'/database.dump', 'safe database payload');
    File::put($directory.'/manifest.json', json_encode([
        'format' => 1,
        'backup_id' => 'atlantia-backup-test',
        'created_at' => now()->toIso8601String(),
        'files' => ['database.dump' => hash_file('sha256', $directory.'/database.dump')],
    ], JSON_THROW_ON_ERROR));

    $service = app(BackupArchiveService::class);
    expect($service->verify($directory)['backup_id'])->toBe('atlantia-backup-test');

    File::put($directory.'/database.dump', 'tampered payload');
    expect(fn () => $service->verify($directory))->toThrow(RuntimeException::class);
    File::deleteDirectory($directory);
});

test('restore command requires an explicit matching database confirmation', function () {
    $this->artisan('backup:restore', [
        'archive' => 'missing',
        '--target-database' => 'atlantia_restore_test',
        '--confirm-database' => 'different_database',
    ])->expectsOutput('Debes indicar y repetir exactamente --target-database y --confirm-database.')
        ->assertFailed();
});

test('backup commands expose the controlled operational interface', function () {
    $this->artisan('backup:verify', ['archive' => 'missing'])
        ->expectsOutputToContain('Respaldo inválido:')
        ->assertFailed();

    expect(config('backup.retention_days'))->toBeGreaterThan(0);
});
