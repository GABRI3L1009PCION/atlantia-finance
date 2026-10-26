<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every printing of a legal book (Diario, Mayor, Inventarios, Estados Financieros) with the folios it used, so the next
 * printing continues the numbering of the sheets authorized by the SAT and the office can prove what was printed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_book_prints', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('book', 20);
            $table->date('period_from');
            $table->date('period_to');
            $table->unsignedInteger('first_folio');
            $table->unsignedInteger('last_folio');
            $table->string('authorization', 120)->nullable();
            $table->char('file_hash', 64);
            $table->foreignId('printed_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['company_id', 'book', 'last_folio']);
            $table->index('organization_id');
        });

        DB::statement("ALTER TABLE legal_book_prints ADD CONSTRAINT legal_book_prints_integrity CHECK (book IN ('diario', 'mayor', 'inventarios', 'estados') AND first_folio >= 1 AND last_folio >= first_folio AND period_to >= period_from)");
        DB::statement("CREATE TRIGGER legal_book_prints_enforce_organization BEFORE INSERT OR UPDATE ON legal_book_prints FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization('companies', 'company_id')");
        DB::statement('ALTER TABLE legal_book_prints ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE legal_book_prints FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON legal_book_prints USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_book_prints');
    }
};
