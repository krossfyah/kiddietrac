<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NO-SHOW ON SALES MEETINGS (2026-11-28, App\Support\NoShow). A lead meeting that has started can be marked
 * held or no_show (meetings.status / marketing_bookings.status are plain strings: the new words are
 * held, no_show, rescheduled). These columns record who marked it and when, when the "pick a new time"
 * email/text went, and which new booking replaced it. Idempotent: every column is added only if missing.
 */
return new class extends Migration
{
    private const COLS = ['outcome_at', 'outcome_by', 'noshow_asked_at', 'rebooked_booking_id'];

    public function up(): void
    {
        foreach (['meetings', 'marketing_bookings'] as $t) {
            if (! Schema::hasTable($t)) continue;
            Schema::table($t, function (Blueprint $table) use ($t) {
                if (! Schema::hasColumn($t, 'outcome_at')) $table->timestamp('outcome_at')->nullable();
                if (! Schema::hasColumn($t, 'outcome_by')) $table->unsignedBigInteger('outcome_by')->nullable();
                if (! Schema::hasColumn($t, 'noshow_asked_at')) $table->timestamp('noshow_asked_at')->nullable();
                if (! Schema::hasColumn($t, 'rebooked_booking_id')) $table->unsignedBigInteger('rebooked_booking_id')->nullable();
            });
        }
        if (Schema::hasTable('marketing_bookings') && ! Schema::hasColumn('marketing_bookings', 'rebook_of')) {
            Schema::table('marketing_bookings', function (Blueprint $table) {
                $table->string('rebook_of', 30)->nullable();   // 'meeting:9' / 'booking:3' - the missed meeting this one replaces
            });
        }
    }

    public function down(): void
    {
        foreach (['meetings', 'marketing_bookings'] as $t) {
            if (! Schema::hasTable($t)) continue;
            foreach (array_merge(self::COLS, $t === 'marketing_bookings' ? ['rebook_of'] : []) as $c) {
                if (Schema::hasColumn($t, $c)) Schema::table($t, fn (Blueprint $table) => $table->dropColumn($c));
            }
        }
    }
};
