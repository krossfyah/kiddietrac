<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Task reminders (2026-10-27, App\Support\TaskReminders): when (UTC), how (["app","email","sms","voice"]),
   when it went and what each channel did. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $t) {
            if (! Schema::hasColumn('tasks', 'remind_at')) $t->dateTime('remind_at')->nullable()->index();
            if (! Schema::hasColumn('tasks', 'remind_channels')) $t->json('remind_channels')->nullable();
            if (! Schema::hasColumn('tasks', 'reminded_at')) $t->dateTime('reminded_at')->nullable();
            if (! Schema::hasColumn('tasks', 'remind_result')) $t->json('remind_result')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $t) {
            foreach (['remind_at', 'remind_channels', 'reminded_at', 'remind_result'] as $c) {
                if (Schema::hasColumn('tasks', $c)) $t->dropColumn($c);
            }
        });
    }
};
