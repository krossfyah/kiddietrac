<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/* WHO LOGGED A LATE PICKUP FEE (2026-10-06). Anthony: "make sure the late pickup fee's
   table has a date stamp and who initiated this request logged as well". The table had
   created_at but no person. Existing rows are back-filled from the audit trail: the
   POST /operations/late-pickup that created each one, matched by agency and time. */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('late_pickup_charges', 'logged_by_id')) {
            Schema::table('late_pickup_charges', function (Blueprint $t) {
                $t->unsignedBigInteger('logged_by_id')->nullable()->after('notes');
            });
        }
        foreach (DB::table('late_pickup_charges')->whereNull('logged_by_id')->get(['id', 'agency_id', 'created_at']) as $r) {
            if (! $r->created_at) continue;
            $t = strtotime((string) $r->created_at);
            $uid = DB::table('audit_logs')
                ->where('action', 'post:api/v1/operations/late-pickup')
                ->whereBetween('created_at', [date('Y-m-d H:i:s', $t - 5), date('Y-m-d H:i:s', $t + 5)])
                ->where(fn ($q) => $q->where('agency_id', $r->agency_id)->orWhereNull('agency_id'))
                ->orderBy('id')->value('user_id');
            if ($uid) DB::table('late_pickup_charges')->where('id', $r->id)->update(['logged_by_id' => $uid]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('late_pickup_charges', 'logged_by_id')) {
            Schema::table('late_pickup_charges', fn (Blueprint $t) => $t->dropColumn('logged_by_id'));
        }
    }
};
