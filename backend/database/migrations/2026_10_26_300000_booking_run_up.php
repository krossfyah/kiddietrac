<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* The run-up to a website meeting (2026-10-26, App\Support\BookingRunUp): which emails of the sequence each
   booking has already received, so nobody is sent the same one twice: ["day","safe","onetap","tomorrow:2026-10-29"] */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('marketing_bookings', function (Blueprint $t) {
            if (! Schema::hasColumn('marketing_bookings', 'run_up_sent')) $t->json('run_up_sent')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('marketing_bookings', function (Blueprint $t) {
            if (Schema::hasColumn('marketing_bookings', 'run_up_sent')) $t->dropColumn('run_up_sent');
        });
    }
};
