<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extend the EXISTING parent_feedback table (v22p55) for day-of-care feedback.
 *
 * There was already a working feature here — 1-5 rating, category, comment, image
 * attachments, low-rating alerts to directors — reached from the portal by a signed-in
 * parent. What it could not do was: be answered from the daily summary EMAIL without
 * signing in, name the educator being thanked, carry a note for tomorrow, or be seen by
 * the educator it is about.
 *
 * So this ADDS columns rather than creating a second feedback table. (2026-08-25)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('parent_feedback')) {
            return;   // nothing to extend; the v22p55 migration owns creation
        }

        Schema::table('parent_feedback', function (Blueprint $table) {
            // The day being commented on, in the AGENCY's timezone — a date, not a
            // timestamp, so "Tuesday" does not drift with the reader's zone. Null for
            // the older portal-submitted rows, which were about no particular day.
            if (! Schema::hasColumn('parent_feedback', 'for_date')) {
                $table->date('for_date')->nullable()->after('centre_id');
            }
            // The educator being thanked, when the parent picked one.
            if (! Schema::hasColumn('parent_feedback', 'educator_id')) {
                $table->unsignedBigInteger('educator_id')->nullable()->after('for_date');
            }
            // "Anything we should know for tomorrow?" — read before the next morning.
            if (! Schema::hasColumn('parent_feedback', 'tomorrow_note')) {
                $table->text('tomorrow_note')->nullable()->after('comment');
            }
            // A signed email link identifies a FAMILY, not a person, so user_id stays
            // null for those. This records how it arrived.
            if (! Schema::hasColumn('parent_feedback', 'source')) {
                $table->string('source', 20)->nullable()->after('tomorrow_note');
            }
            if (! Schema::hasColumn('parent_feedback', 'child_id')) {
                $table->unsignedBigInteger('child_id')->nullable()->after('family_id');
            }
        });

        // Indexes added separately so a partially-applied run can be re-run safely.
        Schema::table('parent_feedback', function (Blueprint $table) {
            try { $table->index('educator_id'); } catch (\Throwable $e) {}
            try { $table->index('for_date'); } catch (\Throwable $e) {}
            try { $table->index('child_id'); } catch (\Throwable $e) {}
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('parent_feedback')) {
            return;
        }
        Schema::table('parent_feedback', function (Blueprint $table) {
            foreach (['for_date', 'educator_id', 'tomorrow_note', 'source', 'child_id'] as $c) {
                if (Schema::hasColumn('parent_feedback', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
