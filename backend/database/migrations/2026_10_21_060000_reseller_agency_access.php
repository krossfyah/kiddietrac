<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * RESELLER = FULL AGENCY ADMIN OF ITS OWN AGENCIES (2026-10-21).
 *
 * A Reseller person holds a real agency_admin role_assignment for every agency whose
 * agencies.reseller_id is their reseller - granted and revoked centrally by
 * App\Support\ResellerAccess::sync(). granted_by_reseller_id marks those rows, so a
 * grant is never confused with a genuine agency appointment and is withdrawn the moment
 * the agency leaves the reseller (or the person leaves it).
 *
 * resellers.commission_pct / commission_flat_cents: what the reseller earns on what
 * KiddieTrac bills its agencies (platform admin sets them).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('role_assignments', 'granted_by_reseller_id')) {
            Schema::table('role_assignments', function (Blueprint $t) {
                $t->unsignedBigInteger('granted_by_reseller_id')->nullable()->after('reseller_id');
                $t->index('granted_by_reseller_id');
            });
        }
        Schema::table('resellers', function (Blueprint $t) {
            if (! Schema::hasColumn('resellers', 'commission_pct')) {
                $t->decimal('commission_pct', 5, 2)->default(0)->after('allow_agency_override');
            }
            if (! Schema::hasColumn('resellers', 'commission_flat_cents')) {
                $t->integer('commission_flat_cents')->default(0)->after('commission_pct');
            }
        });
    }

    public function down(): void
    {
        \Illuminate\Support\Facades\DB::table('role_assignments')->whereNotNull('granted_by_reseller_id')->delete();
        if (Schema::hasColumn('role_assignments', 'granted_by_reseller_id')) {
            Schema::table('role_assignments', function (Blueprint $t) { $t->dropIndex(['granted_by_reseller_id']); $t->dropColumn('granted_by_reseller_id'); });
        }
        Schema::table('resellers', function (Blueprint $t) {
            foreach (['commission_pct', 'commission_flat_cents'] as $c) {
                if (Schema::hasColumn('resellers', $c)) { $t->dropColumn($c); }
            }
        });
    }
};
