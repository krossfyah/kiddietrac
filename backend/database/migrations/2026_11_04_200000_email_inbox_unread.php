<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unread mail on the top bar (2026-11-04): each mailbox's inbox unread count, kept by App\Support\MailUnread so the
 * badge poll reads a column instead of asking the mail server. NULL = not counted yet.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('email_accounts', function (Blueprint $t) {
            if (! Schema::hasColumn('email_accounts', 'inbox_unread')) $t->unsignedInteger('inbox_unread')->nullable();
            if (! Schema::hasColumn('email_accounts', 'inbox_unread_at')) $t->dateTime('inbox_unread_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('email_accounts', function (Blueprint $t) {
            foreach (['inbox_unread', 'inbox_unread_at'] as $c) {
                if (Schema::hasColumn('email_accounts', $c)) $t->dropColumn($c);
            }
        });
    }
};
