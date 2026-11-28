<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* NPS surveys that actually go out (2026-11-28): a survey round per agency, one invite per person with a signed link. */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('nps_surveys')) {
            Schema::create('nps_surveys', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->boolean('families')->default(true);
                $t->boolean('staff')->default(false);
                $t->string('source', 20);                    // send_now | schedule
                $t->unsignedBigInteger('started_by')->nullable();
                $t->unsignedInteger('invited')->default(0);
                $t->unsignedInteger('emailed')->default(0);
                $t->timestamp('delivered_at')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('nps_invites')) {
            Schema::create('nps_invites', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('survey_id');
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('centre_id')->nullable();
                $t->unsignedBigInteger('user_id');
                $t->unsignedBigInteger('family_id')->nullable();
                $t->string('audience', 10);                  // family | staff
                $t->char('token_hash', 64)->nullable()->unique();
                $t->timestamp('expires_at')->nullable();
                $t->string('status', 20)->default('pending'); // pending | sent | blocked | failed
                $t->timestamp('sent_at')->nullable();
                $t->timestamp('answered_at')->nullable();
                $t->unsignedBigInteger('response_id')->nullable();
                $t->timestamps();
                $t->unique(['survey_id', 'user_id']);
                $t->index(['user_id', 'answered_at']);
                $t->index(['status', 'survey_id']);
            });
        }
        Schema::table('nps_responses', function (Blueprint $t) {
            if (! Schema::hasColumn('nps_responses', 'audience')) $t->string('audience', 10)->default('family')->after('user_id');
            if (! Schema::hasColumn('nps_responses', 'survey_id')) $t->unsignedBigInteger('survey_id')->nullable()->after('audience');
            if (! Schema::hasColumn('nps_responses', 'invite_id')) $t->unsignedBigInteger('invite_id')->nullable()->after('survey_id');
            if (! Schema::hasColumn('nps_responses', 'updated_at')) $t->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nps_invites');
        Schema::dropIfExists('nps_surveys');
        Schema::table('nps_responses', fn (Blueprint $t) => $t->dropColumn(['audience', 'survey_id', 'invite_id', 'updated_at']));
    }
};
