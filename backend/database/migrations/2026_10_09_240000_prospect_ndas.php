<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Our NDA sent to a PROSPECT — somebody who is not a customer and has no account
   (2026-10-09). Signed from a private link; nothing is created in any agency. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('prospect_ndas')) return;
        Schema::create('prospect_ndas', function (Blueprint $t) {
            $t->id();
            $t->string('token', 64)->unique();
            $t->unsignedBigInteger('sent_by_id')->nullable();
            $t->unsignedBigInteger('lead_id')->nullable()->index();
            $t->string('name', 160);
            $t->string('email', 190)->index();
            $t->string('company', 190)->nullable();
            $t->string('status', 20)->default('sent');          // sent | signed | cancelled
            $t->string('agreement_version', 20);
            $t->dateTime('sent_at')->nullable();
            $t->dateTime('expires_at')->nullable();
            $t->dateTime('opened_at')->nullable();
            $t->dateTime('signed_at')->nullable();
            $t->string('signer_name', 160)->nullable();
            $t->string('role_title', 160)->nullable();
            $t->string('business_name', 255)->nullable();
            $t->text('business_address')->nullable();
            $t->string('document_hash', 64)->nullable();
            $t->string('pdf_path', 255)->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->string('user_agent', 300)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prospect_ndas');
    }
};
