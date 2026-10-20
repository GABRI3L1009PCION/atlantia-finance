<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** What the team needs to learn, who takes part and the date by which it should be covered. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_needs', function (Blueprint $table): void {
            $table->text('objective')->nullable()->after('topic');
            $table->json('participants')->nullable()->after('employees');
            $table->date('target_date')->nullable()->after('participants');
        });
    }

    public function down(): void
    {
        Schema::table('training_needs', function (Blueprint $table): void {
            $table->dropColumn(['objective', 'participants', 'target_date']);
        });
    }
};
