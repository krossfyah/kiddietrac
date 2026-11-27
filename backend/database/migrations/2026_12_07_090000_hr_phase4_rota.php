<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KiddieTrac HR Phase 4, batch 8 (2026-11-27): rota drafts + publish, shift presets, labour budgets, shift acceptance,
 * punch-correction requests (App\Support\HrRota, App\Support\HrPunchFixes).
 *
 * - hr_shift_drafts: changes a director has made to the rota that are NOT published yet. They live here, never in
 *   `shifts`, so nothing that reads shifts (clock-in, lateness, ratios, payroll, timesheets, calendar feeds, the staff
 *   member's own schedule, shift notices) can see a draft. kind add = a new shift; change = new values for a published
 *   shift (shift_id); cancel = a published shift to remove (shift_id). One pending change per shift (unique shift_id).
 *   Publishing applies them to `shifts` through the ordinary paths (shift notices batch them per person).
 * - hr_shift_presets: named shifts ("Early 7-3") with a colour, agency-wide (centre_id null) or one centre.
 * - hr_labour_budgets: a weekly labour budget per centre (seen only by people who may see pay).
 * - hr_shift_acceptances: when the agency asks staff to accept published shifts: one row per shift, for the person
 *   it was published to (pending | accepted | declined).
 * - hr_punch_corrections: a staff member asks for a clock-in to be fixed (or a missing one added); a director or admin
 *   (never the person) approves; refused once the timesheet is locked.
 * - shifts.preset_id: which preset a shift was made from (its colour on the grid). Nullable; nothing else changes.
 * Additive and idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hr_shift_drafts')) {
            Schema::create('hr_shift_drafts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('centre_id')->index();
                $t->string('kind', 8);
                $t->unsignedBigInteger('shift_id')->nullable()->unique();
                $t->unsignedBigInteger('user_id');
                $t->unsignedBigInteger('room_id');
                $t->dateTime('starts_at');
                $t->dateTime('ends_at');
                $t->string('role', 12)->default('support');
                $t->unsignedBigInteger('preset_id')->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->unsignedBigInteger('updated_by_id')->nullable();
                $t->timestamps();
                $t->index(['centre_id', 'starts_at']);
            });
        }
        if (! Schema::hasTable('hr_shift_presets')) {
            Schema::create('hr_shift_presets', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('centre_id')->nullable()->index();
                $t->string('name', 60);
                $t->time('start_time');
                $t->time('end_time');
                $t->string('role', 12)->default('support');
                $t->string('colour', 7)->default('#0E7490');
                $t->unsignedSmallInteger('sort')->default(0);
                $t->timestamp('archived_at')->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('hr_labour_budgets')) {
            Schema::create('hr_labour_budgets', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('centre_id')->unique();
                $t->decimal('weekly_amount', 10, 2);
                $t->unsignedBigInteger('updated_by_id')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('hr_shift_acceptances')) {
            Schema::create('hr_shift_acceptances', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('centre_id')->index();
                $t->unsignedBigInteger('shift_id')->unique();
                $t->unsignedBigInteger('user_id')->index();
                $t->string('status', 10)->default('pending');
                $t->string('note', 500)->nullable();
                $t->timestamp('responded_at')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('hr_punch_corrections')) {
            Schema::create('hr_punch_corrections', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('centre_id')->index();
                $t->unsignedBigInteger('user_id')->index();
                $t->unsignedBigInteger('punch_id')->nullable()->index();
                $t->string('kind', 8);
                $t->dateTime('was_in')->nullable();
                $t->dateTime('was_out')->nullable();
                $t->dateTime('want_in');
                $t->dateTime('want_out')->nullable();
                $t->string('reason', 500);
                $t->string('status', 10)->default('pending');
                $t->unsignedBigInteger('decided_by_id')->nullable();
                $t->timestamp('decided_at')->nullable();
                $t->string('decision_comment', 500)->nullable();
                $t->unsignedBigInteger('applied_punch_id')->nullable();
                $t->timestamps();
                $t->index(['agency_id', 'status']);
            });
        }
        if (Schema::hasTable('shifts') && ! Schema::hasColumn('shifts', 'preset_id')) {
            Schema::table('shifts', function (Blueprint $t) {
                $t->unsignedBigInteger('preset_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Additive; nothing is dropped automatically.
    }
};
