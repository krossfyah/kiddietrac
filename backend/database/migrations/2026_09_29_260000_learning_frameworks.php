<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/* Learning frameworks (2026-09-29). observations.framework and milestone_records.framework
   were ENUM('HDLH','ELECT','ELOF','custom'); an agency may now use BC ELF, Flight, the
   Québec programme, EYFS, HighScope or its own, so they become short strings (existing
   values unchanged). Report cards gain one narrative per framework area. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE observations MODIFY framework VARCHAR(20) NOT NULL DEFAULT 'HDLH'");
        if (Schema::hasTable('milestone_records') && Schema::hasColumn('milestone_records', 'framework')) {
            DB::statement("ALTER TABLE milestone_records MODIFY framework VARCHAR(20) NULL");
        }
        if (! Schema::hasColumn('report_cards', 'narratives')) {
            DB::statement('ALTER TABLE report_cards ADD COLUMN narratives LONGTEXT NULL AFTER narrative_expression, ADD COLUMN framework VARCHAR(20) NULL AFTER narratives');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('report_cards', 'narratives')) {
            DB::statement('ALTER TABLE report_cards DROP COLUMN narratives, DROP COLUMN framework');
        }
    }
};
