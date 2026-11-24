<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Parents call in to report absent / late (2026-11-24, App\Support\ParentLine). */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            if (! Schema::hasColumn('users', 'phone_pin_hash')) $t->string('phone_pin_hash')->nullable();
            if (! Schema::hasColumn('users', 'phone_pin_set_at')) $t->timestamp('phone_pin_set_at')->nullable();
        });
        if (! Schema::hasTable('phone_reports')) {
            Schema::create('phone_reports', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->string('call_sid', 120)->nullable()->index();
                $t->string('from_number', 20);
                $t->string('via', 10)->default('message');
                $t->string('action', 30);
                $t->string('reason', 30)->nullable();
                $t->string('arrive_by', 5)->nullable();
                $t->date('for_date');
                $t->json('candidates');
                $t->string('status', 20)->default('pending')->index();
                $t->string('recording_path')->nullable();
                $t->unsignedInteger('recording_seconds')->nullable();
                $t->text('transcript')->nullable();
                $t->unsignedBigInteger('child_id')->nullable();
                $t->unsignedBigInteger('decided_by_id')->nullable();
                $t->timestamp('decided_at')->nullable();
                $t->string('outcome', 500)->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_reports');
    }
};
