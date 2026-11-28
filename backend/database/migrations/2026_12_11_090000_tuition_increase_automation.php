<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tuition increases that notify families and apply themselves (2026-11-28, App\Support\TuitionIncreases).
 *  - tuition_increases: who scheduled / last changed it, how many families were told, how many fees changed.
 *  - tuition_increase_items: one row per family-child told (phase notice) and per fee changed (phase applied), with
 *    the old and new amount. It is the record of what happened and the guard against doing it twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tuition_increases', function (Blueprint $t) {
            if (! Schema::hasColumn('tuition_increases', 'created_by_id')) $t->unsignedBigInteger('created_by_id')->nullable()->after('notes');
            if (! Schema::hasColumn('tuition_increases', 'updated_by_id')) $t->unsignedBigInteger('updated_by_id')->nullable()->after('created_by_id');
            if (! Schema::hasColumn('tuition_increases', 'notified_families')) $t->unsignedInteger('notified_families')->default(0)->after('notification_sent_at');
            if (! Schema::hasColumn('tuition_increases', 'applied_count')) $t->unsignedInteger('applied_count')->default(0)->after('applied_at');
        });
        if (! Schema::hasTable('tuition_increase_items')) {
            Schema::create('tuition_increase_items', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tuition_increase_id');
                $t->string('phase', 10);   // notice | applied
                $t->unsignedBigInteger('family_id')->nullable();
                $t->unsignedBigInteger('child_id')->nullable();
                $t->unsignedBigInteger('enrollment_id')->nullable();
                $t->unsignedBigInteger('fee_plan_id')->nullable();
                $t->decimal('old_amount', 10, 2);
                $t->decimal('new_amount', 10, 2);
                $t->timestamp('created_at')->nullable();
                $t->index(['tuition_increase_id', 'phase', 'family_id'], 'tii_increase_phase_family');
                $t->index('family_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tuition_increase_items');
        Schema::table('tuition_increases', function (Blueprint $t) {
            foreach (['created_by_id', 'updated_by_id', 'notified_families', 'applied_count'] as $c) {
                if (Schema::hasColumn('tuition_increases', $c)) $t->dropColumn($c);
            }
        });
    }
};
