<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Explanatory notes to the financial statements of a fiscal year, with version control. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_statement_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedSmallInteger('note_number');
            $table->string('title', 150);
            $table->text('body');
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'fiscal_year', 'note_number', 'version'], 'financial_statement_notes_version');
            $table->index('organization_id');
        });

        DB::statement("ALTER TABLE financial_statement_notes ADD CONSTRAINT financial_statement_notes_status CHECK (status IN ('draft', 'published', 'superseded'))");
        DB::statement("CREATE TRIGGER financial_statement_notes_enforce_organization BEFORE INSERT OR UPDATE ON financial_statement_notes FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE financial_statement_notes ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE financial_statement_notes FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON financial_statement_notes USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_statement_notes');
    }
};
