<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Educator award history (2026-11-02, App\Support\EducatorAwardHistory). Each award already had its own row with its
 * month, points and amount; this keeps the context a permanent history needs after staff move centres or leave:
 * the earner's name, role and centres at the time, who approved it, and whether it was automatic. Existing rows are
 * filled from what is on record (users, role_assignments, thank_you_auto_drafts), nothing guessed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('educator_rewards', function (Blueprint $t) {
            if (! Schema::hasColumn('educator_rewards', 'source')) $t->string('source', 12)->nullable()->after('method');
            if (! Schema::hasColumn('educator_rewards', 'centre_ids')) $t->string('centre_ids', 500)->nullable()->after('user_id');
            if (! Schema::hasColumn('educator_rewards', 'earner_name')) $t->string('earner_name', 160)->nullable()->after('centre_ids');
            if (! Schema::hasColumn('educator_rewards', 'earner_role')) $t->string('earner_role', 32)->nullable()->after('earner_name');
            if (! Schema::hasColumn('educator_rewards', 'awarded_by_name')) $t->string('awarded_by_name', 160)->nullable()->after('awarded_by_id');
        });
        try {
            Schema::table('educator_rewards', function (Blueprint $t) { $t->index(['agency_id', 'user_id'], 'educator_rewards_agency_user_idx'); });
        } catch (\Throwable $e) {
            // already there
        }
        $r = \App\Support\EducatorAwardHistory::backfill();
        \Illuminate\Support\Facades\Log::info('educator_rewards history snapshot backfill', $r);
    }

    public function down(): void
    {
        Schema::table('educator_rewards', function (Blueprint $t) {
            $t->dropColumn(['source', 'centre_ids', 'earner_name', 'earner_role', 'awarded_by_name']);
        });
    }
};
