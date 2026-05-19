<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v22p5 — kiosk mode.
 *
 * Adds:
 *   - centres.kiosk_token             (varchar 64, unique, nullable) — public URL identifier.
 *   - centres.kiosk_enabled           (tinyint, default 0) — director toggle.
 *   - guardians.kiosk_pin_hash        (varchar 255, nullable) — bcrypt of 4–6 digit PIN.
 *   - guardians.kiosk_pin_set_at      (timestamp, nullable) — audit / rotation reminder.
 *   - check_events.kiosk_source       (tinyint, default 0) — flag rows recorded via kiosk.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('centres', function (Blueprint $table) {
            $table->string('kiosk_token', 64)->nullable()->after('updated_at');
            $table->boolean('kiosk_enabled')->default(false)->after('kiosk_token');
            $table->unique('kiosk_token');
        });

        Schema::table('guardians', function (Blueprint $table) {
            $table->string('kiosk_pin_hash', 255)->nullable()->after('billing_share_pct');
            $table->timestamp('kiosk_pin_set_at')->nullable()->after('kiosk_pin_hash');
        });

        Schema::table('check_events', function (Blueprint $table) {
            $table->boolean('kiosk_source')->default(false)->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('centres', function (Blueprint $table) {
            $table->dropUnique(['kiosk_token']);
            $table->dropColumn(['kiosk_token', 'kiosk_enabled']);
        });

        Schema::table('guardians', function (Blueprint $table) {
            $table->dropColumn(['kiosk_pin_hash', 'kiosk_pin_set_at']);
        });

        Schema::table('check_events', function (Blueprint $table) {
            $table->dropColumn('kiosk_source');
        });
    }
};
