<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agency waitlist rows can now point at a REAL family and child (ticket #251, 2026-11-24).
 * Families from a closed provider wait here and are placed with a new centre without a
 * second family being created. All columns nullable: leads and partner-synced rows have none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_waitlist', function (Blueprint $t) {
            if (! Schema::hasColumn('external_waitlist', 'family_id')) {
                $t->unsignedBigInteger('family_id')->nullable()->after('agency_id');
                $t->unsignedBigInteger('child_id')->nullable()->after('family_id');
                $t->unsignedBigInteger('from_centre_id')->nullable()->after('child_id');
                $t->unsignedBigInteger('assigned_centre_id')->nullable()->after('from_centre_id');
                $t->timestamp('assigned_at')->nullable()->after('assigned_centre_id');
                $t->unsignedBigInteger('assigned_by_id')->nullable()->after('assigned_at');
                $t->index('family_id', 'ext_wl_family_idx');
                $t->index('child_id', 'ext_wl_child_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('external_waitlist', function (Blueprint $t) {
            if (Schema::hasColumn('external_waitlist', 'family_id')) {
                $t->dropIndex('ext_wl_family_idx');
                $t->dropIndex('ext_wl_child_idx');
                $t->dropColumn(['family_id', 'child_id', 'from_centre_id', 'assigned_centre_id', 'assigned_at', 'assigned_by_id']);
            }
        });
    }
};
