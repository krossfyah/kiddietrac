<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Who a Safe Arrival escalation did NOT reach by phone (2026-10-01). The alert for a
   missing child failed to two educators whose app tokens had expired and the check
   still read "staff alerted". Names, comma-separated, as they were at the time. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('safe_arrival_checks') || Schema::hasColumn('safe_arrival_checks', 'staff_unreached')) {
            return;
        }
        Schema::table('safe_arrival_checks', function (Blueprint $t) {
            $t->string('staff_unreached', 500)->nullable()->after('staff_alerted');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('safe_arrival_checks', 'staff_unreached')) {
            Schema::table('safe_arrival_checks', function (Blueprint $t) {
                $t->dropColumn('staff_unreached');
            });
        }
    }
};
