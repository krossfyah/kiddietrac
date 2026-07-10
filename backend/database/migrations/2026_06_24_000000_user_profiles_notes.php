<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v22 — user profile extras (address, DOB, emergency contact) + timestamped admin notes.
 * Two NEW tables; the existing users table is untouched (zero login risk).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('user_profiles')) {
            Schema::create('user_profiles', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id')->unique();
                $t->string('address', 300)->nullable();
                $t->date('date_of_birth')->nullable();
                $t->string('emergency_contact_name', 160)->nullable();
                $t->string('emergency_contact_phone', 60)->nullable();
                $t->string('emergency_contact_relation', 80)->nullable();
                $t->timestamps();
            });
        }
        if (!Schema::hasTable('user_notes')) {
            Schema::create('user_notes', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id')->index();
                $t->text('note');
                $t->unsignedBigInteger('created_by')->nullable();
                $t->string('created_by_name', 160)->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notes');
        Schema::dropIfExists('user_profiles');
    }
};
