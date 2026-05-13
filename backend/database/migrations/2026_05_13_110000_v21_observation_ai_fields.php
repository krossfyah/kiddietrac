<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v21 — AI Observation Notes.
 *
 * Extends observations table with AI-structuring fields.
 *
 * Workflow:
 *   1. Educator types a freeform paragraph in 'raw_text'
 *   2. AI service parses it into structured fields (domain, hdlh_milestones, etc.)
 *   3. AI generates a parent-friendly 'family_summary'
 *   4. Educator reviews, optionally edits, then publishes (sets shared_with_family=true)
 *
 * Idempotent: only adds columns that don't exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('observations')) {
            // Should already exist from earlier versions. If not, skip — out of scope.
            return;
        }

        Schema::table('observations', function (Blueprint $t) {
            $cols = [
                'raw_text'             => fn($t) => $t->text('raw_text')->nullable(),
                'hdlh_milestones'      => fn($t) => $t->json('hdlh_milestones')->nullable(),
                'family_summary'       => fn($t) => $t->text('family_summary')->nullable(),
                'ai_generated'         => fn($t) => $t->boolean('ai_generated')->default(false),
                'ai_model_used'        => fn($t) => $t->string('ai_model_used', 80)->nullable(),
                'ai_tokens_used'       => fn($t) => $t->unsignedInteger('ai_tokens_used')->nullable(),
                'ai_processed_at'      => fn($t) => $t->timestamp('ai_processed_at')->nullable(),
                'educator_reviewed_at' => fn($t) => $t->timestamp('educator_reviewed_at')->nullable(),
            ];
            foreach ($cols as $name => $fn) {
                if (! Schema::hasColumn('observations', $name)) {
                    $fn($t);
                }
            }
        });
    }

    public function down(): void
    {
        // Preserve historical AI-generated data on rollback.
    }
};
