<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('BACKUP_ENABLED', false),
    'destination' => env('BACKUP_DESTINATION', base_path('../backups')),
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30),
    's3_uri' => env('BACKUP_S3_URI'),
    'pg_dump_binary' => env('BACKUP_PG_DUMP_BINARY', 'pg_dump'),
    'pg_restore_binary' => env('BACKUP_PG_RESTORE_BINARY', 'pg_restore'),
    'tar_binary' => env('BACKUP_TAR_BINARY', 'tar'),
    'aws_binary' => env('BACKUP_AWS_BINARY', 'aws'),
];
