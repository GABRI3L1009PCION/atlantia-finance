<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** When a course starts and how long it lasts, and the link of the session for participants. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_courses', function (Blueprint $table): void {
            $table->time('start_time')->nullable()->after('starts_on');
            $table->decimal('duration_hours', 4, 1)->nullable()->after('start_time');
            $table->string('session_url', 255)->nullable()->after('capacity');
        });
    }

    public function down(): void
    {
        Schema::table('training_courses', function (Blueprint $table): void {
            $table->dropColumn(['start_time', 'duration_hours', 'session_url']);
        });
    }
};
