<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tuition plans: which plan raised an invoice (2026-11-27). "Generate invoices" had no duplicate check, so pressing it
 * twice billed every family twice. FeePlanController::generateInvoices now stamps fee_plan_id and skips a family that
 * already has a live (not void) invoice from that plan for the same period. Existing invoices keep NULL; nothing that
 * reads invoices today changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('invoices', 'fee_plan_id')) {
            Schema::table('invoices', function (Blueprint $t) {
                $t->unsignedBigInteger('fee_plan_id')->nullable()->after('family_id');
                $t->index(['fee_plan_id', 'family_id', 'period_start'], 'invoices_fee_plan_period_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('invoices', 'fee_plan_id')) {
            Schema::table('invoices', function (Blueprint $t) {
                $t->dropIndex('invoices_fee_plan_period_idx');
                $t->dropColumn('fee_plan_id');
            });
        }
    }
};
