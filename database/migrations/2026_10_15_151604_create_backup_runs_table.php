<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-wide record of every backup run (one backup protects every organization), so each office can see when its
 * data was last protected and whether the copy passed the integrity check. It holds no tenant data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('backup_id', 80)->nullable();
            $table->string('status', 12);
            $table->boolean('includes_files')->default(true);
            $table->boolean('uploaded')->default(false);
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->index(['status', 'finished_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_runs');
    }
};
