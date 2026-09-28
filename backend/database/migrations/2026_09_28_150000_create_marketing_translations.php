<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automatic translations for the marketing site (2026-09-28).
 *
 * The site ships a hand-checked dictionary per language (/i18n/fr.json, /i18n/es.json).
 * Anything added later — a new blog post, a changed heading, a Website-settings edit — is
 * reported by the page the first time a French or Spanish visitor sees it, translated once
 * here, and served from this table from then on.
 *
 *   status 'auto'   machine translation, live, listed for review in the portal
 *   status 'edited' corrected by a person in Website → Translations; never overwritten
 *
 * `k` is the dictionary key the page looks the text up by; `k_hash` (sha1) is what the
 * unique index can hold, since keys can be long.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_translations')) {
            Schema::create('marketing_translations', function (Blueprint $t) {
                $t->charset = 'utf8mb4';
                $t->collation = 'utf8mb4_unicode_ci';
                $t->id();
                $t->string('lang', 5);
                $t->char('k_hash', 40);
                $t->mediumText('k');
                $t->mediumText('en');
                $t->mediumText('text');
                $t->string('status', 10)->default('auto');
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();
                $t->unique(['lang', 'k_hash']);
                $t->index(['lang', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_translations');
    }
};
