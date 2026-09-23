<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Help-article feedback could not be scoped to an agency, because the table never
 * recorded one. Views recorded a column but always wrote NULL into it —
 * `optional($user)->agency_id` reads a property that does not exist on the users
 * table — so the analytics endpoint's agency filter had nothing to filter on and
 * silently matched everything.
 *
 * Adds the column, and backfills both tables from each author's active role.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('help_article_feedback', 'agency_id')) {
            Schema::table('help_article_feedback', function (Blueprint $t) {
                $t->unsignedBigInteger('agency_id')->nullable()->after('user_id')->index();
            });
        }

        /* Backfill from the author's own active role. Historic rows whose author has
           since been removed stay NULL — deliberately: a guess there would attribute
           one agency's reading habits to another, and NULL is correctly treated as
           "not mine" by the scoped queries. */
        foreach (['help_article_feedback', 'help_article_views'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'agency_id')) {
                continue;
            }
            DB::table($table)
                ->whereNull('agency_id')
                ->whereNotNull('user_id')
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($table) {
                    foreach ($rows as $row) {
                        $agencyId = DB::table('role_assignments')
                            ->where('user_id', $row->user_id)
                            ->where('active', true)
                            ->whereNotNull('agency_id')
                            ->value('agency_id');
                        if ($agencyId) {
                            DB::table($table)->where('id', $row->id)->update(['agency_id' => $agencyId]);
                        }
                    }
                });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('help_article_feedback', 'agency_id')) {
            Schema::table('help_article_feedback', function (Blueprint $t) {
                $t->dropColumn('agency_id');
            });
        }
    }
};
