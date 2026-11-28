<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/* CUSTOM ROLES CAN BE GIVEN TO PEOPLE (2026-11-28, Anthony: "custom roles yes build it out").
   role_assignments.custom_role_id points at the agency's `roles` row. The `role` column is NOT touched: it keeps the
   BASE built-in role, so every existing assignment stays exactly as it is (custom_role_id NULL = the built-in role)
   and every data-scope rule keeps reading the base role. App\Support\CustomRoles narrows what a holder may do.
   RESTRICT on delete: a role in use can never be removed from under its holders (the screen archives, and refuses
   while anyone holds it). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('role_assignments', 'custom_role_id')) {
            Schema::table('role_assignments', function (Blueprint $t) {
                $t->unsignedBigInteger('custom_role_id')->nullable()->after('role');
                $t->index('custom_role_id', 'ra_custom_role_idx');
                $t->foreign('custom_role_id', 'ra_custom_role_fk')->references('id')->on('roles')->restrictOnDelete();
            });
        }
        // Custom roles always name their base now (none existed when this ran; a base-less one would be unusable).
        DB::table('roles')->where('is_system', false)->whereNull('base_role')->whereNull('deleted_at')
            ->update(['base_role' => 'educator', 'updated_at' => now()]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('role_assignments', 'custom_role_id')) {
            Schema::table('role_assignments', function (Blueprint $t) {
                $t->dropForeign('ra_custom_role_fk');
                $t->dropIndex('ra_custom_role_idx');
                $t->dropColumn('custom_role_id');
            });
        }
    }
};
