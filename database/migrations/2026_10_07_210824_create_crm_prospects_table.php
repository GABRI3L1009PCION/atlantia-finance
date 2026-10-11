<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Sales pipeline of the firm: prospects and their stage. Money is in cents of quetzales. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_prospects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 160);
            $table->string('contact_name', 120)->nullable();
            $table->string('contact_email', 190)->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->string('stage', 20)->default('prospect');
            $table->unsignedBigInteger('estimated_value_cents')->default(0);
            $table->date('expected_close_on')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->index(['organization_id', 'stage']);
        });

        DB::statement('CREATE TRIGGER crm_prospects_enforce_organization BEFORE INSERT OR UPDATE ON crm_prospects FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization()');
        DB::statement('ALTER TABLE crm_prospects ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE crm_prospects FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON crm_prospects USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_prospects');
    }
};
