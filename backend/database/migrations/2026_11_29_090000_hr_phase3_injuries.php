<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KiddieTrac HR Phase 3, item 8 (2026-11-26): STAFF injury and near-miss records, apart from child incidents.
 * Health details (what happened, body part, injury, first aid, medical care, modified duties, check-in notes) are
 * sealed by App\Support\Sensitive (KT_DATA_KEY); lists never select them and every reveal is audited.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('hr_injuries')) {
            Schema::create('hr_injuries', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('centre_id')->nullable()->index();
                $t->unsignedBigInteger('user_id')->index();            // the injured person (staff)
                $t->unsignedBigInteger('reported_by_id')->nullable();
                $t->string('kind', 16)->default('injury');             // injury | near_miss
                $t->dateTime('occurred_at');
                $t->date('learned_on');                                // the day the employer learned of it (Form 7 clock)
                $t->string('location', 200)->nullable();
                $t->text('witnesses')->nullable();
                $t->text('what_happened')->nullable();                 // sealed
                $t->text('body_part')->nullable();                     // sealed
                $t->text('injury_nature')->nullable();                 // sealed
                $t->text('first_aid')->nullable();                     // sealed
                $t->text('medical_care')->nullable();                  // sealed: none | doctor | hospital
                $t->text('medical_notes')->nullable();                 // sealed
                $t->boolean('lost_time')->default(false);
                $t->boolean('form7_needed')->default(false);           // health care beyond first aid, or time lost
                $t->date('form7_filed_on')->nullable();
                $t->string('form7_reference', 80)->nullable();
                $t->date('rtw_start')->nullable();
                $t->date('rtw_end')->nullable();
                $t->text('rtw_duties')->nullable();                    // sealed
                $t->string('status', 16)->default('open');             // open | closed
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('hr_injury_checkins')) {
            Schema::create('hr_injury_checkins', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('injury_id')->index();
                $t->date('on_date');
                $t->text('note')->nullable();                          // sealed
                $t->unsignedBigInteger('by_id')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_injury_checkins');
        Schema::dropIfExists('hr_injuries');
    }
};
