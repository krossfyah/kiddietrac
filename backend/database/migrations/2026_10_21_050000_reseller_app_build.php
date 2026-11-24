<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A reseller's own app (2026-10-21, white-label phase 5). JSON:
 *   application_id   the Play/App Store package, permanent once published
 *   version_code / version_name   optional per-brand version
 *   firebase_ready   google-services.json for that package is in place (push needs it)
 *   play_listing_url where the app is published, once it is
 *   status           draft | kit_downloaded | built | published
 *   kit_at / built_at timestamps the panel shows
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('resellers', 'app_build')) {
            Schema::table('resellers', function (Blueprint $t) {
                $t->longText('app_build')->nullable()->after('brand');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('resellers', 'app_build')) {
            Schema::table('resellers', function (Blueprint $t) { $t->dropColumn('app_build'); });
        }
    }
};
