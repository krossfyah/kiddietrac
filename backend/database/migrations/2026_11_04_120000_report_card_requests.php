<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* A parent asking for a child's report card for a quarter (App\Support\ReportCardQuarter, 2026-11-04): one request
   per child per quarter. report_card_reminders: which educator was reminded about which quarter, so the
   quarter-end reminder goes out once. */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('report_card_requests')) {
            Schema::create('report_card_requests', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('child_id');
                $t->unsignedBigInteger('agency_id');
                $t->string('quarter_key', 12);
                $t->string('term', 40);
                $t->unsignedBigInteger('requested_by_user_id');
                $t->string('status', 16)->default('requested');   // requested | fulfilled
                $t->unsignedBigInteger('report_card_id')->nullable();
                $t->timestamp('fulfilled_at')->nullable();
                $t->timestamps();
                $t->unique(['child_id', 'quarter_key'], 'rc_request_once');
                $t->index(['agency_id', 'status']);
            });
        }
        if (! Schema::hasTable('report_card_reminders')) {
            Schema::create('report_card_reminders', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('user_id');
                $t->unsignedBigInteger('agency_id');
                $t->string('quarter_key', 12);
                $t->unsignedInteger('children')->default(0);
                $t->timestamp('sent_at')->nullable();
                $t->unique(['user_id', 'agency_id', 'quarter_key'], 'rc_remind_once');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('report_card_requests');
        Schema::dropIfExists('report_card_reminders');
    }
};
