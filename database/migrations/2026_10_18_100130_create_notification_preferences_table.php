<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** What each person wants to be alerted about, by which channel, how often, and who else receives the email. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kind', 40);
            $table->boolean('in_app')->default(true);
            $table->boolean('email')->default(false);
            $table->string('frequency', 12)->default('immediate');
            $table->unsignedSmallInteger('lead_days')->default(3);
            $table->text('recipients')->nullable();
            $table->timestampsTz();

            $table->unique(['user_id', 'company_id', 'kind']);
            $table->index('organization_id');
        });

        DB::statement("ALTER TABLE notification_preferences ADD CONSTRAINT notification_preferences_integrity CHECK (frequency IN ('immediate', 'daily', 'weekly', 'off') AND lead_days BETWEEN 0 AND 60)");
        DB::statement('CREATE TRIGGER notification_preferences_enforce_organization BEFORE INSERT OR UPDATE ON notification_preferences FOR EACH ROW EXECUTE FUNCTION atlantia_enforce_organization()');
        DB::statement('ALTER TABLE notification_preferences ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE notification_preferences FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY tenant_isolation ON notification_preferences USING (atlantia_is_system() OR organization_id = atlantia_current_organization_id()) WITH CHECK (atlantia_is_system() OR organization_id = atlantia_current_organization_id())');
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
