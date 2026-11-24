<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * "ENTER THE REAL TIME" LINKS (2026-10-22). Anthony: the emails sent when the nightly job
 * signs a child out (to parents) or clocks an educator out (to the educator) should carry a
 * link where they fill in the actual time, and "have this updated in our systems". His call:
 * the time is applied at once, clearly marked, and staff are told and can adjust it.
 *
 *   auto_time_fix_links  one row per (record, recipient): only the SHA-256 of the token is
 *                        kept, single use, expires after 7 days.
 *   check_events / time_punches gain the correction trail: who corrected the automatic
 *                        time, from where, what it was, and their note. is_automatic and
 *                        source='auto' are left as they are on purpose — nobody signed out
 *                        at the time, and the compliance report still needs to say so.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('auto_time_fix_links')) {
            Schema::create('auto_time_fix_links', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('kind', 10);                      // child | staff
                $t->unsignedBigInteger('record_id');         // check_events.id | time_punches.id
                $t->unsignedBigInteger('agency_id')->nullable();
                $t->unsignedBigInteger('centre_id')->nullable();
                $t->unsignedBigInteger('recipient_user_id');
                $t->dateTime('auto_at');                     // UTC, the automatic time when the link was issued
                $t->char('token_hash', 64)->unique();
                $t->dateTime('expires_at');
                $t->dateTime('used_at')->nullable();
                $t->dateTime('submitted_time')->nullable();  // UTC, what they entered
                $t->timestamp('created_at')->nullable();
                $t->index(['kind', 'record_id']);
            });
        }
        foreach (['check_events', 'time_punches'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (! Schema::hasColumn($table, 'correction_source')) {
                    $t->string('correction_source', 20)->nullable();        // parent_email | educator_email | staff
                }
                if (! Schema::hasColumn($table, 'original_auto_at')) {
                    $t->dateTime('original_auto_at')->nullable();           // UTC, the automatic time it replaced
                }
                if (! Schema::hasColumn($table, 'corrected_by_user_id')) {
                    $t->unsignedBigInteger('corrected_by_user_id')->nullable();
                }
                if (! Schema::hasColumn($table, 'corrected_at')) {
                    $t->dateTime('corrected_at')->nullable();
                }
                if (! Schema::hasColumn($table, 'correction_note')) {
                    $t->string('correction_note', 300)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_time_fix_links');
        foreach (['check_events', 'time_punches'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                foreach (['correction_source', 'original_auto_at', 'corrected_by_user_id', 'corrected_at', 'correction_note'] as $c) {
                    if (Schema::hasColumn($table, $c)) {
                        $t->dropColumn($c);
                    }
                }
            });
        }
    }
};
