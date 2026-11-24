<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * RESELLERS (2026-10-21). Anthony: a Reseller owns several agencies and sets ONE brand
 * (logos, colours, app name, support email, splash, favicon, hide-KiddieTrac) from its own
 * panel; an agency may override it only when the reseller allows. Brand resolution is
 * reseller -> agency override -> KiddieTrac default (App\Support\Brand).
 *
 *   resellers.brand            JSON: app_name, logo_square_url, logo_wordmark_url,
 *                              splash_logo_url, favicon_url, primary_color, accent_color,
 *                              splash_bg, support_email
 *   resellers.hide_kiddietrac  every agency under this reseller is white-labelled
 *   resellers.allow_agency_override  agencies may set their own logo/colours over it
 *   agencies.reseller_id       NULL = sold directly by KiddieTrac (unchanged behaviour)
 *   role_assignments.reseller_id + role 'reseller_admin'  a person who manages ONE reseller
 *                              (agency_id and centre_id stay NULL: a reseller admin is not a
 *                              member of any agency and reads none of their data)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('resellers')) {
            Schema::create('resellers', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('name', 160);
                $t->string('slug', 80)->unique();
                $t->longText('brand')->nullable();
                $t->boolean('hide_kiddietrac')->default(true);
                $t->boolean('allow_agency_override')->default(false);
                $t->string('status', 20)->default('active');
                $t->timestamps();
            });
        }
        if (! Schema::hasColumn('agencies', 'reseller_id')) {
            Schema::table('agencies', function (Blueprint $t) {
                $t->unsignedBigInteger('reseller_id')->nullable()->after('id');
                $t->index('reseller_id');
            });
        }
        if (! Schema::hasColumn('role_assignments', 'reseller_id')) {
            Schema::table('role_assignments', function (Blueprint $t) {
                $t->unsignedBigInteger('reseller_id')->nullable()->after('centre_id');
                $t->index('reseller_id');
            });
        }
        DB::statement("ALTER TABLE role_assignments MODIFY role ENUM('agency_admin','centre_director','educator','guardian','auditor','platform_admin','home_visitor','sales_rep','reseller_admin') NOT NULL");
    }

    public function down(): void
    {
        DB::table('role_assignments')->where('role', 'reseller_admin')->delete();
        DB::statement("ALTER TABLE role_assignments MODIFY role ENUM('agency_admin','centre_director','educator','guardian','auditor','platform_admin','home_visitor','sales_rep') NOT NULL");
        if (Schema::hasColumn('role_assignments', 'reseller_id')) {
            Schema::table('role_assignments', function (Blueprint $t) { $t->dropIndex(['reseller_id']); $t->dropColumn('reseller_id'); });
        }
        if (Schema::hasColumn('agencies', 'reseller_id')) {
            Schema::table('agencies', function (Blueprint $t) { $t->dropIndex(['reseller_id']); $t->dropColumn('reseller_id'); });
        }
        Schema::dropIfExists('resellers');
    }
};
