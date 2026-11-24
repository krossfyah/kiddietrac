<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Emails linked to people (2026-11-07) - App\Support\PersonMail.
 *
 * One row per email per person it is linked to: a parent, a staff member or a directory contact in the mailbox's
 * agency. Only what the audit trail needs is kept - who, when, the subject and Outlook's short preview (both sealed,
 * App\Support\Sensitive); the email itself stays in the mailbox and is fetched live when someone opens it.
 * An unlink is a stamp (unlinked_at / unlinked_by), never a delete, so the trail survives.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_person_links')) return;
        Schema::create('email_person_links', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id')->index();
            $t->unsignedBigInteger('account_id')->index();
            $t->string('message_key', 64);                  // sha256 of internetMessageId (else the provider's id)
            $t->string('provider_uid', 600)->nullable();    // Graph / Gmail id, or "folder|uid" on IMAP
            $t->string('direction', 3)->default('in');      // in | out
            $t->string('from_addr', 190)->nullable();
            $t->string('from_name', 190)->nullable();
            $t->text('to_addrs')->nullable();
            $t->text('subject')->nullable();                // ktenc:v1:...
            $t->text('preview')->nullable();                // ktenc:v1:...
            $t->boolean('has_attachments')->default(false);
            $t->timestamp('received_at')->nullable()->index();
            $t->string('person_type', 12);                  // user | contact
            $t->unsignedBigInteger('person_id');
            $t->unsignedBigInteger('family_id')->nullable()->index();
            $t->string('matched_as', 12)->nullable();       // guardian | staff | contact
            $t->string('source', 8)->default('auto');       // auto | manual
            $t->unsignedBigInteger('linked_by')->nullable();
            $t->timestamp('unlinked_at')->nullable();
            $t->unsignedBigInteger('unlinked_by')->nullable();
            $t->timestamps();
            $t->unique(['agency_id', 'message_key', 'person_type', 'person_id'], 'epl_msg_person');
            $t->index(['person_type', 'person_id', 'unlinked_at'], 'epl_person');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_person_links');
    }
};
