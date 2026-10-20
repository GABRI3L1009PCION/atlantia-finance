<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Manual steps of the preparation of a fiscal obligation (documents received, calculation prepared, working paper...).
 * A step is done by a person, with an optional note and supporting file; automatic checks are never stored here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_obligation_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fiscal_obligation_id')->constrained()->cascadeOnDelete();
            $table->string('check_key', 60);
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('completed_at');
            $table->string('note', 500)->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->timestampsTz();

            $table->unique(['fiscal_obligation_id', 'check_key']);
        });

        DB::statement('ALTER TABLE fiscal_obligation_checks ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE fiscal_obligation_checks FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON fiscal_obligation_checks USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_obligation_checks');
    }
};
