<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Draft / published for lesson plans (2026-08-24).
 *
 * lesson_plans had no state at all — every save was immediately live to families, so
 * there was no way to work on next week's plan without publishing it half-finished.
 * (ai_lesson_plans already had a `published` flag; the hand-written ones never did.)
 *
 * Existing rows become 'published': they have been visible to families all along, and
 * silently retracting them behind a draft flag would be worse than the missing feature.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('lesson_plans', 'status')) {
            return;
        }

        Schema::table('lesson_plans', function (Blueprint $table) {
            $table->string('status', 16)->default('published')->after('theme');
            $table->timestamp('published_at')->nullable()->after('status');
        });

        /* Everything that already exists was live. Stamp published_at from the row's own
           history so the audit trail is not rewritten to "published today". */
        DB::table('lesson_plans')->whereNull('published_at')
            ->update(['published_at' => DB::raw('COALESCE(updated_at, created_at)')]);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('lesson_plans', 'status')) {
            return;
        }

        Schema::table('lesson_plans', function (Blueprint $table) {
            $table->dropColumn(['status', 'published_at']);
        });
    }
};
