<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitation_office', function (Blueprint $table): void {
            $table->foreignId('invitation_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('office_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->primary(['invitation_id', 'office_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitation_office');
    }
};
