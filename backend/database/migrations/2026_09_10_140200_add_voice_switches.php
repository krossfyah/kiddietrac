<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE TWO SWITCHES THAT GOVERN A PHONE CALL (2026-09-10).
 *
 * `agencies.voice_enabled` is the per-agency master switch, exactly like the
 * `sms_enabled` column it sits beside. It defaults to OFF and has to be turned on
 * deliberately. That matters more here than it did for SMS: an announcement CALL rings
 * a real phone at whatever hour it is sent, and a carrier's credentials arriving in the
 * settings screen must not be enough on their own to start ringing parents.
 *
 * `users.voice_opt_out` is the person's own no. It is an opt-OUT and not an opt-in,
 * which is a deliberate and narrow choice:
 *
 *   - Voice on this platform is for closures, evacuations, lockdowns and illness
 *     notices -- calls made necessary by a situation affecting the health and safety of
 *     the people receiving them. That is the one purpose the TCPA exempts from prior
 *     express consent, and it is the entire reason to build a voice channel for a
 *     childcare service at all.
 *   - Anything OUTSIDE that purpose is gated on sms_opt_in as well, in
 *     VoiceController::callOne. A reminder or a newsletter read down the phone needs a
 *     yes, and does not get one from this column.
 *
 * So this column is the standing "do not ring me", honoured for every category
 * including an emergency, because somebody who has said it has usually said it for a
 * reason we are not entitled to second-guess. sms_opt_in is untouched -- STOP revokes
 * texts, and inferring a phone preference from it would silently opt people out of the
 * evacuation notice they most need.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('agencies', 'voice_enabled')) {
            Schema::table('agencies', function (Blueprint $table) {
                $table->boolean('voice_enabled')->default(false)->after('sms_enabled');
            });
        }

        if (! Schema::hasColumn('users', 'voice_opt_out')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('voice_opt_out')->default(false)->after('sms_consent_text');
                $table->timestamp('voice_opt_out_at')->nullable()->after('voice_opt_out');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('agencies', 'voice_enabled')) {
            Schema::table('agencies', function (Blueprint $table) {
                $table->dropColumn('voice_enabled');
            });
        }
        if (Schema::hasColumn('users', 'voice_opt_out')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn(['voice_opt_out', 'voice_opt_out_at']);
            });
        }
    }
};
