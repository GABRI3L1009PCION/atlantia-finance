<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_user', function (Blueprint $table): void {
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('permission', 20)->default('viewer');

            $table->primary(['company_id', 'user_id']);
            $table->index(['organization_id', 'user_id']);
        });

        DB::statement("ALTER TABLE company_user ADD CONSTRAINT company_user_permission CHECK (permission IN ('none', 'viewer', 'editor'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('company_user');
    }
};
