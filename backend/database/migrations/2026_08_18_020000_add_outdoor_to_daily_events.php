<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Outdoor play" as a loggable moment.
 *
 * event_type is an ENUM, so the column has to learn the value before anything can write
 * it — an unlisted value is rejected by the database, not silently stored. This is the
 * same shape as the time_punches.source enum that would have failed on its first real run.
 *
 * Daily outdoor time is a licensing expectation in Ontario, so it is worth recording as
 * its own kind rather than folding it into the generic "activity" bucket where it cannot
 * be counted.
 */
return new class extends Migration
{
    private const WITH = "'meal','snack','bottle','nap_start','nap_end','diaper','bathroom',"
        . "'activity','mood','note','incident','medication','sunscreen','outdoor'";

    private const WITHOUT = "'meal','snack','bottle','nap_start','nap_end','diaper','bathroom',"
        . "'activity','mood','note','incident','medication','sunscreen'";

    public function up(): void
    {
        DB::statement('ALTER TABLE daily_events MODIFY event_type ENUM(' . self::WITH . ') NOT NULL');
    }

    public function down(): void
    {
        // Anything already recorded as outdoor would be truncated by a narrowing ALTER,
        // so those rows are folded into the generic bucket first rather than lost.
        DB::table('daily_events')->where('event_type', 'outdoor')->update(['event_type' => 'activity']);
        DB::statement('ALTER TABLE daily_events MODIFY event_type ENUM(' . self::WITHOUT . ') NOT NULL');
    }
};
