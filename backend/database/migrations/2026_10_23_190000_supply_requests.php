<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/* Supplies an educator asks a family to bring (2026-10-23, App\Support\Supplies). Each
   request is one child; the family is told through a family-scoped announcement (push,
   email, reminder, Done) and a task on each parent's list. */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('supply_requests')) {
            Schema::create('supply_requests', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('centre_id')->nullable()->index();
                $t->unsignedBigInteger('family_id')->index();
                $t->unsignedBigInteger('child_id')->index();
                $t->unsignedBigInteger('requested_by')->nullable();
                $t->text('items');                       // JSON [{label, note}]
                $t->string('note', 1000)->nullable();
                $t->date('needed_by')->nullable();
                // open | coming | cant | dropped | received | cancelled
                $t->string('status', 12)->default('open')->index();
                $t->string('response_note', 500)->nullable();
                $t->unsignedBigInteger('responded_by')->nullable();
                $t->timestamp('responded_at')->nullable();
                $t->unsignedBigInteger('received_by')->nullable();
                $t->timestamp('received_at')->nullable();
                $t->unsignedBigInteger('announcement_id')->nullable()->index();
                $t->timestamp('overdue_notified_at')->nullable();
                $t->timestamps();
            });
        }
        Schema::table('tasks', function (Blueprint $t) {
            if (! Schema::hasColumn('tasks', 'source')) $t->string('source', 20)->nullable();
            if (! Schema::hasColumn('tasks', 'source_id')) $t->unsignedBigInteger('source_id')->nullable()->index();
        });
        // A family is now somewhere an announcement can go.
        DB::statement("ALTER TABLE announcements MODIFY scope_type ENUM('agency','centre','room','family') NOT NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('supply_requests');
        Schema::table('tasks', fn (Blueprint $t) => $t->dropColumn(['source', 'source_id']));
    }
};
