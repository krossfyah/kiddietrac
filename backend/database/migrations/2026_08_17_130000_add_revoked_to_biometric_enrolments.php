<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When biometric unlock was switched back OFF.
 *
 * Without it the enrolment table only ever grows, and a report built from it lists unlock
 * methods that were removed months ago as though they were live — which is worse than no
 * report, because somebody would act on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('biometric_enrolments', function (Blueprint $table) {
            $table->timestamp('revoked_at')->nullable()->after('enrolled_at');
            $table->index('revoked_at');
        });
    }

    public function down(): void
    {
        Schema::table('biometric_enrolments', function (Blueprint $table) {
            $table->dropIndex(['revoked_at']);
            $table->dropColumn('revoked_at');
        });
    }
};
