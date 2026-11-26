<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIRE PLANS (2026-11-28, Anthony: "fire drills should allow educators to draw out a fire plan and submit this with
 * details on the fire plan - can you wire this up correctly with a tree fire drill plan").
 *
 * fire_plans: one row per VERSION of the plan for a place (a centre or home, optionally one room). The drawing is
 * kept as vector JSON (editable later) plus a PNG snapshot (for viewing and print); the "what we do" decision tree
 * and the plan details are JSON. A submitted version never changes: editing makes the next version. Review is by a
 * director of that centre or an agency admin (approve, or ask for changes with a comment).
 *
 * drills gains the fire-drill side: the plan used, the route taken, attendance on the day (beside children_count =
 * children evacuated) and the issues found.
 *
 * Idempotent: every table / column is checked first.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fire_plans')) {
            Schema::create('fire_plans', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('centre_id');
                $t->unsignedBigInteger('room_id')->nullable();
                $t->unsignedInteger('version')->default(1);
                $t->string('status', 20)->default('draft');          // draft | submitted | approved | changes
                $t->string('title', 190)->nullable();
                $t->longText('drawing')->nullable();                 // vector JSON
                $t->string('png_path', 255)->nullable();             // private disk, fire-plans/{agency}/...
                $t->string('underlay_path', 255)->nullable();        // the photo / floor plan traced over
                $t->longText('tree')->nullable();                    // decision tree JSON
                $t->text('details')->nullable();                     // plan details JSON
                $t->unsignedBigInteger('based_on_id')->nullable();   // the version this one was edited from
                $t->unsignedBigInteger('drill_id')->nullable();      // the drill it was submitted with
                $t->unsignedBigInteger('created_by')->nullable();
                $t->unsignedBigInteger('submitted_by')->nullable();
                $t->timestamp('submitted_at')->nullable();
                $t->unsignedBigInteger('reviewed_by')->nullable();
                $t->timestamp('reviewed_at')->nullable();
                $t->string('review_comment', 2000)->nullable();
                $t->timestamps();
                $t->timestamp('deleted_at')->nullable();
                $t->index(['centre_id', 'room_id', 'version']);
                $t->index(['agency_id', 'status']);
            });
        }

        Schema::table('drills', function (Blueprint $t) {
            if (! Schema::hasColumn('drills', 'fire_plan_id')) $t->unsignedBigInteger('fire_plan_id')->nullable()->after('improvements');
            if (! Schema::hasColumn('drills', 'route_used')) $t->string('route_used', 20)->nullable()->after('fire_plan_id');
            if (! Schema::hasColumn('drills', 'route_note')) $t->string('route_note', 255)->nullable()->after('route_used');
            if (! Schema::hasColumn('drills', 'attendance_count')) $t->unsignedInteger('attendance_count')->nullable()->after('route_note');
            if (! Schema::hasColumn('drills', 'issues_found')) $t->text('issues_found')->nullable()->after('attendance_count');
        });
    }

    public function down(): void
    {
        // Records are kept: drill and plan records are licensing evidence.
    }
};
