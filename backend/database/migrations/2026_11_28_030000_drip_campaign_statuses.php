<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DRIP CAMPAIGNS COULD NEVER BE SAVED (found 2026-11-28 while adding edit / pause / delete): GrowthController wrote
 * status 'active' and audience 'drip-<event>' into enums that allow neither, so every "Create" failed, and
 * drip:dispatch looks for status 'active'. Adds 'active' and 'paused' to the status enum (existing values and the
 * default unchanged); drips now use audience 'custom'.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE marketing_campaigns MODIFY status ENUM('draft','scheduled','sending','sent','archived','active','paused') NOT NULL DEFAULT 'draft'");
    }

    public function down(): void
    {
        DB::statement("UPDATE marketing_campaigns SET status = 'archived' WHERE status IN ('active','paused')");
        DB::statement("ALTER TABLE marketing_campaigns MODIFY status ENUM('draft','scheduled','sending','sent','archived') NOT NULL DEFAULT 'draft'");
    }
};
