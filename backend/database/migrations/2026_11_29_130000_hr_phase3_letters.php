<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KiddieTrac HR Phase 3, item 12 (2026-11-26, App\Support\HrLetters):
 *  - hr_letter_templates: the agency's editable HR letters (offer, contract, contractor agreement, warning,
 *    termination). reviewed_on = "Reviewed by our lawyer on <date>", ticked by an admin; nothing sends until then.
 *  - hr_letters: each letter sent for e-signature (the Managed Forms row it became).
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('hr_letter_templates')) {
            Schema::create('hr_letter_templates', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->string('kind', 20);                 // offer | contract | contractor | warning | termination
                $t->string('title', 150);
                $t->longText('body');
                $t->boolean('is_default')->default(true);   // still KiddieTrac's starting text (never edited)
                $t->date('reviewed_on')->nullable();
                $t->unsignedBigInteger('reviewed_by_id')->nullable();
                $t->unsignedBigInteger('updated_by_id')->nullable();
                $t->timestamps();
                $t->unique(['agency_id', 'kind']);
            });
        }
        if (! Schema::hasTable('hr_letters')) {
            Schema::create('hr_letters', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('template_id');
                $t->string('kind', 20);
                $t->unsignedBigInteger('user_id')->index();
                $t->unsignedBigInteger('centre_id')->nullable();
                $t->unsignedBigInteger('signatory_id');
                $t->unsignedBigInteger('managed_form_id')->nullable();
                $t->unsignedBigInteger('sent_by_id');
                $t->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_letters');
        Schema::dropIfExists('hr_letter_templates');
    }
};
