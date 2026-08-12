<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-room email delivery switch (2026-08-05).
 *
 * Part of the hierarchical email gate: agency master (agencies.settings
 * notifications_enabled) → centre (centres.settings email_enabled) → room.
 * A room whose email is OFF suppresses mail to the parents of children enrolled
 * in it, so an agency can be pre-boarded and switched on one room at a time.
 *
 * Default TRUE — existing live rooms keep sending; you opt a room OUT.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('rooms', 'email_enabled')) {
            Schema::table('rooms', function (Blueprint $t) {
                $t->boolean('email_enabled')->default(true)->after('active');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('rooms', 'email_enabled')) {
            Schema::table('rooms', function (Blueprint $t) {
                $t->dropColumn('email_enabled');
            });
        }
    }
};
