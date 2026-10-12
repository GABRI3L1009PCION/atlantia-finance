<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Training needs detected by management. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_needs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('area', 60);
            $table->string('topic', 160);
            $table->string('priority', 10)->default('medium');
            $table->unsignedSmallInteger('employees')->default(0);
            $table->string('status', 20)->default('pending');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['organization_id', 'status']);
        });

        DB::statement('CREATE TRIGGER training_needs_enforce_organization BEFORE INSERT OR UPDATE ON training_needs FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization()');
        DB::statement('ALTER TABLE training_needs ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE training_needs FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON training_needs USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('training_needs');
    }
};
