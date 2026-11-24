<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* The 30-minute reminder for meetings made on the Meetings screen (App\Support\MeetingReminders, 2026-11-13).
   reminded_for = the start time the reminder went for: a moved meeting no longer matches, so it reminds again. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting_attendees', function (Blueprint $t) {
            if (! Schema::hasColumn('meeting_attendees', 'reminded_for')) $t->dateTime('reminded_for')->nullable()->after('invited_at');
            if (! Schema::hasColumn('meeting_attendees', 'reminder_result')) $t->string('reminder_result', 190)->nullable()->after('reminded_for');
        });
    }

    public function down(): void
    {
        Schema::table('meeting_attendees', function (Blueprint $t) {
            $t->dropColumn(['reminded_for', 'reminder_result']);
        });
    }
};
