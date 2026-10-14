<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A parent asks for a pick-up login for one of their emergency contacts (2026-10-13,
 * Anthony: "add ability to request account for emergency guardian under parent view").
 * The centre approves it: a login is a key to the children, so a parent never mints one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pickup_login_requests')) return;
        Schema::create('pickup_login_requests', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('family_id')->index();
            $t->unsignedBigInteger('emergency_contact_id')->index();
            $t->unsignedBigInteger('requested_by');
            $t->string('email', 190);
            $t->string('status', 16)->default('pending'); // pending | approved | declined | cancelled
            $t->unsignedBigInteger('decided_by')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pickup_login_requests');
    }
};
