<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* OCCMS monthly reporting (2026-10-13). Ontario operators contracted for fee subsidy enter a
   Record of Attendance (ROA) in OCCMS each month (York: by the 8th of the following month),
   and CWELCC enrolment / capacity. There is no OCCMS API, so KiddieTrac keeps the working
   copy: App\Support\OccmsRoa derives each subsidized child's daily code from attendance,
   reported absences and closures; staff correct cells, review, sign off and record when it
   was entered in OCCMS.

   roa_months  one row per centre per month: status draft → reviewed → signed_off →
               submitted, who did each step, the operating capacity reported, and the frozen
               grid (snapshot) from sign-off onwards.
   roa_cells   a cell someone changed by hand (one per centre/child/day). Everything else is
               derived at the point of asking, never materialised.
   roa_events  the audit trail: every cell change (old → new) and every status step. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roa_months')) {
            Schema::create('roa_months', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('centre_id');
                $t->char('month', 7);                                   // YYYY-MM
                $t->string('status', 16)->default('draft');            // draft|reviewed|signed_off|submitted
                $t->unsignedBigInteger('reviewed_by_id')->nullable();
                $t->timestamp('reviewed_at')->nullable();
                $t->unsignedBigInteger('signed_off_by_id')->nullable();
                $t->timestamp('signed_off_at')->nullable();
                $t->unsignedBigInteger('submitted_by_id')->nullable();
                $t->timestamp('submitted_at')->nullable();
                $t->date('occms_entered_on')->nullable();               // the day it was entered in OCCMS
                $t->string('occms_reference', 120)->nullable();
                $t->unsignedSmallInteger('amendments')->default(0);
                $t->text('capacity')->nullable();                       // JSON {age_group: operating}
                $t->longText('snapshot')->nullable();                   // JSON grid frozen at sign-off
                $t->timestamps();
                $t->unique(['centre_id', 'month']);
            });
        }
        if (! Schema::hasTable('roa_cells')) {
            Schema::create('roa_cells', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('centre_id');
                $t->unsignedBigInteger('child_id');
                $t->date('day');
                $t->string('code', 2)->default('');                     // '' = no care that day
                $t->string('note', 255)->nullable();
                $t->unsignedBigInteger('set_by_id')->nullable();
                $t->timestamps();
                $t->unique(['centre_id', 'child_id', 'day']);
                $t->index(['centre_id', 'day']);
            });
        }
        if (! Schema::hasTable('roa_events')) {
            Schema::create('roa_events', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('centre_id');
                $t->char('month', 7);
                $t->string('action', 24);                               // cell|review|signoff|submit|reopen|amend|capacity
                $t->unsignedBigInteger('child_id')->nullable();
                $t->date('day')->nullable();
                $t->string('old_code', 2)->nullable();
                $t->string('new_code', 2)->nullable();
                $t->string('note', 500)->nullable();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->index(['centre_id', 'month']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('roa_events');
        Schema::dropIfExists('roa_cells');
        Schema::dropIfExists('roa_months');
    }
};
