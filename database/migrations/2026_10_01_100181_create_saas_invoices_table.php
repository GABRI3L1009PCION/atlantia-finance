<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invoices of Atlantia Systems to its client organizations (subscriptions), never tenant invoices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saas_invoices', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 30)->unique();
            $table->string('status', 20)->default('draft');
            $table->string('currency_code', 3)->default('GTQ');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('credit_applied', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->date('due_on')->nullable();
            $table->string('kind', 20)->default('recurring');
            $table->string('idempotency_key', 120)->unique();
            $table->timestampTz('issued_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['organization_id', 'status']);
        });

        DB::unprepared("ALTER TABLE saas_invoices ADD CONSTRAINT saas_invoices_integrity CHECK (status IN ('draft', 'open', 'paid', 'past_due', 'void', 'refunded') AND kind IN ('recurring', 'one_time', 'adjustment') AND total >= 0)");
        DB::statement('ALTER TABLE saas_invoices ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE saas_invoices FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON saas_invoices USING (atlantia_is_system()) WITH CHECK (atlantia_is_system())');
    }

    public function down(): void
    {
        Schema::dropIfExists('saas_invoices');
    }
};
