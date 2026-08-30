<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('office_user', function (Blueprint $table): void {
            $table->foreignId('office_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('role', 30)->default('member');
            $table->string('status', 30)->default('active');
            $table->timestampTz('assigned_at')->nullable();
            $table->timestampsTz();

            $table->primary(['office_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('office_user');
    }
};
