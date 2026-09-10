<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHICH CARRIER CARRIED THIS TEXT (2026-09-10).
 *
 * sms_messages has a `twilio_sid` column because Twilio was the only carrier. Telnyx
 * is now a second one, so the carrier has to be recorded per message -- otherwise
 * "did the switch to Telnyx work?" cannot be answered from the log, and neither can
 * "which account is this on the bill for?".
 *
 * `twilio_sid` is LEFT ALONE and still written on a Twilio send. Nothing reads it
 * today, but it is the identifier support would quote back to Twilio for a message
 * sent before this migration, and dropping it would lose that for 18 months of rows.
 *
 * `provider` is nullable rather than defaulted so that a NULL means "sent before
 * there was a choice", which is true and is not the same statement as "sent on
 * Twilio". Readers coalesce it -- see SmsGateway::recentByProvider.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_messages', function (Blueprint $table) {
            if (! Schema::hasColumn('sms_messages', 'provider')) {
                $table->string('provider', 16)->nullable()->after('category');
            }
            if (! Schema::hasColumn('sms_messages', 'provider_ref')) {
                // Telnyx message ids are UUIDs, Twilio's are SM + 32 hex. 80 matches
                // the existing twilio_sid column so neither can outgrow it.
                $table->string('provider_ref', 80)->nullable()->after('provider');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sms_messages', function (Blueprint $table) {
            foreach (['provider', 'provider_ref'] as $col) {
                if (Schema::hasColumn('sms_messages', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
