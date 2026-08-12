<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reusable forms.
 *
 * Some forms are signed once (a policy acknowledgement). Others are filled again
 * and again — an educator completing the same observation sheet for each child, or
 * the same weekly log every week. `reusable` is set by the admin at upload.
 *
 * managed_form_signoffs had a UNIQUE(managed_form_id, user_id), i.e. exactly one
 * record per person per form, which makes reuse impossible. That unique key is
 * replaced with a plain composite index: single-submission forms are still held to
 * one record, but in the CONTROLLER rather than by a constraint that also forbids
 * the legitimate case.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('managed_forms', function (Blueprint $table) {
            if (!Schema::hasColumn('managed_forms', 'reusable')) {
                $table->boolean('reusable')->default(false)->after('fillable');
            }
        });

        $idx = collect(DB::select('SHOW INDEX FROM managed_form_signoffs'))
            ->pluck('Key_name')->unique();

        if ($idx->contains('managed_form_signoffs_managed_form_id_user_id_unique')) {
            Schema::table('managed_form_signoffs', function (Blueprint $table) {
                $table->dropUnique('managed_form_signoffs_managed_form_id_user_id_unique');
            });
        }
        if (!$idx->contains('mfs_form_user_idx')) {
            Schema::table('managed_form_signoffs', function (Blueprint $table) {
                $table->index(['managed_form_id', 'user_id'], 'mfs_form_user_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::table('managed_forms', function (Blueprint $table) {
            if (Schema::hasColumn('managed_forms', 'reusable')) {
                $table->dropColumn('reusable');
            }
        });
        // Deliberately NOT restoring the unique key: by then duplicate rows may
        // legitimately exist for reusable forms and re-adding it would fail.
    }
};
