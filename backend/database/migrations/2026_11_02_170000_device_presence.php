<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE APP ON A PHONE THAT HAS NO PUSH TOKEN YET (2026-11-02, Anthony: "sukhman installed the testflight app but under
 * staff notifications doesnt show that info").
 *
 * device_tokens only ever held SENDABLE push addresses, so a phone whose owner had not (yet) allowed notifications was
 * invisible to the staff readiness list. device_presence is one row per app install (install_id = a random id the app
 * keeps in its own storage), pointing at whoever is signed in on it now, with the app build, the WebView User-Agent and
 * the notification / location permission states the app read. NEVER a push address: nothing sends from this table.
 *
 * device_tokens.access_token_id: the sign-in (Sanctum token) that registered the push address, so signing out of that
 * session unlinks the phone and a shared or handed-on phone stops showing the previous person's notifications.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('device_presence')) {
            Schema::create('device_presence', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id');
                $t->string('install_id', 64)->unique();
                $t->enum('platform', ['ios', 'android']);
                $t->unsignedSmallInteger('app_build')->nullable();
                $t->string('device_name', 120)->nullable();
                $t->string('notifications', 16)->nullable();
                $t->string('location', 16)->nullable();
                $t->text('perms')->nullable();
                $t->boolean('has_push')->default(false);
                $t->dateTime('last_seen_at')->nullable();
                $t->timestamps();
                $t->index('user_id');
                $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }
        if (! Schema::hasColumn('device_tokens', 'access_token_id')) {
            Schema::table('device_tokens', function (Blueprint $t) {
                $t->unsignedBigInteger('access_token_id')->nullable()->after('app_build');
                $t->index('access_token_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('device_presence');
        if (Schema::hasColumn('device_tokens', 'access_token_id')) {
            Schema::table('device_tokens', function (Blueprint $t) {
                $t->dropIndex(['access_token_id']);
                $t->dropColumn('access_token_id');
            });
        }
    }
};
