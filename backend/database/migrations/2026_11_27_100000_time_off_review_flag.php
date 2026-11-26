<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KiddieTrac HR (2026-11-26): leave that needs no approval is recorded and deducted at once, and flagged "To review"
 * for the managers until one of them marks it Reviewed. Idempotent; down() does nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_off_requests', function (Blueprint $t) {
            if (! Schema::hasColumn('time_off_requests', 'review_pending')) {
                $t->boolean('review_pending')->default(false);
            }
            if (! Schema::hasColumn('time_off_requests', 'reviewed_by_id')) {
                $t->unsignedBigInteger('reviewed_by_id')->nullable();
            }
            if (! Schema::hasColumn('time_off_requests', 'reviewed_at')) {
                $t->timestamp('reviewed_at')->nullable();
            }
        });
    }

    public function down(): void
    {
    }
};
