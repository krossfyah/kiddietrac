<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v20 — Incident Reports end-to-end.
 *
 * Creates (or extends) the incidents table to support:
 *  - Per-incident type classification (general / injury / illness / serious_occurrence / behavioural / medication_error / other)
 *  - Educator-recorded narrative, witnesses, location, action_taken
 *  - Director review workflow (status: draft -> submitted -> director_reviewed -> parent_notified -> acknowledged -> closed)
 *  - Parent acknowledgment with audit trail (signature: name typed + IP + UA + timestamp)
 *  - Ministry/serious-occurrence flag for CCEYA reporting
 *
 * Idempotent: every Schema::hasTable / Schema::hasColumn check is guarded.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ============================================================
        // Main incidents table
        // ============================================================
        if (! Schema::hasTable('incidents')) {
            Schema::create('incidents', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('child_id');
                $t->unsignedBigInteger('room_id')->nullable();
                $t->unsignedBigInteger('centre_id');
                $t->unsignedBigInteger('recorded_by_id'); // educator user_id
                $t->unsignedBigInteger('reviewed_by_id')->nullable(); // director user_id

                $t->enum('incident_type', [
                    'general',
                    'injury',
                    'illness',
                    'serious_occurrence',
                    'behavioural',
                    'medication_error',
                    'other',
                ])->default('general');

                $t->enum('severity', ['low', 'medium', 'high'])->default('low');
                $t->boolean('is_serious_occurrence')->default(false); // CCEYA flag

                $t->timestamp('occurred_at');
                $t->string('location', 160)->nullable(); // e.g. "outdoor playground", "Acorn Room"

                $t->text('description');                  // what happened
                $t->text('action_taken')->nullable();     // first aid / response
                $t->text('follow_up_required')->nullable();

                $t->json('witnesses')->nullable();        // array of {name, role, user_id?}
                $t->json('body_parts_affected')->nullable(); // for injury reports

                $t->enum('status', [
                    'draft',
                    'submitted',
                    'director_reviewed',
                    'parent_notified',
                    'acknowledged',
                    'closed',
                ])->default('draft');

                $t->timestamp('submitted_at')->nullable();
                $t->timestamp('reviewed_at')->nullable();
                $t->timestamp('parent_notified_at')->nullable();
                $t->timestamp('acknowledged_at')->nullable();
                $t->timestamp('closed_at')->nullable();

                $t->text('director_notes')->nullable();

                $t->timestamps();

                $t->index(['centre_id', 'occurred_at']);
                $t->index(['child_id', 'occurred_at']);
                $t->index('status');
                $t->index('is_serious_occurrence');
            });
        } else {
            // Existing table — additive columns only
            Schema::table('incidents', function (Blueprint $t) {
                $cols = [
                    'incident_type'         => fn($t) => $t->enum('incident_type', ['general','injury','illness','serious_occurrence','behavioural','medication_error','other'])->default('general'),
                    'is_serious_occurrence' => fn($t) => $t->boolean('is_serious_occurrence')->default(false),
                    'severity'              => fn($t) => $t->enum('severity', ['low','medium','high'])->default('low'),
                    'location'              => fn($t) => $t->string('location', 160)->nullable(),
                    'action_taken'          => fn($t) => $t->text('action_taken')->nullable(),
                    'follow_up_required'    => fn($t) => $t->text('follow_up_required')->nullable(),
                    'witnesses'             => fn($t) => $t->json('witnesses')->nullable(),
                    'body_parts_affected'   => fn($t) => $t->json('body_parts_affected')->nullable(),
                    'status'                => fn($t) => $t->enum('status', ['draft','submitted','director_reviewed','parent_notified','acknowledged','closed'])->default('draft'),
                    'submitted_at'          => fn($t) => $t->timestamp('submitted_at')->nullable(),
                    'reviewed_at'           => fn($t) => $t->timestamp('reviewed_at')->nullable(),
                    'parent_notified_at'    => fn($t) => $t->timestamp('parent_notified_at')->nullable(),
                    'acknowledged_at'       => fn($t) => $t->timestamp('acknowledged_at')->nullable(),
                    'closed_at'             => fn($t) => $t->timestamp('closed_at')->nullable(),
                    'director_notes'        => fn($t) => $t->text('director_notes')->nullable(),
                    'reviewed_by_id'        => fn($t) => $t->unsignedBigInteger('reviewed_by_id')->nullable(),
                ];
                foreach ($cols as $name => $fn) {
                    if (! Schema::hasColumn('incidents', $name)) {
                        $fn($t);
                    }
                }
            });
        }

        // ============================================================
        // Parent acknowledgments (audit trail — one row per ACK action)
        // ============================================================
        if (! Schema::hasTable('incident_acknowledgments')) {
            Schema::create('incident_acknowledgments', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('incident_id');
                $t->unsignedBigInteger('user_id');     // parent who acknowledged
                $t->string('signed_name', 160);        // typed full name acts as signature
                $t->string('ip_address', 45)->nullable();
                $t->string('user_agent', 255)->nullable();
                $t->text('comment')->nullable();       // optional parent comment
                $t->timestamp('signed_at')->useCurrent();
                $t->timestamps();

                $t->index('incident_id');
                $t->index('user_id');
            });
        }

        // ============================================================
        // Photos / attachments linked to an incident
        // ============================================================
        if (! Schema::hasTable('incident_attachments')) {
            Schema::create('incident_attachments', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('incident_id');
                $t->unsignedBigInteger('media_id')->nullable();  // links to media table if photo
                $t->string('file_url', 500)->nullable();         // raw URL if direct upload
                $t->string('caption', 255)->nullable();
                $t->timestamps();

                $t->index('incident_id');
            });
        }
    }

    public function down(): void
    {
        // Preserve incident history on rollback (no-op for data tables).
    }
};
