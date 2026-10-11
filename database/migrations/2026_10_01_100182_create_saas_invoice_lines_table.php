<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lines of a SaaS invoice (base plan, proration, discounts, credits).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saas_invoice_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('saas_invoice_id')->constrained()->cascadeOnDelete();
            $table->string('description', 255);
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_amount', 12, 2);
            $table->decimal('amount', 12, 2);
            $table->foreignId('plan_version_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE saas_invoice_lines ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE saas_invoice_lines FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON saas_invoice_lines USING (atlantia_is_system()) WITH CHECK (atlantia_is_system())');
    }

    public function down(): void
    {
        Schema::dropIfExists('saas_invoice_lines');
    }
};
