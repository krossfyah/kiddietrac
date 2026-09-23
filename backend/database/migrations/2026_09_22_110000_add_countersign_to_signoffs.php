<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COUNTER-SIGNING A COMPLETED FORM (2026-09-22)
 *
 * Anthony: "for forms manager and the multiple forms that came back completed from the
 * parent can you add a way to review and counter-sign from the agency and send a
 * completed form back to both sides".
 *
 * Until now a signoff recorded ONE party. The parent filled it, signed it, and the agency
 * received a copy — and there was nowhere to record that anybody had looked at it, let
 * alone agreed to it. These columns make the second half of that exchange a fact rather
 * than an assumption.
 *
 * DELIBERATELY SEPARATE FROM THE FIRST SIGNATURE. `signature` stays exactly as the parent
 * left it and is never rewritten: a counter-signature is a second act by a second party,
 * and a record that merges the two cannot answer "what did the parent actually sign".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('managed_form_signoffs')) {
            return;
        }

        Schema::table('managed_form_signoffs', function (Blueprint $t) {
            if (! Schema::hasColumn('managed_form_signoffs', 'countersigned_at')) {
                $t->timestamp('countersigned_at')->nullable();
                $t->unsignedBigInteger('countersigned_by_id')->nullable();
                $t->string('countersigner_name', 190)->nullable();
                /* The person's ROLE at the time, copied not joined: a director who later
                   changes role must not retroactively change who signed this. */
                $t->string('countersigner_role', 60)->nullable();
                $t->longText('countersignature')->nullable();
                $t->text('countersign_note')->nullable();
                /* The stamped PDF. Nullable because the stamp can fail (no ghostscript, a
                   PDF that will not import) and a counter-signature must still be
                   recorded when it does — see ManagedFormCountersign::append(). */
                $t->string('countersigned_file_url', 512)->nullable();
                $t->timestamp('returned_at')->nullable();
                $t->string('returned_to', 400)->nullable();
            }
        });
    }

    public function down(): void
    {
        // Deliberately not dropped: these columns hold signatures.
    }
};
