<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Third-party tools an office connects to its own firm (chat, calendar, CRM, mail). The configuration, which holds secrets, is stored encrypted. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 30);
            $table->string('status', 20)->default('connected');
            $table->text('config');
            $table->string('public_token_hash', 64)->nullable()->unique();
            $table->timestampTz('last_synced_at')->nullable();
            $table->string('last_error', 300)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['organization_id', 'provider']);
        });

        DB::statement('CREATE TRIGGER integration_connections_enforce_organization BEFORE INSERT OR UPDATE ON integration_connections FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization()');
        DB::statement('ALTER TABLE integration_connections ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE integration_connections FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON integration_connections USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_connections');
    }
};
