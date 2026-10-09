<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Whether a form is counter-signed by the office (2026-10-09: "add option to multiforms
   and forms library when adding new forms for counter signing option or not").
   Default 1: every existing form keeps today's behaviour (counter-signing offered);
   switching it off is a per-form choice in the Forms Manager. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('managed_forms', 'requires_countersign')) {
            Schema::table('managed_forms', function (Blueprint $t) {
                $t->boolean('requires_countersign')->default(true)->after('reusable');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('managed_forms', 'requires_countersign')) {
            Schema::table('managed_forms', function (Blueprint $t) {
                $t->dropColumn('requires_countersign');
            });
        }
    }
};
