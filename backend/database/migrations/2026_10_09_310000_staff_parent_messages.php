<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Texts and emails an educator sends a child's parents through KiddieTrac (2026-10-09). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('staff_parent_messages')) {
            Schema::create('staff_parent_messages', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('child_id');
                $t->unsignedBigInteger('family_id');
                $t->unsignedBigInteger('sender_id');
                $t->unsignedBigInteger('guardian_user_id');
                $t->string('channel', 8);          // sms | email
                $t->string('to_address', 190)->nullable();
                $t->text('body');
                $t->string('status', 20);          // sent | skipped | failed | ...
                $t->text('error')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->index(['child_id', 'id']);
                $t->index(['agency_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_parent_messages');
    }
};
