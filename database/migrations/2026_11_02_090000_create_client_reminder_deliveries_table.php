<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Automatic reminders an office sends to its clients before their deadlines (fee invoices, requested documents, tax
 * due dates): the office's settings live in offices.client_reminders and every reminder sent is logged once per
 * deadline, day before it, channel and recipient, so a daily run never repeats a message.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offices', function (Blueprint $table): void {
            $table->jsonb('client_reminders')->nullable();
        });

        Schema::create('client_reminder_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('source_type', 30);
            $table->unsignedBigInteger('source_id');
            $table->date('due_on');
            $table->unsignedSmallInteger('days_before');
            $table->string('channel', 20);
            $table->string('recipient', 255);
            $table->string('status', 20);
            $table->string('error', 255)->nullable();
            $table->timestampsTz();

            $table->unique(['source_type', 'source_id', 'due_on', 'days_before', 'channel', 'recipient'], 'client_reminder_deliveries_once');
            $table->index(['organization_id', 'created_at']);
        });

        DB::statement("ALTER TABLE client_reminder_deliveries ADD CONSTRAINT client_reminder_deliveries_integrity CHECK (source_type IN ('crm_invoice', 'client_request', 'fiscal_obligation') AND channel IN ('in_app', 'email', 'whatsapp') AND status IN ('sent', 'failed', 'skipped'))");
        DB::statement("CREATE TRIGGER client_reminder_deliveries_enforce_organization BEFORE INSERT OR UPDATE ON client_reminder_deliveries FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE client_reminder_deliveries ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE client_reminder_deliveries FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON client_reminder_deliveries USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
        DB::statement('CREATE POLICY hide_deleted_companies ON client_reminder_deliveries AS RESTRICTIVE USING (company_id NOT IN (SELECT unnest(atlantia_hidden_company_ids())))');
    }

    public function down(): void
    {
        Schema::dropIfExists('client_reminder_deliveries');
        Schema::table('offices', fn (Blueprint $table) => $table->dropColumn('client_reminders'));
    }
};
