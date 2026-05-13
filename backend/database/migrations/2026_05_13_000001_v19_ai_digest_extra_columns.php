<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v19 — Add missing columns to ai_daily_digests.
 *
 * AiDigestService writes to source_event_ids, model_used, tokens_used, and
 * language. The original v4 migration only created body, model, generated_at.
 * Without these columns, Eloquent throws "Unknown column" SQL errors when the
 * service tries to save a generated digest. That's why the table has 0 rows.
 *
 * All adds are idempotent (Schema::hasColumn guard) so this is safe to run on
 * any state — fresh install, partial install, or re-run.
 *
 * The legacy `model` column is kept for backward-compat; nothing writes to it
 * any more (service writes `model_used`), but we don't drop it in case any
 * future audit tooling reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_daily_digests', function (Blueprint $t) {
            if (! Schema::hasColumn('ai_daily_digests', 'source_event_ids')) {
                $t->json('source_event_ids')->nullable()->after('body');
            }
            if (! Schema::hasColumn('ai_daily_digests', 'model_used')) {
                $t->string('model_used', 80)->nullable()->after('source_event_ids');
            }
            if (! Schema::hasColumn('ai_daily_digests', 'tokens_used')) {
                $t->unsignedInteger('tokens_used')->nullable()->after('model_used');
            }
            if (! Schema::hasColumn('ai_daily_digests', 'language')) {
                $t->string('language', 12)->nullable()->after('tokens_used');
            }
        });
    }

    public function down(): void
    {
        // Intentional no-op: preserve any historical digest data if rolled back.
    }
};
