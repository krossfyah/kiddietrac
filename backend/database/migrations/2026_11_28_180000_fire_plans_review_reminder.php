<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIRE PLAN YEARLY REVIEW REMINDER (2026-11-28, Anthony): when the current plan for a place reaches 12 months since
 * its last review, the author and that centre's directors are reminded once, then weekly until a new version is
 * approved. review_reminded_at is when the last reminder went out (fire-plans:remind). Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fire_plans') && ! Schema::hasColumn('fire_plans', 'review_reminded_at')) {
            Schema::table('fire_plans', function (Blueprint $t) {
                $t->timestamp('review_reminded_at')->nullable()->after('review_comment');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('fire_plans') && Schema::hasColumn('fire_plans', 'review_reminded_at')) {
            Schema::table('fire_plans', function (Blueprint $t) {
                $t->dropColumn('review_reminded_at');
            });
        }
    }
};
