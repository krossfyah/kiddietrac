<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let the missed-message emailer cover team chat too.
 *
 * `messages` (family threads) has had `email_notified_at` since the emailer was built, so
 * an unread message to a parent turns into an email after 30 minutes. `staff_messages`
 * never had one, and the command only ever read `messages` — so a staff-to-staff message
 * that went unread produced an in-app bell and nothing else. Somebody who does not open
 * the app that day simply never heard.
 *
 * Same column, same meaning: the moment we emailed somebody about it, so we do not email
 * them twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('staff_messages', 'email_notified_at')) {
            Schema::table('staff_messages', function (Blueprint $table) {
                $table->timestamp('email_notified_at')->nullable()->after('created_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('staff_messages', 'email_notified_at')) {
            Schema::table('staff_messages', function (Blueprint $table) {
                $table->dropColumn('email_notified_at');
            });
        }
    }
};
