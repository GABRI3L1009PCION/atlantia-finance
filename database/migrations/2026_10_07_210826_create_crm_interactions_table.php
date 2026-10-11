<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Log of meetings, calls and notes with clients and prospects. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_interactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('crm_prospect_id')->nullable()->constrained('crm_prospects')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20)->default('note');
            $table->string('summary', 300);
            $table->timestampTz('occurred_at');
            $table->string('next_action', 200)->nullable();
            $table->date('next_action_on')->nullable();
            $table->timestampsTz();

            $table->index(['organization_id', 'occurred_at']);
        });

        DB::statement('CREATE TRIGGER crm_interactions_enforce_organization BEFORE INSERT OR UPDATE ON crm_interactions FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization()');
        DB::statement('ALTER TABLE crm_interactions ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE crm_interactions FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON crm_interactions USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_interactions');
    }
};
