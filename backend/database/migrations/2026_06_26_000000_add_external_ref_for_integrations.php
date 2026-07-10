<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Integration sync support: a stable `external_id` (the source system's own
 * primary key) + `external_source` (which system, e.g. "ilearn") on the core
 * entities, so ANY external agency platform can push idempotent upserts without
 * creating duplicates. Indexed for fast lookups; nullable so existing rows and
 * native KiddieTrac records are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['centres', 'families', 'children'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (! Schema::hasColumn($table, 'external_id')) {
                    $t->string('external_id')->nullable()->after('id');
                }
                if (! Schema::hasColumn($table, 'external_source')) {
                    $t->string('external_source')->nullable()->after('external_id');
                }
                $t->index(['external_source', 'external_id'], $table.'_external_idx');
            });
        }
    }

    public function down(): void
    {
        foreach (['centres', 'families', 'children'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropIndex($table.'_external_idx');
                $t->dropColumn(['external_id', 'external_source']);
            });
        }
    }
};
