<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_role_permissions', function (Blueprint $table): void {
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_role_id')->constrained('workspace_roles')->cascadeOnDelete();
            $table->foreignId('workspace_permission_id')->constrained('workspace_permissions')->cascadeOnDelete();

            $table->primary(['workspace_role_id', 'workspace_permission_id'], 'workspace_role_permissions_pk');
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_role_permissions');
    }
};
