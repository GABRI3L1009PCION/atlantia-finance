<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The worker data the libro de salarios asks for and the payroll did not keep: sex, date of birth (for the age) and
 * nationality.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_employees', function (Blueprint $table): void {
            $table->string('sex', 1)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('nationality', 60)->nullable();
        });
        DB::statement("ALTER TABLE payroll_employees ADD CONSTRAINT payroll_employees_sex CHECK (sex IS NULL OR sex IN ('M', 'F'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payroll_employees DROP CONSTRAINT IF EXISTS payroll_employees_sex');
        Schema::table('payroll_employees', fn (Blueprint $table) => $table->dropColumn(['sex', 'birth_date', 'nationality']));
    }
};
