<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff-to-staff direct messaging (#38). A participants model so 1:1 works now
 * and group threads are a later add (just more participant rows). Agency-scoped.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('staff_threads')) {
            Schema::create('staff_threads', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamp('last_message_at')->nullable()->index();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('staff_thread_participants')) {
            Schema::create('staff_thread_participants', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('thread_id')->index();
                $t->unsignedBigInteger('user_id')->index();
                $t->timestamp('last_read_at')->nullable();
                $t->timestamps();
                $t->unique(['thread_id', 'user_id']);
            });
        }

        if (! Schema::hasTable('staff_messages')) {
            Schema::create('staff_messages', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('thread_id')->index();
                $t->unsignedBigInteger('sender_id');
                $t->text('body');
                $t->timestamp('created_at')->nullable()->index();
                $t->timestamp('updated_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_messages');
        Schema::dropIfExists('staff_thread_participants');
        Schema::dropIfExists('staff_threads');
    }
};
