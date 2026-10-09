<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * NOT ON THE STAFF ROTA (2026-10-09).
 *
 * Safia Ali's educator account is in every iLearn room so she can supervise, and the
 * nightly rota auto-fill scheduled her as staff at centres whose provider name did not
 * match an account — so she showed as an expected educator and was told to clock in.
 * Anthony chose: keep her in the rooms, keep her off the rota. A flag on the person.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'rota_exempt')) {
            Schema::table('users', function (Blueprint $t) {
                $t->boolean('rota_exempt')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'rota_exempt')) {
            Schema::table('users', function (Blueprint $t) {
                $t->dropColumn('rota_exempt');
            });
        }
    }
};
