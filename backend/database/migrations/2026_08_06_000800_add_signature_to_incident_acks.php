<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incident_acknowledgments', function (Blueprint $table) {
            if (! Schema::hasColumn('incident_acknowledgments', 'signature_data')) {
                // Base64 PNG of the drawn signature (signature pad).
                $table->longText('signature_data')->nullable()->after('signed_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('incident_acknowledgments', function (Blueprint $table) {
            if (Schema::hasColumn('incident_acknowledgments', 'signature_data')) {
                $table->dropColumn('signature_data');
            }
        });
    }
};
