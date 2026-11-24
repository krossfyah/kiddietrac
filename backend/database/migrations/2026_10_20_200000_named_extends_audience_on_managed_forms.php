<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Named recipients IN ADDITION to the role audience, per form (2026-10-20). Default 0 keeps
   the 2026-09-10 rule: naming people means only them. See App\Support\FormAudience. */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('managed_forms', 'named_extends_audience')) {
            Schema::table('managed_forms', function (Blueprint $t) {
                $t->boolean('named_extends_audience')->default(false)->after('audiences');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('managed_forms', 'named_extends_audience')) {
            Schema::table('managed_forms', function (Blueprint $t) {
                $t->dropColumn('named_extends_audience');
            });
        }
    }
};
