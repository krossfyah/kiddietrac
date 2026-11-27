<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KiddieTrac HR Phase 4, batch 3 (2026-11-27).
 *  - hr_shift_changes: a queue of changes to someone's upcoming shifts (added, changed, cancelled, removed). The
 *    hr:shift-notices job waits until a person's changes go quiet, nets them per shift and sends ONE notice (bell +
 *    phone + email) per person for the whole batch (App\Support\HrShiftNotices). sent_at + outcome once handled.
 *  - hr_open_shifts: a shift nobody is on yet that eligible staff can claim (App\Support\HrOpenShifts); a director
 *    confirms one claim, which writes the real shifts row (shift_id).
 *  - hr_open_shift_claims: who asked for an open shift (pending | approved | declined | withdrawn).
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('hr_shift_changes')) {
            Schema::create('hr_shift_changes', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('centre_id')->nullable();
                $t->unsignedBigInteger('user_id');
                $t->unsignedBigInteger('shift_id')->nullable();
                $t->string('source', 16)->nullable();          // edit | autofill | standing | nightly | offboard
                $t->text('before')->nullable();                // JSON snapshot {date,start,end,room_id,role,status}
                $t->text('after')->nullable();
                $t->unsignedBigInteger('actor_id')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('sent_at')->nullable();
                $t->string('outcome', 12)->nullable();         // sent | nothing | test | off
                $t->index(['sent_at', 'agency_id', 'user_id']);
            });
        }
        if (! Schema::hasTable('hr_open_shifts')) {
            Schema::create('hr_open_shifts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('centre_id');
                $t->unsignedBigInteger('room_id');
                $t->date('shift_date');
                $t->time('start_time');
                $t->time('end_time');
                $t->string('role', 12)->default('support');    // lead | support | floater
                $t->string('cert_type', 40)->nullable();       // staff_certifications.cert_type the person must hold
                $t->string('note', 300)->nullable();
                $t->string('status', 10)->default('open');     // open | filled | cancelled
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->unsignedBigInteger('shift_id')->nullable();
                $t->unsignedBigInteger('filled_user_id')->nullable();
                $t->unsignedBigInteger('decided_by_id')->nullable();
                $t->dateTime('decided_at')->nullable();
                $t->timestamps();
                $t->index(['agency_id', 'status', 'shift_date']);
                $t->index(['centre_id', 'shift_date']);
            });
        }
        if (! Schema::hasTable('hr_open_shift_claims')) {
            Schema::create('hr_open_shift_claims', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('open_shift_id');
                $t->unsignedBigInteger('user_id');
                $t->string('status', 10)->default('pending');  // pending | approved | declined | withdrawn
                $t->string('note', 300)->nullable();
                $t->unsignedBigInteger('decided_by_id')->nullable();
                $t->dateTime('decided_at')->nullable();
                $t->timestamps();
                $t->unique(['open_shift_id', 'user_id']);
                $t->index(['agency_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_open_shift_claims');
        Schema::dropIfExists('hr_open_shifts');
        Schema::dropIfExists('hr_shift_changes');
    }
};
