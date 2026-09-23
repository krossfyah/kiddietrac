<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Presence (2026-08-24).
 *
 * The portal had nothing to base a presence indicator on: no last_seen_at, online_at,
 * is_online or last_active_at. Only last_login_at, which says whether someone signed in
 * this morning, not whether they are there now — a dot driven by it would be a lie.
 *
 * Indexed because presence is read for every row of every conversation list.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'last_seen_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable()->after('last_login_ip');
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'last_seen_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['last_seen_at']);
            $table->dropColumn('last_seen_at');
        });
    }
};
