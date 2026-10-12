<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** What each connected tool did (a notice sent, a sync, a failed test) so the office can see that it works. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 30);
            $table->string('event', 80);
            $table->boolean('ok')->default(true);
            $table->string('message', 300)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['organization_id', 'created_at']);
        });

        DB::statement('CREATE TRIGGER integration_events_enforce_organization BEFORE INSERT OR UPDATE ON integration_events FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization()');
        DB::statement('ALTER TABLE integration_events ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE integration_events FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON integration_events USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_events');
    }
};
