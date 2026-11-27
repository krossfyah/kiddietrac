<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KiddieTrac HR Phase 4, batch 6 (2026-11-27): course quizzes, staff directory, recognition, approvers.
 *
 * - hr_courses.pass_mark (percent, null = no quiz) + max_attempts (null = no limit); hr_course_questions (the quiz,
 *   options + the right answers as JSON, never sent to the person taking it); hr_course_attempts (one row per try, the
 *   answers given, score, passed); hr_course_assignments.extra_attempts (a manager allowed more tries).
 * - hr_directory_shares: what a person chose to show colleagues in the staff directory (their email / phone). No row =
 *   nothing personal shown.
 * - hr_kudos: peer recognition (from, to, value tags, message). Points come from EducatorPoints (activity 'kudos'),
 *   worked out from these rows like every other activity, never stored. hidden_at = taken down by a manager.
 * - hr_approver_grants: kind 'people' (an admin lets someone approve time off for named people, optionally only some
 *   leave types) or 'cover' (a director's approvals go to another manager from starts_on to ends_on, then stop by
 *   themselves). revoked_at ends either early. Every change is audited.
 * Additive and idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_courses', function (Blueprint $t) {
            if (! Schema::hasColumn('hr_courses', 'pass_mark')) $t->unsignedTinyInteger('pass_mark')->nullable();
            if (! Schema::hasColumn('hr_courses', 'max_attempts')) $t->unsignedTinyInteger('max_attempts')->nullable();
        });
        Schema::table('hr_course_assignments', function (Blueprint $t) {
            if (! Schema::hasColumn('hr_course_assignments', 'extra_attempts')) $t->unsignedTinyInteger('extra_attempts')->default(0);
        });
        if (! Schema::hasTable('hr_course_questions')) {
            Schema::create('hr_course_questions', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('course_id')->index();
                $t->unsignedSmallInteger('position')->default(0);
                $t->text('prompt');
                $t->string('kind', 10)->default('single');   // single | multi
                $t->json('options');
                $t->json('correct');
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('hr_course_attempts')) {
            Schema::create('hr_course_attempts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('assignment_id')->index();
                $t->unsignedBigInteger('course_id');
                $t->unsignedBigInteger('user_id')->index();
                $t->json('answers');
                $t->unsignedSmallInteger('right')->default(0);
                $t->unsignedSmallInteger('total')->default(0);
                $t->unsignedTinyInteger('score')->default(0);
                $t->boolean('passed')->default(false);
                $t->timestamp('created_at')->nullable();
            });
        }
        if (! Schema::hasTable('hr_directory_shares')) {
            Schema::create('hr_directory_shares', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('user_id');
                $t->boolean('share_email')->default(false);
                $t->boolean('share_phone')->default(false);
                $t->timestamps();
                $t->unique(['agency_id', 'user_id']);
            });
        }
        if (! Schema::hasTable('hr_kudos')) {
            Schema::create('hr_kudos', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('from_user_id')->index();
                $t->unsignedBigInteger('to_user_id')->index();
                $t->unsignedBigInteger('centre_id')->nullable();
                $t->json('tags')->nullable();
                $t->string('message', 600);
                $t->timestamp('hidden_at')->nullable();
                $t->unsignedBigInteger('hidden_by_id')->nullable();
                $t->timestamps();
                $t->index(['agency_id', 'created_at']);
            });
        }
        if (! Schema::hasTable('hr_approver_grants')) {
            Schema::create('hr_approver_grants', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->string('kind', 10);                                    // people | cover
                $t->unsignedBigInteger('approver_user_id')->index();
                $t->json('subject_user_ids')->nullable();                  // people: whose time off
                $t->json('leave_type_ids')->nullable();                    // people: only these types (null = all)
                $t->unsignedBigInteger('covering_for_user_id')->nullable(); // cover: the director who is away
                $t->date('starts_on')->nullable();
                $t->date('ends_on')->nullable();
                $t->string('note', 300)->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamp('revoked_at')->nullable();
                $t->unsignedBigInteger('revoked_by_id')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach (['hr_approver_grants', 'hr_kudos', 'hr_directory_shares', 'hr_course_attempts', 'hr_course_questions'] as $x) Schema::dropIfExists($x);
        Schema::table('hr_course_assignments', function (Blueprint $t) {
            if (Schema::hasColumn('hr_course_assignments', 'extra_attempts')) $t->dropColumn('extra_attempts');
        });
        Schema::table('hr_courses', function (Blueprint $t) {
            foreach (['pass_mark', 'max_attempts'] as $c) if (Schema::hasColumn('hr_courses', $c)) $t->dropColumn($c);
        });
    }
};
