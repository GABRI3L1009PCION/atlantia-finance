<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Who registered, and how, a document the firm issued by hand in the SAT portal. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fel_documents', function (Blueprint $table): void {
            $table->string('sat_source', 10)->nullable()->after('certified_at');
            $table->foreignId('sat_registered_by')->nullable()->after('sat_source')->constrained('users')->nullOnDelete();
            $table->timestampTz('sat_registered_at')->nullable()->after('sat_registered_by');
            $table->string('sat_xml_path')->nullable()->after('sat_registered_at');
            $table->timestampTz('awaiting_sat_at')->nullable()->after('sat_xml_path');
            $table->timestampTz('sat_alerted_at')->nullable()->after('awaiting_sat_at');
        });
    }

    public function down(): void
    {
        Schema::table('fel_documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sat_registered_by');
            $table->dropColumn(['sat_source', 'sat_registered_at', 'sat_xml_path', 'awaiting_sat_at', 'sat_alerted_at']);
        });
    }
};
