<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * New-mail alerts per mailbox, per person (2026-11-05, Anthony: "add option to turn off alerts for mailboxes in mail
 * client and add ability to change notification sounds for new mail for each inbox individually"). A shared Sales
 * mailbox rings everyone on the sales team, so the choice is each person's own: one row per person and mailbox. No row =
 * alerts on with the person's Email tone (Settings > Notifications).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_account_alerts')) return;
        Schema::create('email_account_alerts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('account_id');     // email_accounts.id
            $t->boolean('alerts_on')->default(true);
            $t->string('tone', 40)->nullable();       // a NotificationSounds library id; null = the person's Email tone
            $t->timestamps();
            $t->unique(['user_id', 'account_id']);
            $t->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_account_alerts');
    }
};
