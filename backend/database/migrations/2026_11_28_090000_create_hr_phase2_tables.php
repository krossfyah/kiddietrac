<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * KIDDIETRAC HR, PHASE 2 (2026-11-28): hiring, onboarding, performance, courses. Idempotent: every table and column
 * is created only when missing, nothing is dropped or renamed.
 *
 * Hiring reuses career_applications (KiddieTrac's own careers pipeline). agency_id NULL = a www.kiddietrac.com
 * application (Platform -> Careers, unchanged); a number = an application to that agency's job post.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('career_applications', function (Blueprint $t) {
            if (! Schema::hasColumn('career_applications', 'agency_id')) $t->unsignedBigInteger('agency_id')->nullable()->after('id')->index();
            if (! Schema::hasColumn('career_applications', 'job_post_id')) $t->unsignedBigInteger('job_post_id')->nullable()->after('agency_id')->index();
            if (! Schema::hasColumn('career_applications', 'centre_id')) $t->unsignedBigInteger('centre_id')->nullable()->after('job_post_id');
            if (! Schema::hasColumn('career_applications', 'hired_user_id')) $t->unsignedBigInteger('hired_user_id')->nullable()->after('meeting_id');
        });

        if (! Schema::hasTable('hr_job_posts')) {
            Schema::create('hr_job_posts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('centre_id')->nullable();
                $t->string('title', 120);
                $t->string('employment_type', 20)->nullable();
                $t->decimal('pay_min', 10, 2)->nullable();
                $t->decimal('pay_max', 10, 2)->nullable();
                $t->string('pay_unit', 10)->nullable();          // hour | year
                $t->text('description')->nullable();
                $t->string('status', 10)->default('open');       // open | closed
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamp('closed_at')->nullable();
                $t->timestamps();
                $t->index(['agency_id', 'status']);
            });
        }

        if (! Schema::hasTable('hr_onboarding_templates')) {
            Schema::create('hr_onboarding_templates', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->string('name', 120);
                $t->string('role', 30)->nullable();              // educator | centre_director | home_visitor | agency_admin | null = anyone
                $t->timestamp('archived_at')->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('hr_onboarding_template_tasks')) {
            Schema::create('hr_onboarding_template_tasks', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('template_id')->index();
                $t->string('kind', 20);                          // document | certificate | policy | course | custom
                $t->string('title', 160);
                $t->text('detail')->nullable();
                $t->unsignedBigInteger('ref_id')->nullable();    // policy id | course id
                $t->string('ref_key', 60)->nullable();           // certificate type code
                $t->integer('due_days')->default(0);             // days after the start date
                $t->integer('sort')->default(0);
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('hr_onboardings')) {
            Schema::create('hr_onboardings', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('user_id');
                $t->unsignedBigInteger('template_id')->nullable();
                $t->unsignedBigInteger('application_id')->nullable();
                $t->date('start_date');
                $t->string('status', 12)->default('active');     // active | complete | cancelled
                $t->timestamp('completed_at')->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();
                $t->index(['agency_id', 'user_id']);
            });
        }
        if (! Schema::hasTable('hr_onboarding_tasks')) {
            Schema::create('hr_onboarding_tasks', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('onboarding_id')->index();
                $t->unsignedBigInteger('user_id');
                $t->string('kind', 20);
                $t->string('title', 160);
                $t->text('detail')->nullable();
                $t->unsignedBigInteger('ref_id')->nullable();
                $t->string('ref_key', 60)->nullable();
                $t->date('due_date')->nullable();
                $t->integer('sort')->default(0);
                $t->timestamp('done_at')->nullable();
                $t->unsignedBigInteger('done_by_id')->nullable();
                $t->string('done_how', 10)->nullable();         // auto | self | manager | upload
                $t->string('file_path', 255)->nullable();
                $t->string('file_name', 160)->nullable();
                $t->date('reminded_on')->nullable();
                $t->timestamps();
                $t->index(['agency_id', 'user_id']);
            });
        }

        if (! Schema::hasTable('hr_review_templates')) {
            Schema::create('hr_review_templates', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->string('name', 120);
                $t->text('scale');                               // JSON [{value, label}]
                $t->text('sections');                            // JSON [{title, prompt}]
                $t->timestamp('archived_at')->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('hr_reviews')) {
            Schema::create('hr_reviews', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('user_id');
                $t->unsignedBigInteger('manager_user_id');
                $t->unsignedBigInteger('template_id')->nullable();
                $t->string('cycle', 12);                          // probation | annual | custom
                $t->string('title', 160);
                $t->date('period_start')->nullable();
                $t->date('period_end')->nullable();
                $t->date('due_date')->nullable();
                $t->text('scale');                                // snapshot of the template when the review began
                $t->text('sections');
                $t->longText('self_answers')->nullable();         // JSON {sections:[{rating, comment}], overall}
                $t->longText('manager_answers')->nullable();
                $t->timestamp('self_submitted_at')->nullable();
                $t->timestamp('manager_submitted_at')->nullable();
                $t->timestamp('employee_signed_at')->nullable();
                $t->string('employee_signed_name', 120)->nullable();
                $t->timestamp('manager_signed_at')->nullable();
                $t->string('manager_signed_name', 120)->nullable();
                $t->string('status', 14)->default('open');        // open | self_done | manager_done | signing | complete | cancelled
                $t->date('reminded_on')->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();
                $t->index(['agency_id', 'user_id']);
                $t->index(['agency_id', 'manager_user_id']);
            });
        }
        if (! Schema::hasTable('hr_goals')) {
            Schema::create('hr_goals', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('user_id');
                $t->unsignedBigInteger('review_id')->nullable();
                $t->string('title', 200);
                $t->text('detail')->nullable();
                $t->date('due_date')->nullable();
                $t->string('status', 12)->default('not_started'); // not_started | on_track | at_risk | done | dropped
                $t->unsignedTinyInteger('progress')->default(0);
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();
                $t->index(['agency_id', 'user_id']);
            });
        }
        if (! Schema::hasTable('hr_goal_checkins')) {
            Schema::create('hr_goal_checkins', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('goal_id')->index();
                $t->unsignedBigInteger('author_id');
                $t->text('note');
                $t->string('status', 12);
                $t->unsignedTinyInteger('progress')->default(0);
                $t->timestamp('created_at')->nullable();
            });
        }
        if (! Schema::hasTable('hr_one_on_ones')) {
            Schema::create('hr_one_on_ones', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('user_id');
                $t->unsignedBigInteger('manager_user_id');
                $t->date('met_on');
                $t->text('notes');
                $t->text('next_steps')->nullable();
                $t->unsignedBigInteger('author_id');
                $t->timestamps();
                $t->index(['agency_id', 'user_id']);
            });
        }

        if (! Schema::hasTable('hr_courses')) {
            Schema::create('hr_courses', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->string('title', 160);
                $t->text('description')->nullable();
                $t->string('content_kind', 12);                   // link | video | file | library
                $t->string('url', 500)->nullable();
                $t->string('file_path', 255)->nullable();
                $t->string('file_name', 160)->nullable();
                $t->unsignedBigInteger('training_video_id')->nullable();
                $t->decimal('pd_hours', 5, 2)->nullable();
                $t->boolean('counts_pd')->default(false);
                $t->boolean('needs_ack')->default(true);
                $t->timestamp('archived_at')->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('hr_course_assignments')) {
            Schema::create('hr_course_assignments', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('course_id');
                $t->unsignedBigInteger('user_id');
                $t->string('assigned_role', 30)->nullable();
                $t->date('due_date')->nullable();
                $t->timestamp('completed_at')->nullable();
                $t->string('acknowledged_name', 120)->nullable();
                $t->string('certificate_no', 30)->nullable();
                $t->unsignedBigInteger('pd_hours_id')->nullable();
                $t->unsignedBigInteger('assigned_by_id')->nullable();
                $t->date('reminded_on')->nullable();
                $t->timestamps();
                $t->unique(['course_id', 'user_id']);
                $t->index(['agency_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        foreach (['hr_course_assignments', 'hr_courses', 'hr_one_on_ones', 'hr_goal_checkins', 'hr_goals', 'hr_reviews', 'hr_review_templates',
            'hr_onboarding_tasks', 'hr_onboardings', 'hr_onboarding_template_tasks', 'hr_onboarding_templates', 'hr_job_posts'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
