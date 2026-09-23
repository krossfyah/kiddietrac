<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A refund is signed for.
 *
 * Money going back out is the one movement with no counterparty confirming it. An
 * invoice is agreed with the family, a payment arrives from somebody who chose to send
 * it — but a refund is authorised by one person inside the agency and then simply
 * happens. So it carries who approved it, when, from where, and their signature.
 *
 * Anthony's spec: "the refund process should work with a flow on selecting the invoice,
 * refunding partially or full and audit trailed by an authorized user that can refund
 * payments with a date/timestamp and signature on file."
 *
 * The signature is drawn on screen and stored as a data URI, exactly as
 * incidents.recorder_signature_data already does — the same evidence an incident report
 * carries, for the same reason. It is not a legal instrument on its own; it is the
 * difference between "somebody refunded $735" and "this named person approved this
 * refund at this minute from this address, and drew their name to say so".
 *
 * `initiated_by_id` and `refunded_at` already existed and already recorded who and when.
 * What was missing is the deliberate act: a user id is whoever was logged in, whereas a
 * signature is somebody choosing to put their name to a decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_refunds', function (Blueprint $table) {
            // mediumText: a drawn signature as a PNG data URI runs to tens of KB.
            $table->mediumText('approver_signature_data')->nullable()->after('notes');
            $table->string('approver_signed_name', 160)->nullable()->after('approver_signature_data');
            $table->timestamp('approver_signed_at', 3)->nullable()->after('approver_signed_name');
            $table->string('approver_signature_ip', 45)->nullable()->after('approver_signed_at');
        });
    }

    public function down(): void
    {
        Schema::table('payment_refunds', function (Blueprint $table) {
            $table->dropColumn([
                'approver_signature_data',
                'approver_signed_name',
                'approver_signed_at',
                'approver_signature_ip',
            ]);
        });
    }
};
