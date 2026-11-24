<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRIVACY BREACH REGISTER (2026-11-06, Anthony: "add a breach section in the portal to record these types of events -
 * fully wire this up"). PIPEDA s. 10.3 and the Breach of Security Safeguards Regulations s. 6: every breach of
 * security safeguards involving personal information is recorded and the record kept 24 months after the day the
 * organization decides a breach occurred - whether or not it was reported. See App\Http\Controllers\Api\PrivacyBreachController.
 *
 * privacy_breaches: one row per breach, owned by an agency. Never deleted while retain_until is in the future.
 * privacy_breach_events: the timeline - every change of status / assessment, and the steps people record
 * (recipients asked to delete, families notified, ...).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('privacy_breaches')) {
            Schema::create('privacy_breaches', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('centre_id')->nullable();
                $t->string('ref', 20);                                   // PB-2026-001, per agency
                $t->string('title', 200);
                $t->string('status', 16)->default('open');               // open | assessing | reported | closed
                $t->date('occurred_on')->nullable();
                $t->date('occurred_to')->nullable();
                $t->date('discovered_on');
                $t->date('determined_on')->nullable();                   // decided it IS a breach: starts the 24 months
                $t->date('contained_on')->nullable();
                $t->text('description');                                 // what happened
                $t->text('cause')->nullable();
                $t->json('info_types')->nullable();                      // kinds of personal information involved
                $t->text('info_detail')->nullable();
                $t->unsignedInteger('individuals_affected')->nullable();
                $t->unsignedInteger('children_affected')->nullable();
                $t->text('recipients')->nullable();                      // who received / could see it
                $t->text('containment')->nullable();                     // steps to contain and reduce harm
                $t->string('sensitivity', 8)->nullable();                // low | medium | high
                $t->string('misuse_likelihood', 8)->nullable();          // low | medium | high
                $t->string('rrosh', 14)->default('undetermined');        // undetermined | yes | no
                $t->text('rrosh_reasoning')->nullable();
                $t->date('opc_reported_on')->nullable();
                $t->string('opc_reference', 100)->nullable();
                $t->date('individuals_notified_on')->nullable();
                $t->string('notification_method', 200)->nullable();
                $t->text('others_notified')->nullable();
                $t->date('retain_until');
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->unsignedBigInteger('updated_by_id')->nullable();
                $t->timestamp('closed_at')->nullable();
                $t->timestamps();
                $t->unique(['agency_id', 'ref']);
                $t->index(['agency_id', 'status']);
            });
        }
        if (! Schema::hasTable('privacy_breach_events')) {
            Schema::create('privacy_breach_events', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('breach_id');
                $t->unsignedBigInteger('user_id')->nullable();
                $t->string('kind', 16);                                  // created | changed | note
                $t->text('body');
                $t->timestamp('created_at')->nullable();
                $t->index('breach_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('privacy_breach_events');
        Schema::dropIfExists('privacy_breaches');
    }
};
