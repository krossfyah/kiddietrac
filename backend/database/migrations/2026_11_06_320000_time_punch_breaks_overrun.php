<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LUNCH / BREAK OVERRUN (2026-11-06, Anthony: "when on lunch or break and the break or lunch exceeds a threshold -
 * warn and remind director/admin and educator appropriately"). When each step went out, so it goes out once.
 *   reminded_at   the educator was reminded (at the limit)
 *   escalated_at  the centre's directors / the agency's admins were alerted (limit + overrun_escalate_after)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('time_punch_breaks') || Schema::hasColumn('time_punch_breaks', 'reminded_at')) {
            return;
        }
        Schema::table('time_punch_breaks', function (Blueprint $t) {
            $t->timestamp('reminded_at')->nullable()->after('ended_how');
            $t->timestamp('escalated_at')->nullable()->after('reminded_at');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('time_punch_breaks', 'reminded_at')) {
            Schema::table('time_punch_breaks', function (Blueprint $t) {
                $t->dropColumn(['reminded_at', 'escalated_at']);
            });
        }
    }
};
