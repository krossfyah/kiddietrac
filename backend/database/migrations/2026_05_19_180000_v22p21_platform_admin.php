<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * v22p21 — Phase B: introduce platform_admin role for true cross-agency
 * SaaS management. agency_admin remains the standard tenant admin;
 * platform_admin is a superset that bypasses agency scoping entirely.
 */
return new class extends Migration {
    public function up(): void
    {
        // ALTER the role_assignments.role ENUM to include the new value.
        // doctrine/dbal isn't installed, so use a raw statement.
        DB::statement(
            "ALTER TABLE role_assignments MODIFY COLUMN role ".
            "ENUM('agency_admin','centre_director','educator','guardian','auditor','platform_admin') NOT NULL"
        );
    }

    public function down(): void
    {
        // First nuke any rows that use the new value so the enum can shrink.
        DB::table('role_assignments')->where('role', 'platform_admin')->delete();
        DB::statement(
            "ALTER TABLE role_assignments MODIFY COLUMN role ".
            "ENUM('agency_admin','centre_director','educator','guardian','auditor') NOT NULL"
        );
    }
};
