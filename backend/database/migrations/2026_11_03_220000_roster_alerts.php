<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * 2026-11-03 (Anthony: "de-enrollement should be alerted in the bell for all admin staff and educator" / "new family
 * should also be alerted the same way by the bell"). App\Support\RosterAlerts rings once per withdrawal and once per
 * family; these stamps are the "once". Everything already withdrawn or already here is stamped now, so switching
 * this on tells nobody about the past.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('children', 'withdraw_alerted_at')) {
            Schema::table('children', function (Blueprint $t) { $t->dateTime('withdraw_alerted_at')->nullable(); });
        }
        if (! Schema::hasColumn('families', 'joined_alerted_at')) {
            Schema::table('families', function (Blueprint $t) { $t->dateTime('joined_alerted_at')->nullable(); });
        }
        DB::table('children')->where('enrollment_status', 'withdrawn')->whereNull('withdraw_alerted_at')->update(['withdraw_alerted_at' => now()]);
        DB::table('families')->whereNull('joined_alerted_at')->update(['joined_alerted_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('children', function (Blueprint $t) { $t->dropColumn('withdraw_alerted_at'); });
        Schema::table('families', function (Blueprint $t) { $t->dropColumn('joined_alerted_at'); });
    }
};
