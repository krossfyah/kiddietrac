<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/* One-off invoices (2026-10-25). (agency_id, period_start) was unique so the renewal run can never bill a period
   twice; a one-off invoice on the same day as a renewal would have collided. A series column joins the key:
   'recurring' for everything raised from plans and licences (unchanged guard), 'oneoff-<n>' for each one-off. */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('platform_invoices', 'series')) {
            Schema::table('platform_invoices', function (Blueprint $t) {
                $t->string('series', 40)->default('recurring')->after('number');
            });
        }
        $idx = collect(DB::select("SHOW INDEX FROM platform_invoices WHERE Key_name = 'platform_invoices_agency_period_unique'"));
        if ($idx->count() === 2) {
            Schema::table('platform_invoices', function (Blueprint $t) {
                $t->unique(['agency_id', 'period_start', 'series'], 'platform_invoices_agency_period_series_unique');
                $t->dropUnique('platform_invoices_agency_period_unique');
            });
        }
    }

    public function down(): void {}
};
