<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Files on a support ticket: a screenshot, an export, a log.
 *
 * support_ticket_messages already had an `attachments` column, but the ticket ITSELF had
 * nowhere to hold one — so the evidence had to be described in prose at exactly the
 * moment somebody is least able to describe it. "The screen went white" plus a
 * screenshot is a bug report; without the screenshot it is a conversation.
 *
 * A table rather than a JSON column so each file has its own row to delete, and so a
 * file can be attached to a later message as well as to the ticket.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('support_ticket_files')) {
            return;
        }

        Schema::create('support_ticket_files', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ticket_id')->index();
            // Null when attached at creation, set when attached to a specific reply.
            $table->unsignedBigInteger('message_id')->nullable()->index();
            $table->string('path', 300);
            $table->string('original_name', 255);
            $table->string('mime', 120)->nullable();
            $table->unsignedInteger('size_bytes')->default(0);
            $table->unsignedBigInteger('uploaded_by_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_files');
    }
};
