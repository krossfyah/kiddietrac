<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Texts on the KiddieTrac line (2026-10-23): read marker for texts in, who sent a reply. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('sms_messages', function (Blueprint $t) {
            if (! Schema::hasColumn('sms_messages', 'read_at')) $t->timestamp('read_at')->nullable();
            if (! Schema::hasColumn('sms_messages', 'sent_by_user_id')) $t->unsignedBigInteger('sent_by_user_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sms_messages', function (Blueprint $t) {
            $t->dropColumn(['read_at', 'sent_by_user_id']);
        });
    }
};
