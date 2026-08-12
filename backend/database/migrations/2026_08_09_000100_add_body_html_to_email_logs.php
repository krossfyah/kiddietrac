<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store the rendered HTML of each outbound email so the Email Log can show a
 * true preview of what was actually sent. Capped in code before insert.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_logs') && ! Schema::hasColumn('email_logs', 'body_html')) {
            Schema::table('email_logs', function (Blueprint $table) {
                $table->longText('body_html')->nullable()->after('error');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('email_logs', 'body_html')) {
            Schema::table('email_logs', function (Blueprint $table) {
                $table->dropColumn('body_html');
            });
        }
    }
};
