<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A prospect's answers to the KiddieTrac intake form, one per website booking (2026-10-23). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('prospect_intakes')) {
            Schema::create('prospect_intakes', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('booking_id')->unique();
                $t->unsignedBigInteger('sales_lead_id')->nullable()->index();
                $t->string('email', 160)->nullable();
                $t->longText('answers');
                $t->timestamp('submitted_at')->nullable();
                $t->string('ip', 45)->nullable();
                $t->timestamps();
            });
            DB::statement('ALTER TABLE prospect_intakes CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('prospect_intakes');
    }
};
