<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v22p7 — add two_factor_recovery_codes column to users.
 *
 * two_factor_secret + two_factor_enabled already exist on users; only
 * the recovery codes column is missing.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'two_factor_recovery_codes')) {
                $table->json('two_factor_recovery_codes')->nullable()->after('two_factor_enabled');
            }
        });
        // Pre-existing two_factor_secret column was varchar(255) which is too
        // short for Laravel's encrypt() output (~250+ chars). Widen to TEXT.
        // On the prod server this was applied via a one-shot ALTER prior to
        // adding it here; this branch makes fresh installs match.
        \Illuminate\Support\Facades\DB::statement('ALTER TABLE users MODIFY two_factor_secret TEXT NULL');
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('two_factor_recovery_codes');
        });
    }
};
