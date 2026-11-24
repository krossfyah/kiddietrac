<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Counter-signatures are finished by the queue worker (2026-11-06). The request records
 * what the reviewer did; these columns hold what the worker needs to make the stamped
 * copy exactly as reviewed, and whether it has.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('managed_form_signoffs', function (Blueprint $t) {
            if (! Schema::hasColumn('managed_form_signoffs', 'countersign_reviewed_url')) {
                $t->string('countersign_reviewed_url', 500)->nullable();
            }
            if (! Schema::hasColumn('managed_form_signoffs', 'countersign_annotations')) {
                $t->text('countersign_annotations')->nullable();
            }
            if (! Schema::hasColumn('managed_form_signoffs', 'countersign_options')) {
                $t->text('countersign_options')->nullable();
            }
            if (! Schema::hasColumn('managed_form_signoffs', 'countersign_finished_at')) {
                $t->timestamp('countersign_finished_at')->nullable();
            }
        });

        /* Everything counter-signed before this was finished in the request, as well as
           it could be. Marked done so the guard does not send it again; the ones that
           went out without the page are put right by --resend-corrected, on purpose. */
        DB::table('managed_form_signoffs')->whereNotNull('countersigned_at')->whereNull('countersign_finished_at')
            ->update(['countersign_finished_at' => DB::raw('COALESCE(returned_at, countersigned_at)')]);
    }

    public function down(): void
    {
        Schema::table('managed_form_signoffs', function (Blueprint $t) {
            foreach (['countersign_reviewed_url', 'countersign_annotations', 'countersign_options', 'countersign_finished_at'] as $c) {
                if (Schema::hasColumn('managed_form_signoffs', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
