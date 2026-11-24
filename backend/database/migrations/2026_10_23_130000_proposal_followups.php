<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Automatic proposal follow-ups (2026-10-23, App\Support\ProposalFollowUp). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_quotes', function (Blueprint $t) {
            if (! Schema::hasColumn('sales_quotes', 'followup_days')) $t->unsignedSmallInteger('followup_days')->nullable();
            if (! Schema::hasColumn('sales_quotes', 'followup_max')) $t->unsignedTinyInteger('followup_max')->nullable();
            if (! Schema::hasColumn('sales_quotes', 'followups_sent')) $t->unsignedTinyInteger('followups_sent')->default(0);
            if (! Schema::hasColumn('sales_quotes', 'followup_next_at')) $t->timestamp('followup_next_at')->nullable();
            if (! Schema::hasColumn('sales_quotes', 'last_followup_at')) $t->timestamp('last_followup_at')->nullable();
            if (! Schema::hasColumn('sales_quotes', 'followup_task_id')) $t->unsignedBigInteger('followup_task_id')->nullable();
            if (! Schema::hasColumn('sales_quotes', 'email_note')) $t->text('email_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sales_quotes', fn (Blueprint $t) => $t->dropColumn(['followup_days', 'followup_max', 'followups_sent', 'followup_next_at', 'last_followup_at', 'followup_task_id', 'email_note']));
    }
};
