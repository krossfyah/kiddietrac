<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Training assigned to a person (2026-10-02): who, which video, by whom, by when.
   Completion is not stored here — it is training_progress.completed_at, the one
   place "watched" is recorded, so an assignment can never disagree with the player.
   Reminder stamps stop the daily job sending the same reminder twice. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('training_assignments')) {
            return;
        }
        Schema::create('training_assignments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id')->index();
            $t->unsignedBigInteger('user_id')->index();          // the person who must watch it
            $t->unsignedBigInteger('video_id')->index();
            $t->unsignedBigInteger('assigned_by');
            $t->date('due_on')->nullable();
            $t->string('note', 500)->nullable();
            $t->timestamp('emailed_at')->nullable();
            $t->timestamp('reminded_due_at')->nullable();
            $t->timestamp('reminded_overdue_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->unsignedBigInteger('cancelled_by')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_assignments');
    }
};
