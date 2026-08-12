<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fill-and-sign for managed forms.
 *
 * `fillable` is opt-in PER FORM, set by the admin at upload time — most uploads are
 * read-and-sign notices where typing into the page makes no sense. Only a form
 * flagged fillable gets the interactive field experience.
 *
 * The recipient's answers are kept twice on purpose:
 *   field_values     — the raw name/value map, so the data stays queryable and
 *                      readable without parsing a PDF.
 *   filled_file_url  — the completed PDF (values written into the original's own
 *                      AcroForm fields, signature embedded, then flattened), which
 *                      is the artefact a regulator or parent actually wants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('managed_forms', function (Blueprint $table) {
            if (!Schema::hasColumn('managed_forms', 'fillable')) {
                $table->boolean('fillable')->default(false)->after('file_size');
            }
        });

        Schema::table('managed_form_signoffs', function (Blueprint $table) {
            if (!Schema::hasColumn('managed_form_signoffs', 'field_values')) {
                $table->longText('field_values')->nullable()->after('signature');
            }
            if (!Schema::hasColumn('managed_form_signoffs', 'filled_file_url')) {
                $table->string('filled_file_url', 512)->nullable()->after('field_values');
            }
        });
    }

    public function down(): void
    {
        Schema::table('managed_forms', function (Blueprint $table) {
            if (Schema::hasColumn('managed_forms', 'fillable')) {
                $table->dropColumn('fillable');
            }
        });
        Schema::table('managed_form_signoffs', function (Blueprint $table) {
            foreach (['field_values', 'filled_file_url'] as $col) {
                if (Schema::hasColumn('managed_form_signoffs', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
