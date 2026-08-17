<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When biometric unlock was switched on, and on what.
 *
 * Biometric enrolment has been purely client-side — kt-biometric.js writes a localStorage
 * flag and makes no API call — so the server has never known it happened and nobody was
 * ever told. This is the record that makes the alert possible, and it exists mainly so the
 * same device does not alert twice.
 *
 * It holds no biometric data and could not: a fingerprint or face never leaves the handset,
 * which only reports whether the check passed. What is stored is the same thing any sign-in
 * log holds — a device description, an IP and a time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('biometric_enrolments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            // user + device, hashed. One alert per account per device, and re-enrolling
            // after switching off is a real event that should alert again.
            $table->string('fingerprint', 64);
            $table->string('device', 120)->nullable();
            $table->string('ip', 45)->nullable();          // 45 = an IPv6 address
            $table->string('user_agent', 500)->nullable();
            // A device reporting enrolment that predates this feature, rather than one
            // enrolling now. The wording of the alert differs, and a burst of these is
            // expected rather than alarming.
            $table->boolean('was_catch_up')->default(false);
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'fingerprint'], 'biometric_user_device_uniq');
            $table->index('enrolled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biometric_enrolments');
    }
};
