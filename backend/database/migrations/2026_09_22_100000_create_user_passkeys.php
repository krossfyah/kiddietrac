<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PASSKEYS (2026-09-22)
 *
 * A passkey belongs to a USER, not to an email address. That is not a style choice here:
 * eight live accounts share three email addresses, so anything keyed on an address would
 * be ambiguous the day it shipped. The credential itself names the account, which is why
 * sign-in can be usernameless — nobody types anything at all.
 *
 * NOT A REPLACEMENT FOR THE PASSWORD. Anthony, 2026-09-22: passkeys are an additional way
 * in, the password stays as the recovery path, and everyone still rotates at 90 days. A
 * row here therefore grants nothing on its own; it is one more door, not a different
 * building.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_passkeys')) {
            return;
        }

        Schema::create('user_passkeys', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->index();

            /* base64url, not raw bytes. It is looked up by equality on every sign-in and
               shown in the portal, and a binary column on a latin1-era schema is how you
               get a lookup that works locally and fails on the host. Unique because two
               users holding one credential id is not a state that can be resolved later:
               the assertion would be ambiguous. */
            $t->string('credential_id', 512)->unique();

            /* PEM, as the library hands it back. Public by definition — this column is
               not a secret and does not need encrypting; the private half never leaves
               the authenticator. */
            $t->text('public_key');

            /* CLONE DETECTION. An authenticator increments this on every assertion; a
               counter that goes BACKWARDS means two copies of one credential exist. Not
               every authenticator implements it (many platform ones always send 0), so a
               zero is normal and only a genuine decrease is a signal. */
            $t->unsignedBigInteger('sign_count')->default(0);

            $t->string('aaguid', 64)->nullable();
            $t->string('transports', 120)->nullable();

            /* Does this passkey SYNC (iCloud Keychain, Google Password Manager, 1Password)
               or is it bound to one device? It decides what losing the phone means - a
               synced key survives it, a device-bound one does not. */
            $t->boolean('is_synced')->default(false);

            /* What the person calls it. Defaulted from the browser/device at enrolment
               because "Passkey 1" is useless the moment somebody has two. */
            $t->string('label', 80);

            $t->timestamp('last_used_at')->nullable();
            $t->string('last_used_ip', 45)->nullable();
            $t->string('created_ua', 255)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_passkeys');
    }
};
