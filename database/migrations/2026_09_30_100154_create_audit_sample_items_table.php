<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Frozen element of a sample with the snapshot taken at selection and its test result.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_sample_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audit_sample_id')->constrained()->cascadeOnDelete();
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');
            $table->string('reference', 160);
            $table->date('item_date')->nullable();
            $table->bigInteger('amount_cents')->nullable();
            $table->jsonb('snapshot');
            $table->string('snapshot_hash', 64);
            $table->string('result', 20)->default('pending');
            $table->text('note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['audit_sample_id', 'source_type', 'source_id']);
        });

        DB::statement("ALTER TABLE audit_sample_items ADD CONSTRAINT audit_sample_items_integrity CHECK (result IN ('pending', 'pass', 'exception', 'pending_evidence', 'not_applicable'))");
        DB::statement("CREATE TRIGGER audit_sample_items_enforce_organization BEFORE INSERT OR UPDATE ON audit_sample_items FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE audit_sample_items ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE audit_sample_items FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON audit_sample_items USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_sample_items');
    }
};
