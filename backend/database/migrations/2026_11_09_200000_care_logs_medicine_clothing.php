<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Log a moment: Medicine and Change of clothes (2026-11-09, Anthony: "log a moment add medicine as an option as well
 * as change of clothing"). daily_care_logs.log_type is an ENUM; the two values are APPENDED, so every existing row
 * keeps its value and MySQL changes only the column definition. CareController::logCare validates the same list.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('daily_care_logs')) {
            return;
        }
        $col = DB::selectOne("SHOW COLUMNS FROM daily_care_logs LIKE 'log_type'");
        if (! $col || str_contains((string) $col->Type, "'medicine'")) {
            return;
        }
        preg_match_all("/'([^']+)'/", (string) $col->Type, $m);
        $vals = array_values(array_unique(array_merge($m[1], ['medicine', 'clothing'])));
        $list = implode(',', array_map(fn ($v) => "'" . str_replace("'", "''", $v) . "'", $vals));
        DB::statement("ALTER TABLE daily_care_logs MODIFY log_type ENUM($list) NOT NULL");
    }

    public function down(): void
    {
        // Left in place: removing a value would fail (or blank) any row already logged with it.
    }
};
