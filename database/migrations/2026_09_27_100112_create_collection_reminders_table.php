<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every reminder or statement sent to a customer: who, when, which template, to whom and the delivery status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_reminders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('collection_reminder_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 24);
            $table->string('channel', 20)->default('email');
            $table->string('recipient', 255);
            $table->string('subject', 200);
            $table->text('body');
            $table->string('status', 16)->default('queued');
            $table->string('error', 500)->nullable();
            $table->foreignId('sent_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();
            $table->index(['company_id', 'sales_customer_id', 'created_at']);
        });

        DB::statement("ALTER TABLE collection_reminders ADD CONSTRAINT collection_reminders_integrity CHECK (status IN ('queued', 'sent', 'failed'))");
        DB::statement("CREATE TRIGGER collection_reminders_enforce_organization BEFORE INSERT OR UPDATE ON collection_reminders FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('offices', 'office_id', 'companies', 'company_id')");
        DB::statement('ALTER TABLE collection_reminders ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE collection_reminders FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON collection_reminders USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_reminders');
    }
};
