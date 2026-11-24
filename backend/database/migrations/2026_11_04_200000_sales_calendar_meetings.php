<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Meetings on the sales@ calendar matched to leads, and their 30-minute reminders (App\Support\SalesMeetings). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_calendar_meetings')) return;
        Schema::create('sales_calendar_meetings', function (Blueprint $t) {
            $t->id();
            $t->char('event_hash', 40);
            $t->text('event_id');
            $t->unsignedBigInteger('lead_id');
            $t->string('email', 190)->nullable();
            $t->string('subject', 250)->nullable();
            $t->string('location', 250)->nullable();
            $t->text('join_url')->nullable();
            $t->dateTime('starts_at');
            $t->dateTime('ends_at');
            $t->unsignedBigInteger('booking_id')->nullable();
            $t->unsignedBigInteger('activity_id')->nullable();
            $t->string('status', 20)->default('scheduled');
            $t->dateTime('email_reminded_at')->nullable();
            $t->dateTime('sms_reminded_at')->nullable();
            $t->string('sms_result', 190)->nullable();
            $t->timestamps();
            $t->unique(['event_hash', 'lead_id']);
            $t->index(['status', 'starts_at']);
            $t->index('lead_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_calendar_meetings');
    }
};
