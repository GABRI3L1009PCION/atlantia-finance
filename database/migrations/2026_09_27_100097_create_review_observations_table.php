<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Review workflow of the accountant in charge: offices choose one or two review levels, the first level approval is
 * recorded on the period review, and observations are tracked one by one until they are resolved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offices', function (Blueprint $table): void {
            $table->string('review_workflow', 20)->default('single_level');
        });

        Schema::table('company_period_reviews', function (Blueprint $table): void {
            $table->foreignId('first_level_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('first_level_approved_at')->nullable();
            $table->foreignId('upper_reviewer_id')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('review_observations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('accounting_period_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('company_period_review_id')->nullable()->constrained()->nullOnDelete();
            $table->string('related_type', 60)->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->string('related_label', 180)->nullable();
            $table->string('severity', 20)->default('medium');
            $table->string('status', 20)->default('open');
            $table->text('message');
            $table->text('resolution')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('reopened_at')->nullable();
            $table->timestampsTz();

            $table->index(['organization_id', 'company_id', 'status']);
            $table->index(['organization_id', 'assigned_to', 'status']);
        });

        DB::statement("CREATE TRIGGER review_observations_enforce_organization BEFORE INSERT OR UPDATE ON review_observations FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('offices', 'office_id', 'companies', 'company_id')");
        DB::statement('ALTER TABLE review_observations ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE review_observations FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON review_observations USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('review_observations');
        Schema::table('company_period_reviews', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('first_level_approved_by');
            $table->dropConstrainedForeignId('upper_reviewer_id');
            $table->dropColumn('first_level_approved_at');
        });
        Schema::table('offices', function (Blueprint $table): void {
            $table->dropColumn('review_workflow');
        });
    }
};
