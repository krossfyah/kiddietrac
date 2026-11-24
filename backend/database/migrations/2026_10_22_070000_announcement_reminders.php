<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* ANNOUNCEMENT REMINDERS (2026-10-22). Anthony: "with announcements section add sent
   reminder function as optional if you are sending an announcement to ask for something".
   His choices: a due date with preset reminders (up to two), and who a reminder goes to is
   the sender's choice — only the people who have not tapped "Done", or everyone again.

   announcements.due_at       UTC; the composer's date is the agency's wall clock.
   announcements.remind_who   'pending' (the Done button is shown, reminders skip anyone
                              done — per FAMILY for parents) or 'all'; NULL = no reminders.
   announcement_reminders     one row per reminder; sent_at is the claim, as for
                              scheduled announcements.
   announcement_done          who has tapped Done. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $t) {
            if (! Schema::hasColumn('announcements', 'due_at')) {
                $t->dateTime('due_at')->nullable()->after('scheduled_at');
            }
            if (! Schema::hasColumn('announcements', 'remind_who')) {
                $t->string('remind_who', 10)->nullable()->after('due_at');
            }
        });

        if (! Schema::hasTable('announcement_reminders')) {
            Schema::create('announcement_reminders', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('announcement_id')->index();
                $t->dateTime('remind_at')->index();
                $t->dateTime('sent_at')->nullable();
                $t->unsignedInteger('delivered')->nullable();
                $t->dateTime('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('announcement_done')) {
            Schema::create('announcement_done', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('announcement_id');
                $t->unsignedBigInteger('user_id');
                $t->dateTime('done_at');
                $t->unique(['announcement_id', 'user_id']);
                $t->index('user_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_done');
        Schema::dropIfExists('announcement_reminders');
        Schema::table('announcements', function (Blueprint $t) {
            foreach (['remind_who', 'due_at'] as $c) {
                if (Schema::hasColumn('announcements', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
