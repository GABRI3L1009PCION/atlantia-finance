<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Professional certifications held by staff, with their expiry. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_certifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('issuer', 120)->nullable();
            $table->date('issued_on');
            $table->date('expires_on')->nullable();
            $table->timestampsTz();

            $table->index(['organization_id', 'expires_on']);
        });

        DB::statement('CREATE TRIGGER training_certifications_enforce_organization BEFORE INSERT OR UPDATE ON training_certifications FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization()');
        DB::statement('ALTER TABLE training_certifications ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE training_certifications FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON training_certifications USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('training_certifications');
    }
};
