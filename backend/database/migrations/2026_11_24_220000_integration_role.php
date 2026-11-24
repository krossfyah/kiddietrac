<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/* A silent service role for integration tokens (2026-11-24, Anthony: "ilearn integration user should be a silent user ...
   for integration purposes only and should not show up as a true user"). It held agency_admin, so every admin list,
   bell, email and "who is on" view counted it as a person. 'integration' passes only the /integration API
   (routes/api.php) and no people role list names it. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE role_assignments MODIFY role ENUM('agency_admin','centre_director','educator','guardian','auditor','platform_admin','home_visitor','sales_rep','reseller_admin','integration') NOT NULL");
        DB::table('role_assignments')->where('role', 'agency_admin')
            ->whereIn('user_id', DB::table('users')->where('email', 'like', 'integration+%')->pluck('id'))
            ->update(['role' => 'integration']);
    }

    public function down(): void
    {
        DB::table('role_assignments')->where('role', 'integration')->update(['role' => 'agency_admin']);
        DB::statement("ALTER TABLE role_assignments MODIFY role ENUM('agency_admin','centre_director','educator','guardian','auditor','platform_admin','home_visitor','sales_rep','reseller_admin') NOT NULL");
    }
};
