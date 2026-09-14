<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * users.must_change_password — "this password was issued to you, not chosen by you".
 *
 * An administrator resetting somebody's password mints a random temporary one and
 * emails it. Until 2026-09-14 that was the end of it: the person signed in with the
 * temporary password and could carry on using it indefinitely, which means a password
 * that travelled through an inbox in plain text stays live on the account.
 *
 * The flag closes that. It is set when a temporary password is issued and cleared the
 * moment the person chooses their own, by any route — change-password, a reset link, or
 * an invite link. EnsurePasswordChanged turns it into a gate: while it is set, the API
 * answers 403 to everything except the endpoints needed to set a new password.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'must_change_password')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            // Default 0 so every existing account is unaffected — nobody is locked
            // out by the migration itself.
            $table->boolean('must_change_password')->default(false)->after('password');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'must_change_password')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });
    }
};
