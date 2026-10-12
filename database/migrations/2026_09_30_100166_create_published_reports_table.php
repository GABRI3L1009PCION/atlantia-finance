<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reports published to the client: an immutable snapshot per version; errors are revoked, never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('published_reports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('report_type', 30);
            $table->string('title', 180);
            $table->string('period_label', 60);
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('draft');
            $table->string('file_path', 500)->nullable();
            $table->string('file_hash', 64)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('published_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revoke_reason', 500)->nullable();
            $table->timestampsTz();
            $table->unique(['company_id', 'report_type', 'period_start', 'period_end', 'version']);
        });

        DB::statement("ALTER TABLE published_reports ADD CONSTRAINT published_reports_integrity CHECK (report_type IN ('income_statement', 'balance_sheet', 'trial_balance', 'receivables', 'payables', 'cash_flow', 'fiscal', 'other') AND status IN ('draft', 'in_review', 'approved', 'published', 'revoked'))");
        DB::statement("CREATE TRIGGER published_reports_enforce_organization BEFORE INSERT OR UPDATE ON published_reports FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE published_reports ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE published_reports FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON published_reports USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('published_reports');
    }
};
