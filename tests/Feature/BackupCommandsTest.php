<?php

use App\Services\BackupArchiveService;
use Illuminate\Support\Facades\DB;
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

test('the trash filter works with the empty search path that pg_dump and pg_restore use', function () {
    $schema = DB::selectOne('SELECT current_schema() AS name')->name;
    DB::statement("SELECT set_config('search_path', '', true)");

    $hidden = DB::selectOne("SELECT \"{$schema}\".atlantia_hidden_company_ids() AS ids")->ids;

    expect($hidden)->toBe('{}');
});

test('backups read every organization and the companies in the trash', function () {
    $environment = (fn (): array => $this->databaseEnvironment())->call(app(BackupArchiveService::class));

    expect($environment['PGOPTIONS'])
        ->toContain('atlantia.system=on')
        ->toContain('atlantia.include_deleted=on');
});
