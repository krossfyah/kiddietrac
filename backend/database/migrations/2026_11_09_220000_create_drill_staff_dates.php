<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each educator's fire drill training dates (2026-11-09, Anthony: "configure each educator to set the fire drill
 * renewal dates and expiry dates"). One row per person per agency: when they last renewed it, when it expires, and a
 * note. Set by a director of their centre or an agency admin from the Drill log's Educators tab.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('drill_staff_dates')) {
            return;
        }
        Schema::create('drill_staff_dates', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id');
            $t->unsignedBigInteger('user_id');
            $t->date('renewed_on')->nullable();
            $t->date('expires_on')->nullable();
            $t->string('note', 190)->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
            $t->unique(['agency_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drill_staff_dates');
    }
};
