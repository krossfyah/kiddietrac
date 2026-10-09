<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* "Incomplete" RSVP reminders (2026-10-09): which question was left blank (null = any). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('rsvp_reminders', 'field_key')) {
            Schema::table('rsvp_reminders', function (Blueprint $t) {
                $t->string('field_key', 60)->nullable()->after('audience');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('rsvp_reminders', 'field_key')) {
            Schema::table('rsvp_reminders', function (Blueprint $t) { $t->dropColumn('field_key'); });
        }
    }
};
