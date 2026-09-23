<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ASK SOMEBODY FOR FILES, AND KNOW WHEN THEY ARRIVE.
 *
 * Forms cover the case where the agency supplies the document and the family fills it in.
 * The other half has had no home at all: "send me a copy of her immunisation card, both
 * sides of your ID, and last year's tax slip". That happened over email, so nobody could
 * answer "what did we ask the Hoseins for, and what is still missing" without reading a
 * thread.
 *
 * Three tables, because a request is a list and a list item can be satisfied more than
 * once ("two photos of the vaccination record"):
 *
 *   file_requests       one ask, to one person, with a priority and an optional due date
 *   file_request_items  one line of it: what, what kind of file, how many
 *
 * The FILES themselves are not a third table. They go into `documents` scoped to the
 * person who uploaded them, tagged with source_type/source_id pointing at the item -- the
 * same filing every signed form already uses. That is what makes an uploaded file appear
 * on the user's record and in their own Documents screen without a line of extra code,
 * and it means there is one answer to "where do this person's files live".
 * (Anthony, 2026-09-10)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_requests', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id')->index();
            $t->unsignedBigInteger('requested_by_id')->nullable();
            $t->string('requested_by_name', 160)->nullable();

            /* The recipient is an ACCOUNT, not an address. A file has to land on somebody's
               record, and an address with nobody behind it has no record to land on. The
               address is kept beside it as a snapshot of where the ask was sent. */
            $t->unsignedBigInteger('user_id')->index();
            $t->string('email', 190)->nullable();

            $t->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal');
            $t->date('due_on')->nullable();
            $t->text('note')->nullable();

            // open -> complete when every item has the files it asked for.
            $t->enum('status', ['open', 'complete', 'cancelled'])->default('open')->index();
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('notified_at')->nullable();     // the ask was emailed
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
        });

        Schema::create('file_request_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('file_request_id')->index();
            $t->string('description', 200);
            /* What kind of file will satisfy this line. 'any' is the default because the
               point is to get the document, not to argue about its format. */
            $t->enum('kind', ['any', 'pdf', 'image', 'document'])->default('any');
            $t->unsignedSmallInteger('quantity')->default(1);
            $t->unsignedSmallInteger('display_order')->default(0);
            $t->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_request_items');
        Schema::dropIfExists('file_requests');
    }
};
