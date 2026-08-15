<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who has been told about which version of the legal terms.
 *
 * A platform-wide mailing will part-fail — a bad address, a mail server that stops
 * answering halfway through 90 sends — and the obvious response is to run it again. Without
 * this the second run mails everyone who already got it, which is both a nuisance and the
 * fastest way to teach people that a notice from us is noise.
 *
 * It is also the record of who was told, and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_notice_sends', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('version', 20);
            $table->timestamp('sent_at')->nullable();

            // One row per person per version, enforced here and not just in the command:
            // two overlapping runs would otherwise each pass their "already told?" check
            // before either had written anything.
            $table->unique(['user_id', 'version'], 'legal_notice_user_version_uniq');
            $table->index('version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_notice_sends');
    }
};
