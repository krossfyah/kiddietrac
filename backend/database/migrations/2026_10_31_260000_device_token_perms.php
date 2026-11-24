<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** What each phone/browser lets KiddieTrac do (notifications, location, camera…), reported by the app. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_tokens', function (Blueprint $t) {
            if (! Schema::hasColumn('device_tokens', 'perms')) $t->text('perms')->nullable();
            if (! Schema::hasColumn('device_tokens', 'perms_checked_at')) $t->timestamp('perms_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('device_tokens', function (Blueprint $t) { $t->dropColumn(['perms', 'perms_checked_at']); });
    }
};
