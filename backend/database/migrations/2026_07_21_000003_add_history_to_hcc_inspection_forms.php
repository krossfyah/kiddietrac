<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hcc_inspection_forms', function (Blueprint $table) {
            // Audit trail: an append-only list of edits made after submission
            // (who, when, an optional note, the field-level old→new changes, and a
            // full snapshot of the answers BEFORE the edit for an old-vs-new view).
            $table->json('history')->nullable()->after('answers');
        });
    }

    public function down(): void
    {
        Schema::table('hcc_inspection_forms', function (Blueprint $table) {
            $table->dropColumn('history');
        });
    }
};
