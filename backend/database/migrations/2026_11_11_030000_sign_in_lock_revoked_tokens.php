<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sessions a sign-in lock ended (2026-11-10, Anthony: "Sign out now + stop push"). Locking signs the person out of
 * every device; this keeps only the SHA-256 that personal_access_tokens already held, so a screen still open with that
 * session is answered "your access is paused" (423) rather than "session expired". Pruned after 30 days.
 * App\Support\SignInLocks::signOut / revokedAnswer.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sign_in_lock_revoked_tokens')) return;
        Schema::create('sign_in_lock_revoked_tokens', function (Blueprint $t) {
            $t->id();
            $t->char('token_hash', 64)->unique();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('lock_id');
            $t->timestamp('created_at')->useCurrent();
            $t->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sign_in_lock_revoked_tokens');
    }
};
