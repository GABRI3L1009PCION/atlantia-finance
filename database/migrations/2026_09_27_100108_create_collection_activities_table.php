<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Collection follow-ups with a customer (calls, emails, meetings...). Rows are never edited: a correction is a new activity that points to the one it corrects.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 20);
            $table->string('result', 30);
            $table->text('notes')->nullable();
            $table->string('next_action', 250)->nullable();
            $table->timestampTz('next_action_at')->nullable();
            $table->string('status', 16)->default('done');
            $table->foreignId('corrects_id')->nullable()->constrained('collection_activities')->nullOnDelete();
            $table->foreignId('performed_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('performed_at');
            $table->timestampsTz();
            $table->index(['company_id', 'sales_customer_id', 'performed_at']);
            $table->index(['organization_id', 'status', 'next_action_at']);
        });

        DB::statement("ALTER TABLE collection_activities ADD CONSTRAINT collection_activities_integrity CHECK (status IN ('open', 'done', 'superseded'))");
        DB::statement("CREATE TRIGGER collection_activities_enforce_organization BEFORE INSERT OR UPDATE ON collection_activities FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('offices', 'office_id', 'companies', 'company_id')");
        DB::statement('ALTER TABLE collection_activities ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE collection_activities FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON collection_activities USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_activities');
    }
};
