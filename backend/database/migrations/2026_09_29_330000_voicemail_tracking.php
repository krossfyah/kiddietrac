<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Voicemail tracking (2026-09-29): who listened in the portal, and deletions kept as rows so counts survive. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voicemails', function (Blueprint $t) {
            if (! Schema::hasColumn('voicemails', 'listened_at')) $t->timestamp('listened_at')->nullable();
            if (! Schema::hasColumn('voicemails', 'listened_by')) $t->unsignedBigInteger('listened_by')->nullable();
            if (! Schema::hasColumn('voicemails', 'deleted_at')) $t->timestamp('deleted_at')->nullable()->index();
            if (! Schema::hasColumn('voicemails', 'deleted_by')) $t->unsignedBigInteger('deleted_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('voicemails', function (Blueprint $t) {
            $t->dropColumn(['listened_at', 'listened_by', 'deleted_at', 'deleted_by']);
        });
    }
};
