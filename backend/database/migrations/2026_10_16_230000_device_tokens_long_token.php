<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Windows (Edge/Chrome → WNS) web-push subscriptions are longer than 500 characters once
 * the keys are included. PushController cut them to 500 — invalid JSON that could never be
 * pushed to — and the insert then failed anyway (2026-10-13..15, user 851, 7 attempts).
 * Tokens are ASCII (endpoints + base64url keys + FCM tokens), so ASCII keeps the UNIQUE
 * index inside InnoDB's 3072-byte limit at 2048 characters. ascii_bin: tokens are
 * case-sensitive; utf8mb4_general_ci could treat two different tokens as equal.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE device_tokens MODIFY token VARCHAR(2048) CHARACTER SET ascii COLLATE ascii_bin NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE device_tokens MODIFY token VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL');
    }
};
