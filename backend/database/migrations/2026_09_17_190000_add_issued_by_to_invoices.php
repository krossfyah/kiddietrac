<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHO TOOK THIS INVOICE OUT OF DRAFT (2026-09-17).
 *
 * `issued_at` has always recorded WHEN an invoice was issued and nothing recorded by
 * WHOM, because until today nobody could issue one by hand — the 06:00 cron did it on
 * the first of each month and there was no other way. Now that a director can issue a
 * draft early, "issued" and "issued by" are two different facts and the schedule shows
 * both.
 *
 * NULL IS NOT MISSING DATA. It means no person did it: the cron, or an import. Every
 * row that exists today is one of those, so backfilling would be inventing an author.
 * The reader shows null as "Automatic" rather than as a blank.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('invoices', 'issued_by_user_id')) {
            return;
        }
        Schema::table('invoices', function (Blueprint $t) {
            $t->unsignedBigInteger('issued_by_user_id')->nullable()->after('issued_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('invoices', 'issued_by_user_id')) {
            return;
        }
        Schema::table('invoices', function (Blueprint $t) {
            $t->dropColumn('issued_by_user_id');
        });
    }
};
