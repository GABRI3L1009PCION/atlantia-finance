<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Alerts already delivered, so each reminder reaches a person once per window (day or week) and channel. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 40);
            $table->string('reference', 120);
            $table->string('channel', 10);
            $table->string('window_key', 20);
            $table->timestampsTz();

            $table->unique(['user_id', 'kind', 'reference', 'channel', 'window_key'], 'alert_deliveries_once');
            $table->index('organization_id');
        });

        DB::statement('CREATE TRIGGER alert_deliveries_enforce_organization BEFORE INSERT OR UPDATE ON alert_deliveries FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization()');
        DB::statement('ALTER TABLE alert_deliveries ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE alert_deliveries FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON alert_deliveries USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_deliveries');
    }
};
