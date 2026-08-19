<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * People who signed up on the public website.
 *
 * Unsubscribes were a flat list of email addresses in a JSON file — no date, no source, no
 * way to tell a person who left last year from one who left this morning. That is not
 * enough to answer "when did they unsubscribe", which is exactly what anyone asks, and in
 * several jurisdictions it is what you are required to be able to show.
 *
 * A subscriber is never deleted on unsubscribe: the row stays and gains a timestamp, so the
 * record of consent being withdrawn survives. The file-based suppression list is kept in
 * step alongside it, because the mail layer already reads that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('email', 190)->unique();
            $table->string('name', 120)->nullable();
            $table->string('agency_name', 160)->nullable();
            $table->string('source', 60)->nullable();
            $table->string('ip', 45)->nullable();

            $table->timestamp('subscribed_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable()->index();
            // Who ended it: the person themselves via the emailed link, or an
            // administrator acting on a request made some other way.
            $table->string('unsubscribed_by', 20)->nullable();
            $table->string('unsubscribe_note', 190)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_subscribers');
    }
};
