<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POLICY LIBRARY (2026-10-13) — O. Reg. 137/15 s.6.1 (as amended by O. Reg. 197/26):
 * every required policy, procedure and individualized plan is implemented, reviewed by
 * staff before they work with children and whenever it changes, compliance and
 * contraventions are monitored in writing, and the records are kept for 3 years.
 * s.58(1.1) (from 2027-01-01): training on every required policy. s.46/49: the program
 * statement is reviewed annually.
 *
 * Nothing here is ever purged: policies are archived, versions and sign-offs are kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('policies')) {
            Schema::create('policies', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('centre_id')->nullable()->index();   // null = agency-wide
                $t->string('key', 80)->nullable();                           // catalogue key for seeded entries
                $t->string('title', 190);
                $t->string('purpose', 500)->nullable();
                $t->string('category', 60)->nullable();
                $t->boolean('required')->default(false);
                $t->string('reg_ref', 120)->nullable();                      // e.g. "O. Reg. 137/15 s.46"
                $t->date('required_from')->nullable();                       // e.g. 2027-01-01 for the 2027 amendments
                $t->unsignedSmallInteger('review_frequency_months')->default(12);
                $t->unsignedBigInteger('owner_user_id')->nullable();
                $t->unsignedBigInteger('current_version_id')->nullable();
                $t->date('last_reviewed_at')->nullable();
                $t->date('next_review_due')->nullable();
                $t->boolean('archived')->default(false);
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->index(['agency_id', 'key']);
            });
        }
        if (! Schema::hasTable('policy_versions')) {
            Schema::create('policy_versions', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('policy_id')->index();
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedInteger('version');
                $t->longText('body_text')->nullable();     // what was typed/pasted
                $t->longText('body_html')->nullable();     // the same, escaped and laid out server-side
                $t->string('file_path', 255)->nullable();  // an uploaded PDF/DOCX instead
                $t->string('file_name', 190)->nullable();
                $t->string('file_mime', 120)->nullable();
                $t->string('change_summary', 1000);
                $t->unsignedBigInteger('published_by')->nullable();
                $t->timestamp('published_at')->nullable();
                $t->timestamps();
                $t->unique(['policy_id', 'version']);
            });
        }
        if (! Schema::hasTable('policy_version_views')) {
            Schema::create('policy_version_views', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('policy_version_id');
                $t->unsignedBigInteger('user_id');
                $t->timestamp('first_viewed_at')->nullable();
                $t->timestamp('last_viewed_at')->nullable();
                $t->unique(['policy_version_id', 'user_id']);
            });
        }
        if (! Schema::hasTable('policy_acknowledgements')) {
            Schema::create('policy_acknowledgements', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('policy_version_id');
                $t->unsignedBigInteger('policy_id')->index();
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('user_id')->index();
                $t->timestamp('acknowledged_at')->nullable();
                $t->string('method', 20)->default('e-sign');
                $t->string('typed_name', 190)->nullable();
                $t->text('signature_data')->nullable();
                $t->string('statement', 255)->nullable();
                $t->timestamp('viewed_at')->nullable();
                $t->string('ip', 45)->nullable();
                $t->string('user_agent', 255)->nullable();
                $t->timestamps();
                $t->unique(['policy_version_id', 'user_id']);
            });
        }
        if (! Schema::hasTable('policy_trainings')) {
            Schema::create('policy_trainings', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('policy_id')->index();
                $t->unsignedBigInteger('policy_version_id')->nullable();
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('user_id')->index();
                $t->date('trained_on');
                $t->string('delivered_by', 190)->nullable();
                $t->string('notes', 1000)->nullable();
                $t->unsignedBigInteger('recorded_by')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('policy_reviews')) {
            Schema::create('policy_reviews', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('policy_id')->index();
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('policy_version_id')->nullable();
                $t->date('reviewed_on');
                $t->boolean('changed')->default(false);
                $t->string('notes', 1000)->nullable();
                $t->unsignedBigInteger('reviewed_by')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('policy_contraventions')) {
            Schema::create('policy_contraventions', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('policy_id')->index();
                $t->unsignedBigInteger('centre_id')->nullable()->index();
                $t->timestamp('occurred_at')->nullable();
                $t->text('description');
                $t->text('action_taken')->nullable();
                $t->string('staff_involved', 500)->nullable();
                $t->unsignedBigInteger('recorded_by')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Records of compliance are kept (s.6.1: 3 years). Nothing is dropped on rollback.
    }
};
