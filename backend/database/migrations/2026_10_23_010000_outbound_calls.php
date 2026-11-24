<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* The outbound dialer's call log (2026-10-23, App\Support\Dialer). One row per call placed
   from the portal: who called whom, from which line (the platform's toll-free or an
   agency's own number), what happened, how long, and the recording when there is one.
   hook_token protects that call's Telnyx callbacks. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('outbound_calls')) {
            return;
        }
        Schema::create('outbound_calls', function (Blueprint $t) {
            $t->id();
            $t->string('scope', 10);                       // platform | agency
            $t->unsignedBigInteger('agency_id')->nullable()->index();
            $t->unsignedBigInteger('account_agency_id');   // whose Telnyx account placed it
            $t->unsignedBigInteger('user_id')->index();
            $t->string('from_number', 20);
            $t->string('to_number', 20);
            $t->string('to_name', 160)->nullable();
            $t->unsignedBigInteger('lead_id')->nullable()->index();
            $t->unsignedBigInteger('chat_id')->nullable();
            $t->string('context', 80)->nullable();          // the screen it was started from
            $t->string('hook_token', 64);
            $t->string('call_sid', 120)->nullable();
            $t->string('status', 20)->default('dialing');
            $t->boolean('recorded')->default(false);
            $t->string('recording_path', 255)->nullable();
            $t->string('recording_sid', 120)->nullable();
            $t->unsignedInteger('recording_seconds')->nullable();
            $t->dateTime('answered_at')->nullable();
            $t->dateTime('ended_at')->nullable();
            $t->unsignedInteger('duration_seconds')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_calls');
    }
};
