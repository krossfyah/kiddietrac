<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * email_logs.created_at → TIMESTAMP(3).
 *
 * The email log records the order things were sent in, and whole seconds cannot carry
 * that: a single batch writes six to eight rows in one second (2026-09-11 22:00:10 has
 * eight, 2026-09-12 12:58:07 has six), so a correctly ordered list reads as one
 * undifferentiated moment. On 2026-09-14 two password emails to the same person were
 * logged at 16:29:11 and 16:29:12 and only one of the two passwords still worked —
 * which of them arrived second was the entire question, and the log could only answer
 * it by luck of the id.
 *
 * audit_logs was widened to TIMESTAMP(3) for exactly this reason and has carried a
 * fraction on 100% of rows since. This brings its twin into line, so an email row and
 * the audit row written beside it can actually be lined up.
 *
 * NOT touching opened_at. That is stamped when a tracking pixel is fetched, minutes or
 * days later and by whatever mail client happens to prefetch it; a millisecond on it
 * would be precision without meaning.
 *
 * The 7,615 existing rows keep their value and read as .000 — the fraction is genuinely
 * unknown for them and inventing one would be worse than admitting it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('email_logs')) {
            return;
        }

        // Written as raw SQL rather than through the Blueprint: Laravel's timestamp()
        // change() on MySQL re-emits the whole column and has a habit of attaching
        // DEFAULT CURRENT_TIMESTAMP to a nullable timestamp. The column is nullable
        // with no default and must stay exactly that.
        DB::statement('ALTER TABLE `email_logs` MODIFY `created_at` TIMESTAMP(3) NULL DEFAULT NULL');
    }

    public function down(): void
    {
        if (! Schema::hasTable('email_logs')) {
            return;
        }

        DB::statement('ALTER TABLE `email_logs` MODIFY `created_at` TIMESTAMP NULL DEFAULT NULL');
    }
};
