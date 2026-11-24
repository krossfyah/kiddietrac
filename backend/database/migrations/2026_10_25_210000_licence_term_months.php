<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A licence's CONTRACT TERM in months (2026-10-25): 12 by default for a yearly licence, longer when a
 * contract says so (24, 36…). Renewals advance by the term; billing frequency stays `interval`.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('agency_licences', 'term_months')) {
            Schema::table('agency_licences', function (Blueprint $t) {
                $t->unsignedSmallInteger('term_months')->nullable()->after('interval');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('agency_licences', 'term_months')) {
            Schema::table('agency_licences', fn (Blueprint $t) => $t->dropColumn('term_months'));
        }
    }
};
