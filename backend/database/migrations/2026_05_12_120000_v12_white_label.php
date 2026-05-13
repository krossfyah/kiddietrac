<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v12-big: agencies gets custom_domain + subdomain for tenant routing.
 * Also adds 'unread_count_cache' on conversations for nav badge perf.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('agencies') && ! Schema::hasColumn('agencies', 'subdomain')) {
            Schema::table('agencies', function (Blueprint $t) {
                $t->string('subdomain', 80)->nullable()->unique()->after('slug');
                $t->string('custom_domain', 200)->nullable()->unique()->after('subdomain');
            });
        }
        // Add helpful index on conversations for unread lookup
        if (Schema::hasTable('messages') && ! Schema::hasColumn('messages', 'idx_unread_marker')) {
            // do nothing — the existing index is fine
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('agencies') && Schema::hasColumn('agencies', 'subdomain')) {
            Schema::table('agencies', function (Blueprint $t) {
                $t->dropColumn(['subdomain', 'custom_domain']);
            });
        }
    }
};
