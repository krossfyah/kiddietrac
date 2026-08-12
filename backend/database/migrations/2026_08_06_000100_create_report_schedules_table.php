<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scheduled reports (2026-08-06).
 *
 * An admin schedules a canned report to be emailed (PDF and/or CSV) to a portal
 * user or a typed address, on a daily / weekly / monthly cadence at a chosen
 * time. The kiddietrac:send-scheduled-reports command fires due rows hourly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_schedules', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id')->index();
            $t->string('report_type', 40);                 // matches ReportsController cannedDefs keys
            $t->unsignedBigInteger('centre_id')->nullable();
            $t->string('format', 8)->default('pdf');        // pdf | csv | both
            $t->string('frequency', 12)->default('weekly'); // daily | weekly | monthly
            $t->unsignedTinyInteger('day_of_week')->nullable();   // 0=Sun..6=Sat (weekly)
            $t->unsignedTinyInteger('day_of_month')->nullable();  // 1..28 (monthly)
            $t->string('send_time', 5)->default('07:00');   // HH:MM (server tz)
            $t->string('range_kind', 20)->default('last_7d'); // last_7d|last_30d|this_month|last_month|all
            $t->unsignedBigInteger('recipient_user_id')->nullable();
            $t->string('recipient_email', 190)->nullable();
            $t->boolean('active')->default(true);
            $t->date('last_sent_on')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->index(['active', 'frequency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_schedules');
    }
};
