<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RETURNING A COMPLETED FORM TO THE SIGNER (2026-09-22)
 *
 * A parent submits a form with a field wrong or missing and, until now, the agency's
 * only options were to accept it or DELETE the sign-off — which threw away the record
 * that they ever submitted anything, along with the PDF they signed. Neither is a
 * correction: one leaves bad data filed, the other destroys evidence.
 *
 * This records the third option. The original sign-off stays exactly as it is; it is
 * marked as sent back, with the reason, and the form becomes outstanding for that
 * person again. When they resubmit, `sign()` writes a NEW row, so the history reads
 * submitted -> returned -> resubmitted rather than overwriting itself.
 *
 * `corrected_at` closes the loop: a pending return is what makes the form outstanding,
 * so it has to be resolvable, or a returned form would follow the parent forever.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('managed_form_signoffs')) {
            return;
        }

        Schema::table('managed_form_signoffs', function (Blueprint $t) {
            if (! Schema::hasColumn('managed_form_signoffs', 'correction_requested_at')) {
                $t->timestamp('correction_requested_at')->nullable()->index();
            }
            if (! Schema::hasColumn('managed_form_signoffs', 'correction_requested_by_id')) {
                $t->unsignedBigInteger('correction_requested_by_id')->nullable();
            }
            if (! Schema::hasColumn('managed_form_signoffs', 'correction_requested_by_name')) {
                /* Denormalised on purpose: the person who sent it back may later leave the
                   agency, and "returned by a deleted user" is not an answer. */
                $t->string('correction_requested_by_name', 190)->nullable();
            }
            if (! Schema::hasColumn('managed_form_signoffs', 'correction_note')) {
                /* WHAT WAS WRONG. Required by the endpoint: a form returned with no reason
                   is just a rejection, and the parent has nothing to act on. */
                $t->text('correction_note')->nullable();
            }
            if (! Schema::hasColumn('managed_form_signoffs', 'correction_sent_to')) {
                $t->string('correction_sent_to', 320)->nullable();
            }
            if (! Schema::hasColumn('managed_form_signoffs', 'corrected_at')) {
                $t->timestamp('corrected_at')->nullable();
            }
            if (! Schema::hasColumn('managed_form_signoffs', 'correction_count')) {
                $t->unsignedInteger('correction_count')->default(0);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('managed_form_signoffs')) {
            return;
        }
        Schema::table('managed_form_signoffs', function (Blueprint $t) {
            foreach ([
                'correction_requested_at', 'correction_requested_by_id', 'correction_requested_by_name',
                'correction_note', 'correction_sent_to', 'corrected_at', 'correction_count',
            ] as $c) {
                if (Schema::hasColumn('managed_form_signoffs', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
