<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columns the Control Center needs on existing commercial tables: organization lifecycle, subscription billing
 * state (plan version, dunning, cancellation, scheduled downgrade), feature catalog metadata, limit policies and the
 * link between provider payments and SaaS invoices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('code', 30)->nullable()->unique();
            $table->boolean('beta_program')->default(false);
            $table->timestampTz('suspended_at')->nullable();
            $table->text('suspension_reason')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampTz('last_activity_at')->nullable();
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->foreignId('plan_version_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->timestampTz('past_due_since')->nullable();
            $table->timestampTz('grace_ends_at')->nullable();
            $table->timestampTz('paused_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('cancel_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('scheduled_plan_price_id')->nullable()->constrained('plan_prices')->nullOnDelete();
            $table->timestampTz('scheduled_change_at')->nullable();
            $table->timestampTz('over_limit_since')->nullable();
        });

        Schema::table('features', function (Blueprint $table): void {
            $table->string('module', 40)->nullable();
            $table->string('status', 20)->default('active');
            $table->string('release_stage', 30)->default('general_availability');
            $table->jsonb('requires')->nullable();
            $table->jsonb('conflicts_with')->nullable();
        });

        Schema::table('plan_limits', function (Blueprint $table): void {
            $table->unsignedSmallInteger('warning_threshold')->default(80);
            $table->unsignedSmallInteger('high_threshold')->default(95);
            $table->string('enforcement', 10)->default('soft');
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignId('saas_invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestampTz('next_retry_at')->nullable();
            $table->string('reference', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $table) => $table->dropConstrainedForeignId('saas_invoice_id'));
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn(['attempt_count', 'next_retry_at', 'reference']));
        Schema::table('plan_limits', fn (Blueprint $table) => $table->dropColumn(['warning_threshold', 'high_threshold', 'enforcement']));
        Schema::table('features', fn (Blueprint $table) => $table->dropColumn(['module', 'status', 'release_stage', 'requires', 'conflicts_with']));
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('plan_version_id');
            $table->dropConstrainedForeignId('cancel_requested_by');
            $table->dropConstrainedForeignId('scheduled_plan_price_id');
            $table->dropColumn(['discount_percent', 'past_due_since', 'grace_ends_at', 'paused_at', 'cancel_reason', 'scheduled_change_at', 'over_limit_since']);
        });
        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn(['code', 'beta_program', 'suspended_at', 'suspension_reason', 'archived_at', 'last_activity_at']));
    }
};
