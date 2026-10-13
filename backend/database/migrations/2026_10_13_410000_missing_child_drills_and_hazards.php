<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Ontario 2027 (O. Reg. 137/15 as amended by O. Reg. 197/26, in force 2027-01-01):
     s.68.2 — a missing-child drill at least every 3 months, records kept 12+ months.
              The drill log gains the fields a missing-child drill needs, and
              drill_cadence_reminders remembers which due/overdue state each centre was last
              told about, so the daily reminder fires once per change, not every day.
     s.24.3 — parents told about non-routine hazards at the premises and the steps taken
              (centre_hazards). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('drills')) {
            Schema::table('drills', function (Blueprint $t) {
                if (! Schema::hasColumn('drills', 'area')) $t->string('area', 255)->nullable()->after('room_id');          // rooms / area covered
                if (! Schema::hasColumn('drills', 'scenario')) $t->text('scenario')->nullable()->after('notes');
                if (! Schema::hasColumn('drills', 'led_by')) $t->string('led_by', 160)->nullable()->after('scenario');
                if (! Schema::hasColumn('drills', 'located_seconds')) $t->unsignedInteger('located_seconds')->nullable()->after('led_by');   // until the child was found / everyone accounted for
                if (! Schema::hasColumn('drills', 'simulated_911')) $t->boolean('simulated_911')->nullable()->after('located_seconds');
                if (! Schema::hasColumn('drills', 'simulated_parent_call')) $t->boolean('simulated_parent_call')->nullable()->after('simulated_911');
                if (! Schema::hasColumn('drills', 'improvements')) $t->text('improvements')->nullable()->after('simulated_parent_call');
            });
        }

        if (! Schema::hasTable('drill_cadence_reminders')) {
            Schema::create('drill_cadence_reminders', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('centre_id');
                $t->string('type', 20);                 // missing_child | fire
                $t->string('state', 20);                // ok | due | due_soon | overdue
                $t->string('due_on', 10)->nullable();   // the due date the reminder was about (agency date)
                $t->dateTime('reminded_at')->nullable();
                $t->timestamps();
                $t->unique(['centre_id', 'type']);
            });
        }

        if (! Schema::hasTable('centre_hazards')) {
            Schema::create('centre_hazards', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('centre_id');
                $t->string('title', 200);
                $t->text('description')->nullable();
                $t->string('location', 255)->nullable();
                $t->dateTime('identified_at');                       // UTC
                $t->string('severity', 10)->default('medium');       // low | medium | high
                $t->text('steps_taken')->nullable();
                $t->string('status', 10)->default('open');           // open | resolved
                $t->dateTime('resolved_at')->nullable();
                $t->text('resolution_note')->nullable();
                $t->boolean('notify_parents')->default(false);
                $t->dateTime('notified_at')->nullable();
                $t->unsignedBigInteger('notified_by')->nullable();
                $t->unsignedInteger('notified_families')->nullable();
                $t->dateTime('resolution_notified_at')->nullable();
                $t->unsignedBigInteger('resolution_notified_by')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();
                $t->index(['agency_id', 'status']);
                $t->index(['centre_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('centre_hazards');
        Schema::dropIfExists('drill_cadence_reminders');
    }
};
