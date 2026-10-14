<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visible reference IDs (2026-10-14, Anthony: "does kiddietrac have a unique id assigned to each
 * user/child/staff? can this be made visible on documents, records, portal etc?" — and he chose
 * "agency prefix + running number", e.g. ILH-C-0042).
 *
 * Only the NUMBER is stored; the code is assembled from the agency's current prefix
 * (App\Support\RefCode). See that class for why.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('agencies', 'ref_prefix')) {
            Schema::table('agencies', fn (Blueprint $t) => $t->string('ref_prefix', 6)->nullable());
        }
        foreach (['children', 'families'] as $tbl) {
            Schema::table($tbl, function (Blueprint $t) use ($tbl) {
                if (! Schema::hasColumn($tbl, 'ref_no')) $t->unsignedInteger('ref_no')->nullable();
                if (! Schema::hasColumn($tbl, 'ref_agency_id')) $t->unsignedBigInteger('ref_agency_id')->nullable();
            });
            try { Schema::table($tbl, fn (Blueprint $t) => $t->unique(['ref_agency_id', 'ref_no'], $tbl . '_ref_unique')); } catch (\Throwable $e) {}
        }
        if (! Schema::hasTable('user_refs')) {
            Schema::create('user_refs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id');
                $t->unsignedBigInteger('agency_id');
                $t->char('type', 1);                 // S staff · P parent/guardian
                $t->unsignedInteger('ref_no');
                $t->timestamp('created_at')->nullable();
                $t->unique(['user_id', 'agency_id', 'type']);
                $t->unique(['agency_id', 'type', 'ref_no']);
            });
        }
        if (! Schema::hasTable('agency_ref_counters')) {
            Schema::create('agency_ref_counters', function (Blueprint $t) {
                $t->unsignedBigInteger('agency_id');
                $t->char('type', 1);                 // C F S P
                $t->unsignedInteger('last_no')->default(0);
                $t->primary(['agency_id', 'type']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_ref_counters');
        Schema::dropIfExists('user_refs');
        foreach (['children', 'families'] as $tbl) {
            try { Schema::table($tbl, fn (Blueprint $t) => $t->dropUnique($tbl . '_ref_unique')); } catch (\Throwable $e) {}
            Schema::table($tbl, fn (Blueprint $t) => $t->dropColumn(['ref_no', 'ref_agency_id']));
        }
        Schema::table('agencies', fn (Blueprint $t) => $t->dropColumn('ref_prefix'));
    }
};
