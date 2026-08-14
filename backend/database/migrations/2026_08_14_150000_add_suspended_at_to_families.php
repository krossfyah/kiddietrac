<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A family suspension used to live only in its guardians' user.status, which meant
 * educators' rosters had no way to know about it. Stamping the family itself gives
 * every roster a single column to filter on, and dates the pause — which is the fact
 * a retention question actually needs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('families', 'suspended_at')) {
            Schema::table('families', function (Blueprint $t) {
                $t->timestamp('suspended_at')->nullable()->after('deleted_at')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('families', 'suspended_at')) {
            Schema::table('families', function (Blueprint $t) {
                $t->dropColumn('suspended_at');
            });
        }
    }
};
