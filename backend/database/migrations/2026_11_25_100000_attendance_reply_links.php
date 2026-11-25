<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* "Mark absent / Running late" buttons in the missed-child emails (2026-11-25). App\Support\AttendanceReply. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attendance_reply_links')) {
            return;
        }
        Schema::create('attendance_reply_links', function (Blueprint $t) {
            $t->id();
            $t->char('token_hash', 64)->unique();
            $t->unsignedBigInteger('agency_id')->index();
            $t->unsignedBigInteger('child_id')->index();
            $t->unsignedBigInteger('family_id');
            $t->unsignedBigInteger('guardian_user_id');
            $t->date('for_date');
            $t->string('source', 32);              // checkin_reminder | safe_arrival_ask | safe_arrival_no_show
            $t->timestamp('expires_at')->nullable();
            $t->string('last_action', 16)->nullable();   // absent | late
            $t->timestamp('last_action_at')->nullable();
            $t->unsignedInteger('uses')->default(0);
            $t->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_reply_links');
    }
};
