<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The newest notification id the person had when they last opened their notifications: badges count only newer ones. An id, not a time: notification rows are stamped both by Laravel (UTC) and by the column default (the MST session), so times would not compare (App\Support\Badges). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'notifications_seen_id')) {
            Schema::table('users', function (Blueprint $t) { $t->unsignedBigInteger('notifications_seen_id')->nullable(); });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'notifications_seen_id')) {
            Schema::table('users', function (Blueprint $t) { $t->dropColumn('notifications_seen_id'); });
        }
    }
};
