<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-person choice of which reasons may phone them (2026-09-29,
 * App\Support\ContactCategories). Rows keyed "call:<reason>" use this column;
 * null means "never chosen", which counts as yes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notification_prefs') && ! Schema::hasColumn('notification_prefs', 'voice')) {
            Schema::table('notification_prefs', function (Blueprint $t) {
                $t->boolean('voice')->nullable()->after('sms');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('notification_prefs', 'voice')) {
            Schema::table('notification_prefs', fn (Blueprint $t) => $t->dropColumn('voice'));
        }
    }
};
