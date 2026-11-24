<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The words on every screen for the global search, learned from use (2026-11-09, App\Support\SearchKeywords).
 * search_keywords: the phrases that passed the gates (source crawl = the 2026-11-09 seed, seen = learned from use).
 * search_keyword_screens: when each role's screen was last reported (retiring words, "screens without words").
 * search_keyword_days: counts only, per day and role. A rejected phrase is never stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('search_keywords')) {
            Schema::create('search_keywords', function (Blueprint $t) {
                $t->id();
                $t->string('role', 30);
                $t->string('hash', 60);
                $t->string('phrase', 64);
                $t->string('tab', 64)->default('');
                $t->string('source', 10)->default('seen');
                $t->timestamp('first_seen_at')->nullable();
                $t->timestamp('last_seen_at')->nullable();
                $t->unsignedInteger('seen_count')->default(1);
                $t->timestamp('retired_at')->nullable();
                $t->unique(['role', 'hash', 'phrase']);
                $t->index(['role', 'retired_at']);
            });
        }
        if (! Schema::hasTable('search_keyword_screens')) {
            Schema::create('search_keyword_screens', function (Blueprint $t) {
                $t->id();
                $t->string('role', 30);
                $t->string('hash', 60);
                $t->timestamp('first_report_at')->nullable();
                $t->timestamp('last_report_at')->nullable();
                $t->unsignedInteger('reports')->default(0);
                $t->unique(['role', 'hash']);
            });
        }
        if (! Schema::hasTable('search_keyword_days')) {
            Schema::create('search_keyword_days', function (Blueprint $t) {
                $t->id();
                $t->date('day');
                $t->string('role', 30);
                foreach (['reports', 'accepted', 'known', 'rej_rule', 'rej_source', 'rej_name'] as $c) {
                    $t->unsignedInteger($c)->default(0);
                }
                $t->unique(['day', 'role']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('search_keyword_days');
        Schema::dropIfExists('search_keyword_screens');
        Schema::dropIfExists('search_keywords');
    }
};
