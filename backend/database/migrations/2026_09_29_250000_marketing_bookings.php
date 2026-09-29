<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/* Meetings booked from the marketing site's booking wizard (2026-09-29). starts_at /
   ends_at are UTC; local_date / local_time are what the visitor chose, in `tz`. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('marketing_bookings')) return;
        Schema::create('marketing_bookings', function (Blueprint $t) {
            $t->id();
            $t->string('type', 20);
            $t->unsignedSmallInteger('duration_min');
            $t->dateTime('starts_at')->index();
            $t->dateTime('ends_at');
            $t->date('local_date');
            $t->string('local_time', 5);
            $t->string('tz', 40);
            $t->string('name', 120);
            $t->string('email', 160)->index();
            $t->string('agency', 160)->nullable();
            $t->string('phone', 40)->nullable();
            $t->string('meet_via', 10)->default('video');
            $t->string('children', 40)->nullable();
            $t->text('notes')->nullable();
            $t->string('visitor_tz', 60)->nullable();
            $t->string('lang', 5)->default('en');
            $t->string('status', 20)->default('booked')->index();
            $t->unsignedBigInteger('sales_lead_id')->nullable();
            $t->string('sales_mail', 10)->nullable();
            $t->string('visitor_mail', 10)->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamps();
        });
        DB::statement('ALTER TABLE marketing_bookings CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_bookings');
    }
};
