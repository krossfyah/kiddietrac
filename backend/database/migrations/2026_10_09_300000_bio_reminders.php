<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Provider bio reminders (2026-10-09): when each agency's run last went out, and how many
   reminders each provider has had (so max_reminders can stop chasing someone). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bio_reminder_runs')) {
            Schema::create('bio_reminder_runs', function (Blueprint $t) {
                $t->unsignedBigInteger('agency_id')->primary();
                $t->date('last_sent_on')->nullable();
                $t->dateTime('last_sent_at')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('bio_reminder_people')) {
            Schema::create('bio_reminder_people', function (Blueprint $t) {
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('user_id');
                $t->unsignedInteger('reminders')->default(0);
                $t->dateTime('last_reminded_at')->nullable();
                $t->primary(['agency_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bio_reminder_people');
        Schema::dropIfExists('bio_reminder_runs');
    }
};
