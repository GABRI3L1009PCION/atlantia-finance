<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Certificate code, the issued document attached as evidence, and whether the firm generates its own certificate. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_certifications', function (Blueprint $table): void {
            $table->string('code', 40)->nullable()->after('name');
            $table->string('attachment_path')->nullable()->after('expires_on');
            $table->string('attachment_name', 180)->nullable()->after('attachment_path');
            $table->boolean('generated')->default(false)->after('attachment_name');

            $table->unique(['organization_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::table('training_certifications', function (Blueprint $table): void {
            $table->dropUnique(['organization_id', 'code']);
            $table->dropColumn(['code', 'attachment_path', 'attachment_name', 'generated']);
        });
    }
};
