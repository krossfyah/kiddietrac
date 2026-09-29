<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/* Quarterly access reviews (2026-09-29, SOC 2 CC6.2/CC6.3): who reviewed, when, a snapshot
   of every admin-level account at that moment, what they found and what they changed. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('access_reviews')) return;
        Schema::create('access_reviews', function (Blueprint $t) {
            $t->id();
            $t->timestamp('reviewed_at')->index();
            $t->unsignedBigInteger('reviewer_id');
            $t->string('reviewer_name', 160);
            $t->unsignedInteger('admin_count');
            $t->longText('snapshot');
            $t->text('findings');
            $t->text('actions_taken')->nullable();
            $t->timestamps();
        });
        DB::statement('ALTER TABLE access_reviews CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
    }

    public function down(): void
    {
        Schema::dropIfExists('access_reviews');
    }
};
