<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* "Reply 1 running late, 2 absent today" on the Safe Arrival / check-in reminder texts (2026-11-25). App\Support\SmsAttendance. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sms_attendance_prompts')) {
            return;
        }
        Schema::create('sms_attendance_prompts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id');
            $t->unsignedBigInteger('guardian_user_id');
            $t->string('phone10', 10);
            $t->date('for_date');
            $t->text('children');                       // [{id, first}] in reply-number order
            $t->string('source', 32)->nullable();
            $t->unsignedBigInteger('await_child_id')->nullable();   // asked "what time?" for this child
            $t->timestamp('await_until')->nullable();
            $t->string('last_action', 16)->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
            $t->unique(['agency_id', 'guardian_user_id', 'phone10', 'for_date'], 'sms_att_prompt_unique');
            $t->index(['phone10', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_attendance_prompts');
    }
};
