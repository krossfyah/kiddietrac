<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a schedule's money is FOR, and any extra charges that ride with it (2026-09-17).
 *
 * Anthony: "new payment schedule should have a description line to be entered for the
 * total amount and also ability to add a line item(s) as well."
 *
 * WHY NOT REUSE `notes`. That column is already carrying machine plumbing — the iLearn
 * sync matches on an `[ilearn:...]` token stored in it, and scheduleNote() strips that
 * token before a human reads it. Putting a billing description in there would mix a
 * printed, parent-facing string with an internal key, and the first sync to rewrite the
 * token would take the description with it.
 *
 * WHY STORE THE LINE ITEMS AT ALL, when they are baked into the invoices at creation.
 * Editing a schedule re-raises its unissued tail, and a re-raised invoice has to come out
 * carrying the same extra charges as the ones before it. Without this, editing a schedule
 * would silently drop every extra line from the instalments it rebuilt — the family's
 * supply fee would vanish from March onward and nothing would say so.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_plans', function (Blueprint $t) {
            if (! Schema::hasColumn('payment_plans', 'description')) {
                $t->string('description', 200)->nullable()->after('total_amount');
            }
            if (! Schema::hasColumn('payment_plans', 'line_items')) {
                // JSON rather than a child table: these are a handful of fixed values
                // copied onto each invoice, never queried across plans.
                $t->text('line_items')->nullable()->after('description');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payment_plans', function (Blueprint $t) {
            foreach (['description', 'line_items'] as $c) {
                if (Schema::hasColumn('payment_plans', $c)) { $t->dropColumn($c); }
            }
        });
    }
};
