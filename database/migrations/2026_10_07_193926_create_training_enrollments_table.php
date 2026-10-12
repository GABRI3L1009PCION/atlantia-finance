<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Who is enrolled in each course and how far they are. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('training_course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('enrolled');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->unsignedTinyInteger('satisfaction')->nullable();
            $table->date('completed_on')->nullable();
            $table->timestampsTz();

            $table->unique(['training_course_id', 'user_id']);
            $table->index(['organization_id', 'user_id', 'status']);
        });

        DB::statement("CREATE TRIGGER training_enrollments_enforce_organization BEFORE INSERT OR UPDATE ON training_enrollments FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('training_courses', 'training_course_id')");
        DB::statement('ALTER TABLE training_enrollments ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE training_enrollments FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON training_enrollments USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('training_enrollments');
    }
};
