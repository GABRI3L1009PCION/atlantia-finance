<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Checkbooks of a bank account: a range of consecutive check numbers handed out one at a time. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_checkbooks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained()->restrictOnDelete();
            $table->string('series', 20)->nullable();
            $table->unsignedBigInteger('first_number');
            $table->unsignedBigInteger('last_number');
            $table->unsignedBigInteger('next_number');
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['bank_account_id', 'status']);
            $table->index('organization_id');
        });

        DB::statement("ALTER TABLE bank_checkbooks ADD CONSTRAINT bank_checkbooks_integrity CHECK (first_number > 0 AND last_number >= first_number AND next_number BETWEEN first_number AND last_number + 1 AND status IN ('active', 'exhausted', 'closed'))");
        DB::statement("CREATE TRIGGER bank_checkbooks_enforce_organization BEFORE INSERT OR UPDATE ON bank_checkbooks FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE bank_checkbooks ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE bank_checkbooks FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON bank_checkbooks USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_checkbooks');
    }
};
