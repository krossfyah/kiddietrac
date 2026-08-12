<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email Client — per-user connected mail accounts. Backs the Outlook-style
 * account setup wizard: multiple accounts per user, each with its own signature
 * and out-of-office (auto-reply) settings. Secrets are stored Crypt-encrypted in
 * `secret` and never returned to the client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();      // owner
            $table->unsignedBigInteger('agency_id')->nullable()->index();
            $table->string('display_name', 120)->nullable();      // "Front Office"
            $table->string('email_address', 190);
            $table->string('provider', 20)->default('imap');      // microsoft|google|imap|other
            // IMAP/SMTP server settings (Microsoft/Google prefill these).
            $table->string('imap_host', 190)->nullable();
            $table->unsignedSmallInteger('imap_port')->nullable();
            $table->string('imap_encryption', 10)->nullable();    // ssl|tls|none
            $table->string('smtp_host', 190)->nullable();
            $table->unsignedSmallInteger('smtp_port')->nullable();
            $table->string('smtp_encryption', 10)->nullable();
            $table->string('username', 190)->nullable();
            $table->text('secret')->nullable();                   // Crypt-encrypted password/token
            $table->boolean('is_default')->default(false);
            $table->string('status', 20)->default('pending');     // pending|connected|error
            $table->string('status_detail', 255)->nullable();
            // Signature (HTML) shown/appended when composing from this account.
            $table->text('signature_html')->nullable();
            $table->boolean('signature_enabled')->default(true);
            // Out-of-office / auto-reply.
            $table->boolean('ooo_enabled')->default(false);
            $table->string('ooo_subject', 190)->nullable();
            $table->text('ooo_message')->nullable();
            $table->timestamp('ooo_start')->nullable();
            $table->timestamp('ooo_end')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_accounts');
    }
};
