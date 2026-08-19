<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A walk becomes a thing the day remembers.
 *
 * Walks already existed: a field_trips row with depart_time, return_time and a trail of
 * GPS pings. But the distance was never worked out, and the walk never reached the daily
 * log — so a parent reading their child's day saw no sign of the hour they spent walking
 * to the park, and nobody could answer "how far did they actually go".
 *
 * distance_km is stored rather than recomputed on every read: the pings can be pruned,
 * and what the parent was told the distance was should not change afterwards.
 *
 * Both log tables get 'walk'. They are a pair — daily_events is written by one path and
 * daily_care_logs by another — and widening only one is how the outdoor-play change
 * ended in a 500 last week.
 */
return new class extends Migration
{
    private const EVENTS = ['meal','snack','bottle','nap_start','nap_end','diaper','bathroom',
        'activity','mood','note','incident','medication','sunscreen','outdoor','walk'];

    private const CARE = ['diaper','bathroom','nap','meal','snack','bottle','sunscreen','mood','outdoor','walk'];

    public function up(): void
    {
        if (! Schema::hasColumn('field_trips', 'distance_km')) {
            Schema::table('field_trips', function (Blueprint $table) {
                $table->decimal('distance_km', 6, 2)->nullable()->after('transport_method');
            });
        }

        $this->setEnum('daily_events', 'event_type', self::EVENTS);
        $this->setEnum('daily_care_logs', 'log_type', self::CARE);
    }

    public function down(): void
    {
        // The enums are left wide: narrowing them would fail on any row already using
        // 'walk', and a spare value costs nothing.
        if (Schema::hasColumn('field_trips', 'distance_km')) {
            Schema::table('field_trips', function (Blueprint $table) {
                $table->dropColumn('distance_km');
            });
        }
    }

    private function setEnum(string $table, string $column, array $values): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }
        $list = implode(',', array_map(fn ($v) => "'".$v."'", $values));
        DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` ENUM({$list}) NOT NULL");
    }
};
