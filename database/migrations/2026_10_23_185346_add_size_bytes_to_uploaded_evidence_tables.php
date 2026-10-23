<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Size of the files attached as evidence, so they count toward the plan's storage limit like every other upload.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const COLUMNS = [
        'audit_evidence_requests' => 'file_size_bytes',
        'payment_instructions' => 'evidence_size_bytes',
        'fiscal_obligations' => 'evidence_size_bytes',
        'fiscal_obligation_checks' => 'file_size_bytes',
        'work_task_waits' => 'file_size_bytes',
        'training_certifications' => 'attachment_size_bytes',
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $column) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, $column)) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                $blueprint->unsignedBigInteger($column)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $table => $column) {
            if (! Schema::hasColumn($table, $column)) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                $blueprint->dropColumn($column);
            });
        }
    }
};
