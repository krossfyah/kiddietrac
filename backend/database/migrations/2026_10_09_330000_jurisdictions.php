<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/* Where each agency operates (App\Support\Jurisdictions), and each centre's licence type.
   Every existing agency is Ontario, so they are backfilled CA-ON. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('agencies', 'jurisdiction')) {
            Schema::table('agencies', function (Blueprint $t) {
                $t->string('jurisdiction', 8)->nullable()->after('country');
            });
        }
        DB::table('agencies')->whereNull('jurisdiction')->update(['jurisdiction' => 'CA-ON']);
        if (! Schema::hasColumn('centres', 'license_type')) {
            Schema::table('centres', function (Blueprint $t) {
                $t->string('license_type', 32)->nullable()->after('license_capacity');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('agencies', 'jurisdiction')) Schema::table('agencies', fn (Blueprint $t) => $t->dropColumn('jurisdiction'));
        if (Schema::hasColumn('centres', 'license_type')) Schema::table('centres', fn (Blueprint $t) => $t->dropColumn('license_type'));
    }
};
