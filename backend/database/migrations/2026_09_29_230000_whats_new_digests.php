<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* One row per weekly What's new email run per agency (2026-09-29): which entries went
   out (by date and title) and to how many people. through_date is the newest entry
   included, so the next run only sends what is newer. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('whats_new_digests')) {
            return;
        }
        Schema::create('whats_new_digests', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id')->index();
            $t->date('since_date')->nullable();
            $t->date('through_date');
            $t->unsignedSmallInteger('entries')->default(0);
            $t->unsignedInteger('recipients')->default(0);
            $t->text('titles')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whats_new_digests');
    }
};
