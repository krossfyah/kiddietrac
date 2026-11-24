<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE EMAIL CLIENT STAYS SIGNED IN AND SAYS WHEN MAIL ARRIVES (2026-11-02, Anthony: "any new mail that comes in alert me
 * via the bell with a soft chime ... keep the connection alive without me signing in all the time").
 *
 *   mail_seen_until     - UTC watermark of the new-mail watcher (kiddietrac:mail-watch). NULL = never looked: the first
 *                         pass only sets it, so connecting a mailbox never rings for the mail already in it.
 *   auth_failed_at      - Microsoft refused the stored sign-in (revoked, password changed, 90 days idle). The bell says
 *                         so ONCE, the screen shows "Reconnect", and the watcher stops asking until it is reconnected.
 *   token_refreshed_at  - the last time the refresh token was used, for the daily keep-alive.
 *
 * email_mail_alerts: one row per message already announced, so a slow index or an overlapping pass never rings twice.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('email_accounts', function (Blueprint $t) {
            if (! Schema::hasColumn('email_accounts', 'mail_seen_until')) $t->dateTime('mail_seen_until')->nullable();
            if (! Schema::hasColumn('email_accounts', 'auth_failed_at')) $t->dateTime('auth_failed_at')->nullable();
            if (! Schema::hasColumn('email_accounts', 'token_refreshed_at')) $t->dateTime('token_refreshed_at')->nullable();
        });
        if (! Schema::hasTable('email_mail_alerts')) {
            Schema::create('email_mail_alerts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('account_id');
                $t->char('message_key', 64);
                $t->dateTime('created_at')->nullable();
                $t->unique(['account_id', 'message_key'], 'email_mail_alerts_acct_key_uq');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('email_mail_alerts');
        Schema::table('email_accounts', function (Blueprint $t) {
            foreach (['mail_seen_until', 'auth_failed_at', 'token_refreshed_at'] as $c) {
                if (Schema::hasColumn('email_accounts', $c)) $t->dropColumn($c);
            }
        });
    }
};
