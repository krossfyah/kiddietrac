<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional "send the completed form to this address" per managed form.
 *
 * An agency often needs a signed form to land somewhere specific — the centre's
 * compliance inbox, a director, a licensing contact — rather than only living in the
 * Completed tab. Chosen at upload (and editable afterwards); null means nobody is
 * emailed, which stays the default.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('managed_forms')) return;
        if (Schema::hasColumn('managed_forms', 'notify_email')) return;
        Schema::table('managed_forms', function (Blueprint $t) {
            $t->string('notify_email', 190)->nullable()->after('reusable');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('managed_forms') && Schema::hasColumn('managed_forms', 'notify_email')) {
            Schema::table('managed_forms', function (Blueprint $t) { $t->dropColumn('notify_email'); });
        }
    }
};
