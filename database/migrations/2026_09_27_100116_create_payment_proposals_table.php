<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payment proposals prepared by accounts payable: what to pay, to whom, how much and for when. A proposal never lowers a payable; it is approved by someone else and then sent to Treasury, which executes the real payments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_proposals', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 30);
            $table->date('proposed_date');
            $table->string('priority', 10)->default('normal');
            $table->string('status', 24)->default('draft');
            $table->unsignedBigInteger('total_cents')->default(0);
            $table->char('currency', 3)->default('GTQ');
            $table->text('notes')->nullable();
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->string('review_note', 1000)->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();
            $table->unique(['company_id', 'reference']);
            $table->index(['organization_id', 'company_id', 'status']);
        });

        DB::statement("ALTER TABLE payment_proposals ADD CONSTRAINT payment_proposals_integrity CHECK (status IN ('draft', 'pending_approval', 'approved', 'rejected', 'sent_to_treasury', 'partially_executed', 'completed', 'cancelled') AND priority IN ('low', 'normal', 'high', 'urgent'))");
        DB::statement("CREATE TRIGGER payment_proposals_enforce_organization BEFORE INSERT OR UPDATE ON payment_proposals FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('offices', 'office_id', 'companies', 'company_id')");
        DB::statement('ALTER TABLE payment_proposals ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE payment_proposals FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON payment_proposals USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_proposals');
    }
};
