<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The daily intake-form reminder: the Eastern day it last went, and how many (2026-10-23). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_bookings', function (Blueprint $t) {
            if (! Schema::hasColumn('marketing_bookings', 'intake_reminded_on')) $t->date('intake_reminded_on')->nullable();
            if (! Schema::hasColumn('marketing_bookings', 'intake_reminders')) $t->unsignedSmallInteger('intake_reminders')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('marketing_bookings', function (Blueprint $t) {
            $t->dropColumn(['intake_reminded_on', 'intake_reminders']);
        });
    }
};
