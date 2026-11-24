<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRIVACY REQUESTS (2026-11-10, PIPEDA review: "Tracker + export"). PIPEDA Principle 4.9 and s. 8: a person may ask
 * to see the personal information held about them (or their child), have it corrected, or withdraw consent. The
 * organization answers within 30 days (s. 8(3)), may extend once by up to 30 more with notice (s. 8(4)), and a
 * missed deadline counts as a refusal (s. 8(5)). See App\Http\Controllers\Api\PrivacyRequestController.
 *
 * privacy_requests: one row per request, owned by an agency. Never deleted - it is the evidence of the answer.
 * privacy_request_events: the timeline - every change, and the steps people record.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('privacy_requests')) {
            Schema::create('privacy_requests', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->string('ref', 20);                                   // PR-2026-001, per agency
                $t->string('kind', 24);                                  // access | correction | deletion | withdraw_consent | other
                $t->string('status', 16)->default('open');               // open | in_progress | completed | refused | withdrawn
                $t->string('requester_name', 160);
                $t->string('requester_relation', 24)->default('self');   // self | parent | representative
                $t->string('subject_type', 10)->nullable();              // user | child - whose information
                $t->unsignedBigInteger('subject_id')->nullable();
                $t->date('received_on');
                $t->date('due_on');                                      // received + 30 days
                $t->date('extended_to')->nullable();                     // one extension of up to 30 more days
                $t->text('extension_reason')->nullable();
                $t->date('identity_verified_on')->nullable();
                $t->string('identity_method', 200)->nullable();
                $t->text('details');                                     // what they asked for
                $t->text('response')->nullable();                        // what was given, corrected or refused, and why
                $t->date('completed_on')->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->unsignedBigInteger('updated_by_id')->nullable();
                $t->timestamp('closed_at')->nullable();
                $t->timestamps();
                $t->unique(['agency_id', 'ref']);
                $t->index(['agency_id', 'status']);
            });
        }
        if (! Schema::hasTable('privacy_request_events')) {
            Schema::create('privacy_request_events', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('request_id');
                $t->unsignedBigInteger('user_id')->nullable();
                $t->string('kind', 16);                                  // created | changed | note | exported
                $t->text('body');
                $t->timestamp('created_at')->nullable();
                $t->index('request_id');
            });
        }
    }

    public function down(): void
    {
        // Deliberately empty: these rows are the record of how each request was answered.
    }
};
