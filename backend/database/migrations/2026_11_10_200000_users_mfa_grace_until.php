<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* When a platform admin's or reseller's 14 days to set up two-factor end (App\Support\StrongSignIn, 2026-11-10). */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'mfa_grace_until')) {
            Schema::table('users', function (Blueprint $t) {
                $t->timestamp('mfa_grace_until')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'mfa_grace_until')) {
            Schema::table('users', function (Blueprint $t) { $t->dropColumn('mfa_grace_until'); });
        }
    }
};
