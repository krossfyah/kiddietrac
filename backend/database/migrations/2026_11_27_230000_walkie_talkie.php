<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 📻 WALKIE-TALKIE (2026-11-27, App\Support\Walkie). Live push-to-talk between an agency's staff.
 *
 *  walkie_transmissions  one press of the talk button: who, which channel, when, how long. The audio itself
 *                        (audio, live_key) is sealed with KT_DATA_KEY (Sensitive::seal) and the row is deleted
 *                        after the agency's retention (default 30 days, kt:walkie-sweep).
 *  walkie_chunks         the audio while it is being spoken (sealed), folded into the transmission when it ends.
 *  walkie_recipients     who it went to and what happened (sent / heard / busy / dnd / off_shift).
 *  walkie_floor          one speaker per channel at a time (row lock).
 *  walkie_prefs          a person's own Do not disturb.
 *  walkie_devices        phones running the walkie-talkie listener (Android app 3.13+), with a hashed device secret.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('walkie_transmissions')) {
            Schema::create('walkie_transmissions', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->string('channel', 40);
                $t->string('channel_label', 160)->nullable();
                $t->unsignedBigInteger('centre_id')->nullable();
                $t->unsignedBigInteger('speaker_user_id')->index();
                $t->unsignedBigInteger('to_user_id')->nullable()->index();
                $t->boolean('emergency')->default(false);
                $t->string('status', 12)->default('live');      // live | done
                $t->char('live_dir', 32)->nullable();
                $t->text('live_key')->nullable();               // sealed
                $t->unsignedInteger('chunks')->default(0);
                $t->unsignedInteger('duration_ms')->default(0);
                $t->unsignedInteger('recipients')->default(0);
                $t->mediumText('audio')->nullable();            // sealed base64 G.711 mu-law, 16 kHz mono
                $t->dateTime('started_at');
                $t->dateTime('last_chunk_at')->nullable();
                $t->dateTime('ended_at')->nullable();
                $t->dateTime('live_swept_at')->nullable();
                $t->timestamps();
                $t->index(['agency_id', 'channel', 'started_at']);
                $t->index(['status', 'started_at']);
            });
        }
        if (! Schema::hasTable('walkie_chunks')) {
            Schema::create('walkie_chunks', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('transmission_id');
                $t->unsignedInteger('seq');
                $t->text('data');                                // sealed base64
                $t->dateTime('created_at')->nullable();
                $t->unique(['transmission_id', 'seq']);
            });
        }
        if (! Schema::hasTable('walkie_recipients')) {
            Schema::create('walkie_recipients', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('transmission_id');
                $t->unsignedBigInteger('user_id')->index();
                $t->string('status', 12)->default('sent');       // sent | heard | busy | dnd | off_shift
                $t->dateTime('heard_at')->nullable();
                $t->dateTime('replied_at')->nullable();
                $t->unique(['transmission_id', 'user_id']);
            });
        }
        if (! Schema::hasTable('walkie_floor')) {
            Schema::create('walkie_floor', function (Blueprint $t) {
                $t->unsignedBigInteger('agency_id');
                $t->string('channel', 40);
                $t->unsignedBigInteger('transmission_id')->nullable();
                $t->unsignedBigInteger('speaker_user_id')->nullable();
                $t->dateTime('until')->nullable();
                $t->primary(['agency_id', 'channel']);
            });
        }
        if (! Schema::hasTable('walkie_prefs')) {
            Schema::create('walkie_prefs', function (Blueprint $t) {
                $t->unsignedBigInteger('user_id')->primary();
                $t->boolean('dnd')->default(false);
                $t->dateTime('dnd_until')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('walkie_devices')) {
            Schema::create('walkie_devices', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id')->index();
                $t->string('platform', 12)->default('android');
                $t->char('secret_hash', 64)->unique();
                $t->string('push_token', 2048)->nullable();       // FCM token (Android) / PushToTalk token (iPhone)
                $t->string('app_build', 32)->nullable();
                $t->dateTime('last_seen_at')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach (['walkie_devices', 'walkie_prefs', 'walkie_floor', 'walkie_recipients', 'walkie_chunks', 'walkie_transmissions'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
