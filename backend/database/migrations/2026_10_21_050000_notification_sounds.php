<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Per-person notification tone + vibration per category, and the app build each
   handset runs (so only builds that know the new channels are sent them). 2026-10-21. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notification_sounds')) {
            Schema::create('notification_sounds', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id');
                $t->string('category', 20);
                $t->string('tone', 30);
                $t->string('vibration', 20);
                $t->timestamp('updated_at')->nullable();
                $t->unique(['user_id', 'category']);
            });
        }
        if (! Schema::hasColumn('device_tokens', 'app_build')) {
            Schema::table('device_tokens', function (Blueprint $t) {
                $t->unsignedSmallInteger('app_build')->nullable()->after('device_name');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_sounds');
        if (Schema::hasColumn('device_tokens', 'app_build')) {
            Schema::table('device_tokens', function (Blueprint $t) {
                $t->dropColumn('app_build');
            });
        }
    }
};
