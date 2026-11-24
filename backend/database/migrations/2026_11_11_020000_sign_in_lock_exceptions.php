<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sign-in lock exceptions (2026-11-10): one person let in despite ONE centre / provider lock, while the centre stays
 * locked for everyone else. Ended by hand (they are locked again) or when the lock is lifted. App\Support\SignInLocks.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sign_in_lock_exceptions')) return;
        Schema::create('sign_in_lock_exceptions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id');
            $t->unsignedBigInteger('lock_id');
            $t->unsignedBigInteger('user_id');
            $t->text('notes')->nullable();
            $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->text('end_notes')->nullable();
            $t->unsignedBigInteger('ended_by_user_id')->nullable();
            $t->timestamp('ended_at')->nullable();
            $t->index(['agency_id', 'ended_at']);
            $t->index(['lock_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sign_in_lock_exceptions');
    }
};
