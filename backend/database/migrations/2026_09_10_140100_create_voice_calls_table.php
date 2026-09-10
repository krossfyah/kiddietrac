<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VOICE ANNOUNCEMENTS (2026-09-10).
 *
 * The record of every call the platform places, mirroring sms_messages so the two
 * channels can be read the same way -- one row per RECIPIENT, written before the
 * carrier is contacted, and never deleted when a call fails.
 *
 * A call is not one event the way a text is; it is a sequence, and each step arrives
 * as a separate signed webhook minutes apart:
 *
 *   queued -> ringing -> answered -> spoken -> completed
 *                     \-> no_answer / busy / failed
 *                     \-> machine        (an answering machine picked up)
 *   skipped                              (a gate refused it; the carrier never heard of it)
 *
 * `client_state` is the key to the whole thing. Telnyx echoes it back on every webhook
 * for a call, and the first webhook regularly arrives BEFORE the dial's own HTTP
 * response has been written here -- so the row cannot be found by call_control_id at
 * the moment it is first needed. The state token is minted before the dial and is
 * therefore always resolvable. It is indexed for that reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('voice_calls')) {
            return;
        }

        Schema::create('voice_calls', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('to_user_id')->nullable();
            $table->string('to_phone', 40);

            // What will be said. Kept verbatim rather than as a template id: an
            // announcement is read out once and an auditor asking "what did the parents
            // hear" needs the words, not a pointer to a template that has since changed.
            $table->text('body');
            $table->string('category', 40)->nullable();

            $table->string('provider', 16)->default('telnyx');
            // Telnyx call_control_id -- a long base64 token, not a UUID.
            $table->string('provider_ref', 191)->nullable();

            // Minted before the dial, so a webhook that overtakes the dial response can
            // still find this row. Unique because a collision would speak one agency's
            // announcement down another agency's call.
            $table->string('client_state', 64)->unique();

            $table->string('status', 20)->default('queued');
            $table->text('error')->nullable();

            $table->unsignedBigInteger('started_by_id')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            // Seconds, from Telnyx's own hangup event -- what the call is billed on.
            $table->unsignedInteger('duration_secs')->nullable();
            $table->timestamps();

            $table->index(['agency_id', 'created_at']);
            $table->index('provider_ref');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_calls');
    }
};
