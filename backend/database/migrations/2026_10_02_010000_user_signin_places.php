<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* The devices and areas each account has signed in from (2026-10-01), so a sign-in
   from somewhere new can be told to its owner. See App\Support\SignInAlert.
   kind = 'device' ("Chrome on Android") or 'region' ("CA-Ontario"). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_signin_places')) {
            return;
        }
        Schema::create('user_signin_places', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->index();
            $t->string('kind', 10);
            $t->string('place_key', 120);
            $t->string('label', 190)->nullable();
            $t->timestamp('first_seen_at')->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->unique(['user_id', 'kind', 'place_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_signin_places');
    }
};
