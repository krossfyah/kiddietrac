<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Voicemail → lead link, and when each person last opened the phone for voicemails (2026-10-23). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voicemails', function (Blueprint $t) {
            if (! Schema::hasColumn('voicemails', 'lead_id')) $t->unsignedBigInteger('lead_id')->nullable();
        });
        Schema::table('dialer_seen', function (Blueprint $t) {
            if (! Schema::hasColumn('dialer_seen', 'vm_seen_at')) $t->timestamp('vm_seen_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('voicemails', fn (Blueprint $t) => $t->dropColumn('lead_id'));
        Schema::table('dialer_seen', fn (Blueprint $t) => $t->dropColumn('vm_seen_at'));
    }
};
