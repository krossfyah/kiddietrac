<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Calendar sync (2026-11-03): one private subscription link per person and kind (parent / staff).
 * Only the salt and sha256(token) are stored; the token is HMAC(APP_KEY, salt) - see
 * App\Support\CalendarFeed. Reset = new salt, so the old link dies at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('calendar_feeds')) {
            return;
        }
        Schema::create('calendar_feeds', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('kind', 10);                 // parent | staff
            $t->char('salt', 64);
            $t->char('token_hash', 64);
            $t->text('options')->nullable();       // JSON: closures/events/absences/schedule or shifts/timeoff
            $t->dateTime('reset_at')->nullable();
            $t->dateTime('last_polled_at')->nullable();
            $t->unsignedInteger('poll_count')->default(0);
            $t->string('last_agent', 120)->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->unique(['user_id', 'kind'], 'calendar_feeds_user_kind');
            $t->unique('token_hash', 'calendar_feeds_token_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_feeds');
    }
};
