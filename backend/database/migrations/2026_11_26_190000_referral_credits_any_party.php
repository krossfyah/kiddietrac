<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/* REFERRAL CREDITS, ANY PARTY OR NONE (2026-11-26, Anthony): the referred party can be a Sales lead OR a customer
   agency with no lead, or nobody at all (a general one-time credit with a required reason). One live credit per
   referred party still holds: live_lead_id (existing) and live_agency_id are unique and NULL when there is none. */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('referral_credits', 'referred_agency_id')) return;
        DB::statement('ALTER TABLE referral_credits MODIFY referred_lead_id BIGINT UNSIGNED NULL');
        DB::statement("ALTER TABLE referral_credits ADD referred_agency_id BIGINT UNSIGNED NULL AFTER referred_lead_id,
            ADD live_agency_id BIGINT UNSIGNED GENERATED ALWAYS AS (IF(status <> 'cancelled', referred_agency_id, NULL)) STORED,
            ADD UNIQUE KEY referral_credits_live_agency_unique (live_agency_id)");
    }

    public function down(): void
    {
        if (! Schema::hasColumn('referral_credits', 'referred_agency_id')) return;
        DB::statement('ALTER TABLE referral_credits DROP INDEX referral_credits_live_agency_unique, DROP COLUMN live_agency_id, DROP COLUMN referred_agency_id');
    }
};
