<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** When the missed-call alert went out for a call (kt:call-alerts), so it goes once. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('inbound_calls', 'notified_at')) {
            Schema::table('inbound_calls', function (Blueprint $t) {
                $t->timestamp('notified_at')->nullable()->index();
            });
        }
        // Everything already on file is history: no alerts for it.
        DB::table('inbound_calls')->whereNull('notified_at')->update(['notified_at' => now()]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('inbound_calls', 'notified_at')) {
            Schema::table('inbound_calls', function (Blueprint $t) {
                $t->dropColumn('notified_at');
            });
        }
    }
};
