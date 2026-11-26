<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * KIDDIETRAC HR, PHASE 2 FOLLOW-UPS (2026-11-28, Anthony). Idempotent, additive only.
 *  - career_applications.declined_at: when an AGENCY applicant was declined, for "Delete declined applicants after N days"
 *    (hr:declined-purge). Backfilled for agency rows already declined (their last move to declined, else updated_at).
 *    Platform careers (agency_id NULL) never read it.
 *  - career_application_events.note: a comment on an applicant (from/to status equal, note set). Platform rows unchanged.
 *  - hr_course_role_rules: "this course goes to everyone in this role", kept, so people who join the role later get it
 *    too (HrTalent::syncRoleCourses). centre_ids NULL = the whole agency; a list = the assigning director's centres.
 *    due_days = due N days after the person gets it. skip_user_ids = people a manager removed from it (not re-added).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('career_applications', function (Blueprint $t) {
            if (! Schema::hasColumn('career_applications', 'declined_at')) $t->timestamp('declined_at')->nullable()->after('status')->index();
        });
        Schema::table('career_application_events', function (Blueprint $t) {
            if (! Schema::hasColumn('career_application_events', 'note')) $t->text('note')->nullable()->after('to_status');
        });
        if (! Schema::hasTable('hr_course_role_rules')) {
            Schema::create('hr_course_role_rules', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('course_id');
                $t->string('role', 30);
                $t->json('centre_ids')->nullable();
                $t->unsignedSmallInteger('due_days')->nullable();
                $t->json('skip_user_ids')->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamp('archived_at')->nullable();
                $t->timestamps();
                $t->index(['agency_id', 'course_id']);
            });
        }

        foreach (DB::table('career_applications')->whereNotNull('agency_id')->where('status', 'declined')->whereNull('declined_at')->get(['id', 'updated_at']) as $a) {
            $at = DB::table('career_application_events')->where('application_id', $a->id)->where('to_status', 'declined')->max('created_at');
            DB::table('career_applications')->where('id', $a->id)->update(['declined_at' => $at ?: $a->updated_at ?: now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_course_role_rules');
    }
};
