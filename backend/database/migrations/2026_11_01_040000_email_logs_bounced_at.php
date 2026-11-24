<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* When a bounce actually happened (2026-11-01, Anthony: "date and timestamp needs to show
   when the bounce happened"). created_at is when we SENT; a bounce can arrive hours later. */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('email_logs', 'bounced_at')) {
            Schema::table('email_logs', function (Blueprint $t) {
                $t->dateTime('bounced_at', 3)->nullable()->after('error');
                $t->index('bounced_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('email_logs', 'bounced_at')) {
            Schema::table('email_logs', function (Blueprint $t) {
                $t->dropIndex(['bounced_at']);
                $t->dropColumn('bounced_at');
            });
        }
    }
};
