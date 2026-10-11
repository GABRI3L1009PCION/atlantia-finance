<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Identity of the firm: trade name, fiscal address, brand colors, logo, contacts and regional preferences. One row per organization. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firm_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('trade_name', 160);
            $table->string('legal_name', 190)->nullable();
            $table->string('tax_id', 30)->nullable();
            $table->string('organization_type', 40)->nullable();
            $table->unsignedSmallInteger('founded_year')->nullable();
            $table->string('website', 190)->nullable();
            $table->text('description')->nullable();
            $table->string('country', 60)->default('Guatemala');
            $table->string('department', 80)->nullable();
            $table->string('municipality', 80)->nullable();
            $table->string('fiscal_address', 255)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('logo_path')->nullable();
            $table->string('primary_color', 7)->default('#7A0E1A');
            $table->string('secondary_color', 7)->default('#F4D7DA');
            $table->string('general_email', 190)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('billing_email', 190)->nullable();
            $table->string('timezone', 60)->default('America/Guatemala');
            $table->string('currency', 3)->default('GTQ');
            $table->string('date_format', 20)->default('DD/MM/YYYY');
            $table->string('number_format', 20)->default('es-GT');
            $table->boolean('logo_in_reports')->default(true);
            $table->boolean('letterhead_in_communications')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        DB::statement('CREATE TRIGGER firm_profiles_enforce_organization BEFORE INSERT OR UPDATE ON firm_profiles FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization()');
        DB::statement('ALTER TABLE firm_profiles ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE firm_profiles FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON firm_profiles USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('firm_profiles');
    }
};
