<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Second approval step of a company period: after the accounting approval the supervisor (or accountant) reviews the
 * fiscal side (IVA, ISR, FEL, withholdings) before the period can be closed. The approval keeps who, when and from where.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_period_reviews', function (Blueprint $table): void {
            $table->string('fiscal_status', 30)->nullable()->after('status');
            $table->foreignId('fiscal_reviewed_by')->nullable()->after('fiscal_status')->constrained('users')->nullOnDelete();
            $table->timestampTz('fiscal_reviewed_at')->nullable()->after('fiscal_reviewed_by');
            $table->text('approval_comment')->nullable()->after('approved_at');
            $table->ipAddress('approved_ip')->nullable()->after('approval_comment');
        });
    }

    public function down(): void
    {
        Schema::table('company_period_reviews', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('fiscal_reviewed_by');
            $table->dropColumn(['fiscal_status', 'fiscal_reviewed_at', 'approval_comment', 'approved_ip']);
        });
    }
};
