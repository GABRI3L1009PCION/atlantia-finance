<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Changes of company master data requested by the client and validated by the office.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_data_change_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('field', 30);
            $table->string('current_value', 255)->nullable();
            $table->string('requested_value', 255);
            $table->string('reason', 500)->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestampsTz();
            $table->index(['company_id', 'status']);
        });

        DB::statement("ALTER TABLE company_data_change_requests ADD CONSTRAINT company_data_change_requests_integrity CHECK (field IN ('email', 'phone', 'address', 'trade_name', 'legal_representative', 'contact_name') AND status IN ('pending', 'approved', 'rejected'))");
        DB::statement("CREATE TRIGGER company_data_change_requests_enforce_organization BEFORE INSERT OR UPDATE ON company_data_change_requests FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE company_data_change_requests ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE company_data_change_requests FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON company_data_change_requests USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('company_data_change_requests');
    }
};
