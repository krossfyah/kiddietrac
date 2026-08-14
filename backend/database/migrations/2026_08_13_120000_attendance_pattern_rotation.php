<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A day in an attendance pattern was a BOOLEAN: the child comes, or does not. That
 * cannot express how childcare is actually sold or staffed — mornings only,
 * afternoons only, before and after school, a full day — and those distinctions are
 * exactly what ratios, billing and room planning turn on.
 *
 * Each day becomes a short rotation code instead:
 *
 *   full    all day            am      mornings only
 *   pm      afternoons only    before  before school
 *   after   after school       bna     before AND after school
 *   (null)  not attending
 *
 * Existing rows are booleans, so 1 becomes 'full' and 0/NULL becomes NULL — nobody's
 * pattern changes meaning. The columns are varchar rather than an enum on purpose:
 * this list will grow (lunch clubs, half-day kindergarten), and an enum change on a
 * live table is a far worse day than a string.
 */
return new class extends Migration
{
    private const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    public function up(): void
    {
        foreach (self::DAYS as $d) {
            DB::statement("ALTER TABLE attendance_patterns MODIFY `$d` VARCHAR(12) NULL");
        }
        // Booleans → rotation codes. '1' is a full day; anything falsey is no day.
        foreach (self::DAYS as $d) {
            DB::table('attendance_patterns')->where($d, '1')->update([$d => 'full']);
            DB::table('attendance_patterns')->where($d, '0')->update([$d => null]);
        }

        // Everything else the pattern should have said: which room the child is in
        // for it, whether the pattern is live, and who last changed it.
        Schema::table('attendance_patterns', function ($table) {
            if (! Schema::hasColumn('attendance_patterns', 'room_id')) $table->unsignedBigInteger('room_id')->nullable()->index();
            if (! Schema::hasColumn('attendance_patterns', 'active')) $table->boolean('active')->default(true)->index();
            if (! Schema::hasColumn('attendance_patterns', 'updated_by_id')) $table->unsignedBigInteger('updated_by_id')->nullable();
            if (! Schema::hasColumn('attendance_patterns', 'created_at')) $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        foreach (self::DAYS as $d) {
            DB::table('attendance_patterns')->whereNotNull($d)->update([$d => '1']);
            DB::statement("ALTER TABLE attendance_patterns MODIFY `$d` TINYINT(1) NULL");
        }
        Schema::table('attendance_patterns', function ($table) {
            foreach (['room_id', 'active', 'updated_by_id', 'created_at'] as $c) {
                if (Schema::hasColumn('attendance_patterns', $c)) $table->dropColumn($c);
            }
        });
    }
};
