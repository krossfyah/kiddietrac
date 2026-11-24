<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A parent's pickup countdown + alarm (2026-11-09, see App\Support\PickupPlan). One row per parent + child.
 * adjust_minutes applies on adjust_date only (today's pickup moved earlier/later). sent_key = the pickup time the
 * alarm last rang for ("Y-m-d H:i"), so it rings once per pickup and again if the parent moves the time.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pickup_alarms')) {
            return;
        }
        Schema::create('pickup_alarms', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('child_id');
            $t->boolean('alarm_on')->default(false);
            $t->unsignedSmallInteger('lead_minutes')->default(15);
            $t->smallInteger('adjust_minutes')->default(0);
            $t->date('adjust_date')->nullable();
            $t->string('sent_key', 16)->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'child_id']);
            $t->index(['alarm_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pickup_alarms');
    }
};
