<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Credits granted to an organization (compensation, goodwill) applied to its next SaaS invoices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saas_credits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->decimal('remaining', 12, 2);
            $table->string('currency_code', 3)->default('GTQ');
            $table->text('reason');
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();
        });

        DB::unprepared('ALTER TABLE saas_credits ADD CONSTRAINT saas_credits_integrity CHECK (amount > 0 AND remaining >= 0 AND remaining <= amount)');
        DB::statement('ALTER TABLE saas_credits ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE saas_credits FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON saas_credits USING (atlantia_is_system()) WITH CHECK (atlantia_is_system())');
    }

    public function down(): void
    {
        Schema::dropIfExists('saas_credits');
    }
};
