<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What happened to a ticket, and how it ended.
 *
 * support_tickets could say a ticket was resolved and when, but not by whom or WHAT
 * FIXED IT — so the answer lived in somebody's memory and the ticket screen could only
 * show a status word. And nothing recorded the steps in between: a ticket that went
 * open → awaiting_user → open → resolved over three days looked identical to one someone
 * closed the minute it arrived.
 *
 * Two additions:
 *   • resolution / resolved_by_user_id — the answer, written down, attached to the thing
 *     it answers.
 *   • support_ticket_events — one row per change, so the ticket can show its own history
 *     instead of just its current state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            if (! Schema::hasColumn('support_tickets', 'resolution')) {
                $table->text('resolution')->nullable()->after('resolved_at');
            }
            if (! Schema::hasColumn('support_tickets', 'resolved_by_user_id')) {
                $table->unsignedBigInteger('resolved_by_user_id')->nullable()->after('resolution');
            }
        });

        if (! Schema::hasTable('support_ticket_events')) {
            Schema::create('support_ticket_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('ticket_id')->index();
                // status | assigned | priority | created — what KIND of change this was,
                // so the timeline can word each one properly instead of printing a diff.
                $table->string('type', 30);
                $table->string('from_value', 60)->nullable();
                $table->string('to_value', 60)->nullable();
                $table->text('note')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_events');
        Schema::table('support_tickets', function (Blueprint $table) {
            foreach (['resolution', 'resolved_by_user_id'] as $c) {
                if (Schema::hasColumn('support_tickets', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
