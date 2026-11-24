<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/* Outgoing messages in each person's language (2026-10-22): one row per translated piece
   of text, so the same wording is never translated twice. App\Support\MessageTranslator.
   (Not message_translations: that table belongs to the AI chat translation feature.) */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('outgoing_translations')) {
            return;
        }
        Schema::create('outgoing_translations', function (Blueprint $t) {
            $t->id();
            $t->char('hash', 40)->unique();       // sha1(locale . "\0" . source)
            $t->char('locale', 2)->index();
            $t->mediumText('source');
            $t->mediumText('translated');
            $t->timestamp('created_at')->nullable();
        });
        DB::statement('ALTER TABLE outgoing_translations CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
    }

    public function down(): void
    {
        Schema::dropIfExists('outgoing_translations');
    }
};
