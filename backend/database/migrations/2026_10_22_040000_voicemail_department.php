<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Call menu (2026-10-22): which department's mailbox a voicemail was left in. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('voicemails', 'department')) {
            Schema::table('voicemails', fn (Blueprint $t) => $t->string('department', 40)->nullable()->after('id'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('voicemails', 'department')) {
            Schema::table('voicemails', fn (Blueprint $t) => $t->dropColumn('department'));
        }
    }
};
