<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The audit trail keeps milliseconds.
 *
 * `created_at` was `timestamp` — MySQL's default precision, which is whole seconds, so
 * every one of the 14,729 rows on production stored microsecond 0. That is not enough
 * resolution to order this log: of the 300 newest rows, 241 share their second with
 * another row, and one second in the middle of a sync holds 25 of them.
 *
 * The list already tie-breaks on `id`, so the ORDER shown was correct — but the
 * TIMESTAMPS were identical, which is worse than it sounds. Somebody reading the log
 * cannot tell whether the 25 rows in 13:30:08 happened in the order shown or were
 * simply dumped there, cannot line a row up against a server log or a support call,
 * and cannot prove a sequence to anybody who asks. An audit trail whose order rests
 * entirely on a surrogate key is asking to be doubted.
 *
 * Anthony, 2026-09-04: "add millisecond to audit trail entries for all audit trail
 * logging".
 *
 * Nothing in the application writes this column — all 79 Audit::write() call sites
 * leave it to the DEFAULT — so moving the column to TIMESTAMP(3) with a matching
 * CURRENT_TIMESTAMP(3) default is the whole change for every future row. Audit::write()
 * additionally normalises an explicitly-passed value, because Laravel formats a Carbon
 * as 'Y-m-d H:i:s' and would silently drop the milliseconds again.
 *
 * Existing rows keep .000. That is honest: we do not know their sub-second order and
 * inventing one would be worse than admitting it.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Raw DDL: the fractional-seconds precision is not expressible through the
        // schema builder, and this column carries three indexes that must survive.
        DB::statement(
            'ALTER TABLE `audit_logs` '
            . 'MODIFY `created_at` TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)'
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE `audit_logs` '
            . 'MODIFY `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'
        );
    }
};
