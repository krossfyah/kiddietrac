<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Saved signatures and initials (2026-11-06) - App\Http\Controllers\Api\SavedSignatureController.
 *
 * user_signatures      one row per time a person saved one; the active one has revoked_at NULL.
 *                      The image is sealed at rest (App\Support\Sensitive). Kept after removal: it
 *                      is the evidence for the documents it was applied to.
 * user_signature_uses  every time a saved one was applied, with the words agreed to, IP and device.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_signatures')) {
            Schema::create('user_signatures', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id')->index();
                $t->string('kind', 12);                       // signature | initials
                $t->mediumText('image');                      // ktenc:v1:... of a PNG data URL
                $t->string('image_hash', 64);                 // sha256 of the plain data URL
                $t->text('authorization_text');
                $t->timestamp('authorized_at');
                $t->string('authorized_ip', 45)->nullable();
                $t->string('authorized_ua', 255)->nullable();
                $t->timestamp('revoked_at')->nullable();
                $t->timestamps();
                $t->index(['user_id', 'kind', 'revoked_at']);
            });
        }
        if (! Schema::hasTable('user_signature_uses')) {
            Schema::create('user_signature_uses', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id')->index();
                $t->unsignedBigInteger('user_signature_id')->index();
                $t->string('kind', 12);
                $t->string('context', 120)->nullable();       // e.g. managed_form:12, incident_ack:40
                $t->text('consent_text');
                $t->string('ip', 45)->nullable();
                $t->string('ua', 255)->nullable();
                $t->timestamp('used_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_signature_uses');
        Schema::dropIfExists('user_signatures');
    }
};
