<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_logs') && ! Schema::hasColumn('email_logs', 'agency_id')) {
            Schema::table('email_logs', function (Blueprint $t) {
                $t->unsignedBigInteger('agency_id')->nullable()->index()->after('id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('email_logs') && Schema::hasColumn('email_logs', 'agency_id')) {
            Schema::table('email_logs', function (Blueprint $t) {
                $t->dropColumn('agency_id');
            });
        }
    }
};
