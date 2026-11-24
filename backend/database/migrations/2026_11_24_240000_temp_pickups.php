<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Temporary pickup people (2026-11-24, App\Support\TempPickups). */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('temp_pickups')) {
            Schema::create('temp_pickups', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('centre_id');
                $t->unsignedBigInteger('family_id')->index();
                $t->json('child_ids');
                $t->unsignedBigInteger('created_by_user_id');
                $t->string('full_name', 120);
                $t->string('relationship', 60)->nullable();
                $t->string('phone', 20);
                $t->string('photo_path')->nullable();
                $t->string('mode', 10)->default('pass');
                $t->boolean('one_time')->default(true);
                $t->date('starts_on');
                $t->date('ends_on');
                $t->string('note', 300)->nullable();
                $t->string('code_hash', 64);
                $t->text('code_enc');
                $t->string('token_hash', 64)->unique();
                $t->string('status', 12)->default('active')->index();
                $t->unsignedInteger('uses')->default(0);
                $t->unsignedTinyInteger('fails')->default(0);
                $t->timestamp('last_used_at')->nullable();
                $t->unsignedBigInteger('cancelled_by_id')->nullable();
                $t->timestamp('cancelled_at')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('temp_pickup_uses')) {
            Schema::create('temp_pickup_uses', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('temp_pickup_id')->index();
                $t->unsignedBigInteger('child_id');
                $t->unsignedBigInteger('check_event_id')->nullable();
                $t->unsignedBigInteger('by_user_id')->nullable();
                $t->string('method', 20);
                $t->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('temp_pickup_uses');
        Schema::dropIfExists('temp_pickups');
    }
};
