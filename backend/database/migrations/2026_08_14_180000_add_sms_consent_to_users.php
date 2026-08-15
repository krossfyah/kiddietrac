<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consent to be texted, recorded against the number.
 *
 * SmsController::resolveRecipients already filtered on users.sms_opt_in — a column that
 * was never created, so every admin broadcast died on "unknown column" rather than
 * sending. That is why this is additive and not a rewrite: the sender was already written
 * against this shape.
 *
 * Consent belongs on the USER and not in notification_prefs, which is per event
 * ("tell me about sign-in and sign-out"). Those are different questions. Agreeing to be
 * texted at all is what a carrier requires evidence of, and it is what STOP revokes —
 * one reply has to silence every category at once, including ones added later.
 *
 * sms_consent_text stores the exact wording the person agreed to. A screenshot of today's
 * consent screen is not evidence of what last year's screen said, and the wording is the
 * thing being consented to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('sms_opt_in')->default(false)->after('phone_verified_at');
            $table->timestamp('sms_opt_in_at')->nullable()->after('sms_opt_in');
            $table->timestamp('sms_opt_out_at')->nullable()->after('sms_opt_in_at');
            // 'app' — the in-app consent screen. 'sms' — a START/STOP reply from the
            // handset, which is authoritative over anything set in the app.
            $table->string('sms_consent_source', 20)->nullable()->after('sms_opt_out_at');
            $table->text('sms_consent_text')->nullable()->after('sms_consent_source');
        });

        // The phone number is how an inbound STOP is matched back to a person, and a
        // carrier expects that to be immediate.
        Schema::table('users', function (Blueprint $table) {
            $table->index('phone', 'users_phone_idx');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_phone_idx');
            $table->dropColumn([
                'sms_opt_in', 'sms_opt_in_at', 'sms_opt_out_at',
                'sms_consent_source', 'sms_consent_text',
            ]);
        });
    }
};
