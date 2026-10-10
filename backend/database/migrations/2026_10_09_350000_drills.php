<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Emergency drill log (2026-10-09): fire, severe weather, lockdown, vehicle exit, shelter.
   What a licensing inspector asks to see; the rules that say how often come from the
   agency's jurisdiction (App\Support\Jurisdictions → drills). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('drills')) {
            Schema::create('drills', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('centre_id');
                $t->unsignedBigInteger('room_id')->nullable();
                $t->string('type', 20);                       // fire | weather | lockdown | vehicle_exit | shelter | other
                $t->dateTime('drilled_at');                   // UTC
                $t->unsignedInteger('evacuation_seconds')->nullable();
                $t->unsignedInteger('children_count')->nullable();
                $t->string('staff_present', 500)->nullable();
                $t->text('notes')->nullable();
                $t->unsignedBigInteger('recorded_by')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index(['centre_id', 'drilled_at']);
                $t->index(['agency_id', 'drilled_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('drills');
    }
};
