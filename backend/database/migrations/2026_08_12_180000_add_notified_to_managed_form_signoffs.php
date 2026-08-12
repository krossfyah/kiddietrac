<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records whether a completed form was emailed on, and to whom.
 *
 * The Completed tab could say a form was signed but not whether the copy actually
 * reached the address configured on it — which is the thing an admin needs to trust
 * before assuming a compliance inbox has it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('managed_form_signoffs')) return;
        Schema::table('managed_form_signoffs', function (Blueprint $t) {
            if (! Schema::hasColumn('managed_form_signoffs', 'notified_at')) {
                $t->timestamp('notified_at')->nullable();
            }
            if (! Schema::hasColumn('managed_form_signoffs', 'notified_to')) {
                $t->string('notified_to', 190)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('managed_form_signoffs')) return;
        Schema::table('managed_form_signoffs', function (Blueprint $t) {
            foreach (['notified_at', 'notified_to'] as $c) {
                if (Schema::hasColumn('managed_form_signoffs', $c)) $t->dropColumn($c);
            }
        });
    }
};
