<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* REPLY TO ONE MESSAGE (2026-11-01). The message a reply quotes, in the same table and the same
   conversation (App\Support\ChatReply keeps it there). Nullable: an ordinary message quotes nothing. */
return new class extends Migration
{
    private const TABLES = ['messages', 'staff_messages', 'web_chat_messages'];

    public function up(): void
    {
        foreach (self::TABLES as $t) {
            if (Schema::hasTable($t) && ! Schema::hasColumn($t, 'reply_to_id')) {
                Schema::table($t, function (Blueprint $table) {
                    $table->unsignedBigInteger('reply_to_id')->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $t) {
            if (Schema::hasTable($t) && Schema::hasColumn($t, 'reply_to_id')) {
                Schema::table($t, function (Blueprint $table) {
                    $table->dropColumn('reply_to_id');
                });
            }
        }
    }
};
