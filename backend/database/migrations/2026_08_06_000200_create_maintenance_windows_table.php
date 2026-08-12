<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scheduled platform maintenance / downtime (2026-08-06).
 *
 * A super-admin schedules a window during which no one (except platform admins)
 * can sign in. Optionally a heads-up email goes out to all users. "In
 * maintenance now" = active=1 AND starts_at <= now AND (ends_at IS NULL OR
 * ends_at >= now).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_windows', function (Blueprint $t) {
            $t->id();
            $t->timestamp('starts_at')->nullable();
            $t->timestamp('ends_at')->nullable();
            $t->text('message')->nullable();
            $t->boolean('active')->default(false);
            $t->timestamp('notified_at')->nullable();   // when the all-user heads-up was sent
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->index(['active', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_windows');
    }
};
