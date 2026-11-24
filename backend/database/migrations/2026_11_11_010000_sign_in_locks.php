<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sign-in locks (2026-11-10): pause sign-in for one person, a centre / home provider, or a whole agency, and lift it
 * later. See App\Support\SignInLocks. Nothing on the person, family or child is changed by a lock.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sign_in_locks')) {
            Schema::create('sign_in_locks', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->enum('scope', ['user', 'centre', 'agency']);
                $t->unsignedBigInteger('target_id');
                $t->text('lock_notes')->nullable();
                $t->unsignedBigInteger('locked_by_user_id')->nullable();
                $t->timestamp('locked_at')->useCurrent();
                $t->text('unlock_notes')->nullable();
                $t->unsignedBigInteger('unlocked_by_user_id')->nullable();
                $t->timestamp('unlocked_at')->nullable();
                $t->unsignedInteger('held_count')->default(0);
                $t->unsignedInteger('restored_count')->nullable();
                $t->index(['agency_id', 'unlocked_at']);
                $t->index(['scope', 'target_id']);
            });
        }
        // Who each lock held when it was made: the unlock report reads it. Access is never decided from this table.
        if (! Schema::hasTable('sign_in_lock_people')) {
            Schema::create('sign_in_lock_people', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('lock_id');
                $t->unsignedBigInteger('user_id');
                $t->string('roles', 120)->nullable();
                $t->boolean('already_locked')->default(false);
                $t->timestamp('created_at')->useCurrent();
                $t->unique(['lock_id', 'user_id']);
                $t->index('user_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sign_in_lock_people');
        Schema::dropIfExists('sign_in_locks');
    }
};
