<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which agency a Zum transaction belongs to.
 *
 * The settlement webhook resolved the calling agency from the shared secret and then
 * looked the transaction up by its Zum id across the WHOLE table, without ever
 * comparing the two. With more than one agency on Zum, that let a webhook signed with
 * agency B's secret settle agency A's transaction and mark agency A's invoice paid —
 * proved on 2026-09-01, where a $200 invoice was credited by the wrong tenant's key.
 *
 * The agency was only ever derivable — user_id → role assignment → agency — which is a
 * join the webhook was never going to do on a hot path. Stamped at creation instead,
 * so the scope is a column and the guard is a WHERE.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['zum_transactions', 'zum_refunds'] as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'agency_id')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) {
                $t->unsignedBigInteger('agency_id')->nullable()->after('id')->index();
            });
        }

        /* Backfill from the payer's own roles. Nothing to do today — both tables are
           empty — but a deploy that lands after real traffic must not leave rows the
           webhook then refuses to settle. */
        if (Schema::hasTable('zum_transactions') && Schema::hasColumn('zum_transactions', 'agency_id')) {
            foreach (DB::table('zum_transactions')->whereNull('agency_id')->pluck('user_id', 'id') as $id => $userId) {
                $aid = DB::table('role_assignments')->where('user_id', $userId)->where('active', 1)
                    ->whereNotNull('agency_id')->value('agency_id');
                if ($aid) {
                    DB::table('zum_transactions')->where('id', $id)->update(['agency_id' => $aid]);
                }
            }
        }
        if (Schema::hasTable('zum_refunds') && Schema::hasColumn('zum_refunds', 'agency_id')) {
            DB::statement(
                'UPDATE zum_refunds r JOIN zum_transactions t ON t.id = r.zum_transaction_id_local
                 SET r.agency_id = t.agency_id WHERE r.agency_id IS NULL'
            );
        }
    }

    public function down(): void
    {
        foreach (['zum_transactions', 'zum_refunds'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'agency_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropColumn('agency_id');
                });
            }
        }
    }
};
