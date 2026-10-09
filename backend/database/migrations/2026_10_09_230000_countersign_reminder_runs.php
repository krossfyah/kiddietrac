<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* When each agency last got its "forms waiting for counter-signature" reminder, so a run
   can be claimed once per day with one conditional UPDATE (see CountersignReminders). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('countersign_reminder_runs')) {
            Schema::create('countersign_reminder_runs', function (Blueprint $t) {
                $t->unsignedBigInteger('agency_id')->primary();
                $t->date('last_sent_on')->nullable();
                $t->dateTime('last_sent_at')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('countersign_reminder_runs');
    }
};
