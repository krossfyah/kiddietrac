<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which delivery failures have already been recorded.
 *
 * The bounce ingester only ever read the maildir's `new/` directory and moved each
 * file to `cur/` when it was done — so "done" meant "in cur". But the mail server
 * moves a message to `cur/` on its own the moment anything reads the mailbox, which
 * webmail and Dovecot's indexer both do. Any bounce that had been looked at was
 * therefore skipped forever: ten real "Mail delivery failed" notices were sitting in
 * cur/ unrecorded, and email_logs had never held a single 'bounced' row.
 *
 * Reading cur/ as well fixes that, but location can no longer mean "processed", so
 * it is written down here instead. A fingerprint rather than a filename: maildir
 * names change as flags are added, and the same failure can be delivered twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('processed_bounces')) {
            return;
        }

        Schema::create('processed_bounces', function (Blueprint $table) {
            $table->id();
            // recipient + failure time + status code + a digest of the raw notice
            $table->string('fingerprint', 64)->unique();
            $table->string('recipient', 200)->nullable();
            $table->string('source_file', 255)->nullable();
            $table->boolean('matched')->default(false);
            $table->timestamp('processed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_bounces');
    }
};
