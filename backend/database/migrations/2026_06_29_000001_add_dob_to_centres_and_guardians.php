<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v22p90 — Provider (centre) and parent (guardian) date of birth.
 * Pulled from iLearn by the integration sync (providers carry a DOB; some
 * parents do too). Nullable — many source records lack it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('centres') && ! Schema::hasColumn('centres', 'date_of_birth')) {
            Schema::table('centres', function (Blueprint $table) {
                $table->date('date_of_birth')->nullable()->after('email');
            });
        }
        if (Schema::hasTable('guardians') && ! Schema::hasColumn('guardians', 'date_of_birth')) {
            Schema::table('guardians', function (Blueprint $table) {
                $table->date('date_of_birth')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('centres', 'date_of_birth')) {
            Schema::table('centres', function (Blueprint $table) {
                $table->dropColumn('date_of_birth');
            });
        }
        if (Schema::hasColumn('guardians', 'date_of_birth')) {
            Schema::table('guardians', function (Blueprint $table) {
                $table->dropColumn('date_of_birth');
            });
        }
    }
};
