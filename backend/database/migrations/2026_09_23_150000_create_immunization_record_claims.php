<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHAT THE PARENT SAYS THE CARD SHOWS (2026-09-23)
 *
 * Parents can now tick the immunizations their uploaded record covers. Those ticks are
 * deliberately NOT written to `immunizations`.
 *
 * Reading a card is a clinical judgement and it is the centre's to make — deciding that
 * a smudged line means "DTaP-IPV-Hib, 2nd dose" is the act that clears a compliance
 * flag, and a family must not be able to clear their own. That rule is enforced in
 * ParentImmunizationRecordController and it has not moved.
 *
 * So a parent's ticks land here instead: a claim, attached to the document they
 * uploaded, saying "I believe this record shows these". Staff see it beside the card
 * when they transcribe, which turns an unreadable photo into a checklist to confirm
 * rather than a puzzle to solve. Nothing here counts towards compliance until a human
 * at the centre writes the matching row in `immunizations`.
 *
 * Its own table rather than a column on `documents`: `documents` is shared by every
 * category on the platform and this belongs to exactly one of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('immunization_record_claims')) {
            return;
        }

        Schema::create('immunization_record_claims', function (Blueprint $t) {
            $t->id();

            /* The uploaded card this claim describes. */
            $t->unsignedBigInteger('document_id')->index();

            /* Denormalised so the staff view can filter without joining through
               documents, and so a claim is still attributable if the document row is
               ever moved between scopes. */
            $t->unsignedBigInteger('child_id')->index();
            $t->unsignedBigInteger('agency_id')->nullable()->index();

            /* Matches immunization_schedule.vaccine / dose_label rather than referencing
               its id: a schedule row can be edited or retired, and a claim must still
               read correctly afterwards. What the parent ticked is a statement about the
               card, not a pointer into a table that moves. */
            $t->string('vaccine', 100);
            $t->string('dose_label', 40)->nullable();

            /* Optional and untrusted, exactly like the rest of this row. */
            $t->date('administered_on')->nullable();

            $t->unsignedBigInteger('claimed_by_id')->nullable();

            /* Set when a staff member turns this claim into a real dose, so the same
               card is not transcribed twice and "who confirmed it" is answerable. */
            $t->timestamp('confirmed_at')->nullable();
            $t->unsignedBigInteger('confirmed_by_id')->nullable();
            $t->unsignedBigInteger('immunization_id')->nullable();

            $t->timestamps();

            $t->unique(['document_id', 'vaccine', 'dose_label'], 'imm_claim_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('immunization_record_claims');
    }
};
