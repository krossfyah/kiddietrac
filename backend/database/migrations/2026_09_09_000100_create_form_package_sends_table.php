<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A record of every form package that was sent.
 *
 * bulk-assign wrote an audit row and nothing else, so "what did we send the Hoseins, and
 * when" could only be answered by reading the audit log — which is not a screen anybody
 * uses for this. The recipients and titles are stored as a SNAPSHOT rather than as
 * foreign keys: renaming a form later must not rewrite the history of what was sent, and
 * a person who leaves should still appear in the record of a package they were sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('form_package_sends')) {
            return;
        }

        Schema::create('form_package_sends', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id')->index();
            $t->unsignedBigInteger('sent_by_id')->nullable();
            $t->string('sent_by_name', 160)->nullable();

            // Snapshots — see the note above.
            $t->text('form_titles');            // JSON array of titles as they read that day
            $t->text('recipients');             // JSON [{name,email}]
            $t->unsignedSmallInteger('form_count')->default(0);
            $t->unsignedSmallInteger('recipient_count')->default(0);

            $t->unsignedSmallInteger('assigned')->default(0);   // NEW assignments created
            $t->unsignedSmallInteger('emailed')->default(0);
            $t->boolean('notified')->default(true);             // was an email asked for
            $t->text('note')->nullable();

            $t->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_package_sends');
    }
};
