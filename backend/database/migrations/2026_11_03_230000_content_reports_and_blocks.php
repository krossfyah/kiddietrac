<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * REPORT AND BLOCK IN MESSAGES (2026-11-03, App Store Guideline 1.2 / 2.1: "Any user-generated content, including the
 * required content reporting and blocking mechanisms"). Anthony: "Build Report + Block".
 *   user_blocks      - who someone has blocked. Their messages are hidden from that person and do not notify them.
 *   content_reports  - a reported message, with a copy of what it said, for the agency's admins and KiddieTrac to act on.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_blocks')) {
            Schema::create('user_blocks', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id');           // the person who blocked
                $t->unsignedBigInteger('blocked_user_id');   // the person they blocked
                $t->unsignedBigInteger('agency_id')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->unique(['user_id', 'blocked_user_id']);
                $t->index('blocked_user_id');
            });
        }
        if (! Schema::hasTable('content_reports')) {
            Schema::create('content_reports', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->nullable();
                $t->unsignedBigInteger('reporter_id');
                $t->unsignedBigInteger('reported_user_id')->nullable();
                $t->string('kind', 10);                       // family | staff
                $t->unsignedBigInteger('conversation_id');    // conversations.id or staff_threads.id
                $t->unsignedBigInteger('message_id')->nullable();
                $t->string('reason', 40);
                $t->text('note')->nullable();
                $t->text('excerpt')->nullable();              // what the message said when it was reported
                $t->boolean('blocked')->default(false);       // the reporter also blocked them
                $t->string('status', 12)->default('open');    // open | handled
                $t->unsignedBigInteger('handled_by')->nullable();
                $t->text('handled_note')->nullable();
                $t->timestamp('handled_at')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->index(['agency_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('content_reports');
        Schema::dropIfExists('user_blocks');
    }
};
