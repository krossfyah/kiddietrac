<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/* 2026-10-13 (Anthony):
   - "add country field to add family and other new user flows" → families.country (ISO: CA / US)
   - "add ability to add secondary phone number for primary and secondary guardian such as work
     or home (let user choose type)" + "if its a work phone number add ext as optional"
     → users.phone2, users.phone2_type (mobile/home/work/other), users.phone2_ext */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('families', 'country')) {
            Schema::table('families', function (Blueprint $t) { $t->string('country', 2)->nullable(); });
            // Existing families take their agency's country; everyone so far is Canadian
            // except an agency that says it is in the US.
            DB::statement("UPDATE families f
                JOIN centres c ON c.id = f.centre_id
                JOIN agencies a ON a.id = c.agency_id
                SET f.country = CASE WHEN UPPER(COALESCE(a.country,'')) IN ('US','USA','UNITED STATES','UNITED STATES OF AMERICA') THEN 'US' ELSE 'CA' END
                WHERE f.country IS NULL");
            DB::table('families')->whereNull('country')->update(['country' => 'CA']);
        }
        Schema::table('users', function (Blueprint $t) {
            if (! Schema::hasColumn('users', 'phone2')) $t->string('phone2', 40)->nullable();
            if (! Schema::hasColumn('users', 'phone2_type')) $t->string('phone2_type', 12)->nullable();
            if (! Schema::hasColumn('users', 'phone2_ext')) $t->string('phone2_ext', 10)->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('families', 'country')) Schema::table('families', fn (Blueprint $t) => $t->dropColumn('country'));
        foreach (['phone2', 'phone2_type', 'phone2_ext'] as $c) {
            if (Schema::hasColumn('users', $c)) Schema::table('users', fn (Blueprint $t) => $t->dropColumn($c));
        }
    }
};
