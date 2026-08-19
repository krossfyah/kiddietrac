<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Overtime on a payroll document.
 *
 * iLearn's payslip has always had an overtime line — hours, a multiplier and the resulting
 * amount — and the imported template refers to it. Nothing carried those fields across, so
 * that row rendered blank whatever the pay period held.
 *
 * Nothing in the current data uses it: none of the 64 pay periods record overtime, and only
 * seven record hours at all. The columns exist so the day somebody enters an overtime shift
 * it arrives intact, rather than being silently dropped and noticed a pay run later.
 *
 * Nullable throughout: absent means "not recorded", which is not the same as zero hours.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_documents', function (Blueprint $table) {
            $table->decimal('ot_hours', 10, 2)->nullable()->after('units');
            $table->decimal('ot_mult', 5, 2)->nullable()->after('ot_hours');
            $table->decimal('ot_amount', 12, 2)->nullable()->after('ot_mult');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_documents', function (Blueprint $table) {
            $table->dropColumn(['ot_hours', 'ot_mult', 'ot_amount']);
        });
    }
};
