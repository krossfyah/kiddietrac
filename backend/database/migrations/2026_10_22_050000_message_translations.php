<?php

use Illuminate\Database\Migrations\Migration;

/* NO-OP (2026-10-22). This was meant to create the cache for outgoing-message
   translations, but `message_translations` already belongs to the AI chat translation
   feature (AiV2Controller: message_id, source_lang, target_lang, translated_text), so it
   created nothing — and its down() would have dropped that table. The cache lives in
   `outgoing_translations` (2026_10_22_060000). Kept so the migrations table stays true. */
return new class extends Migration
{
    public function up(): void
    {
    }

    public function down(): void
    {
    }
};
