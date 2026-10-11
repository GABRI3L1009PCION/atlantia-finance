<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Courses the firm gives its staff. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_courses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('title', 160);
            $table->string('area', 60);
            $table->string('modality', 20)->default('virtual');
            $table->date('starts_on')->nullable();
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->string('status', 20)->default('scheduled');
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['organization_id', 'status', 'starts_on']);
        });

        DB::statement('CREATE TRIGGER training_courses_enforce_organization BEFORE INSERT OR UPDATE ON training_courses FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization()');
        DB::statement('ALTER TABLE training_courses ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE training_courses FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON training_courses USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('training_courses');
    }
};
