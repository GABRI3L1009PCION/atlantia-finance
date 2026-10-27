<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sales, purchases and journal entries captured in the desktop app while the server could not be reached. On upload
 * each one becomes a draft through the same services as the web forms, so nothing is posted without review; a capture
 * that cannot become a draft stays here, with the reason, for the accountant to fix or discard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_captures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('desktop_device_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('client_uuid')->unique();
            $table->string('kind', 20);
            $table->jsonb('payload');
            $table->timestampTz('captured_at');
            $table->string('status', 20);
            $table->string('error', 500)->nullable();
            $table->string('draft_url')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();
            $table->index(['company_id', 'status']);
        });

        DB::statement("CREATE TRIGGER offline_captures_enforce_organization BEFORE INSERT ON offline_captures FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE offline_captures ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE offline_captures FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON offline_captures USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
        // Like every table with company rows, captures of a company in the trash are hidden.
        DB::statement('CREATE POLICY hide_deleted_companies ON offline_captures AS RESTRICTIVE USING (company_id IS NULL OR company_id NOT IN (SELECT unnest(atlantia_hidden_company_ids())))');
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_captures');
    }
};
