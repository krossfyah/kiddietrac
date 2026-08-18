<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Outdoor play" on the OTHER care table.
 *
 * A care moment can be recorded from two places into two different tables: the
 * "Log a moment" screen writes daily_care_logs.log_type, while the room roster's
 * quick-log writes daily_events.event_type. Both are enums, and a value has to exist in
 * whichever one the write path uses — adding it to daily_events alone left the moment
 * screen returning a 500, because that is not the table it writes to.
 *
 * Both are widened, since either route may legitimately record outdoor play.
 */
return new class extends Migration
{
    private const WITH = "'diaper','bathroom','nap','meal','snack','bottle','sunscreen','mood','outdoor'";
    private const WITHOUT = "'diaper','bathroom','nap','meal','snack','bottle','sunscreen','mood'";

    public function up(): void
    {
        DB::statement('ALTER TABLE daily_care_logs MODIFY log_type ENUM(' . self::WITH . ') NOT NULL');
    }

    public function down(): void
    {
        // A narrowing ALTER would truncate anything already logged as outdoor, so those
        // rows are moved to the nearest surviving kind rather than silently emptied.
        DB::table('daily_care_logs')->where('log_type', 'outdoor')->update(['log_type' => 'mood']);
        DB::statement('ALTER TABLE daily_care_logs MODIFY log_type ENUM(' . self::WITHOUT . ') NOT NULL');
    }
};
