<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v22p92 — Outbound email log. Every message sent through the app is recorded
 * here (via a MessageSent listener) so platform admins can audit exactly what
 * left the system, to whom, and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_logs')) return;
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->string('to_email', 320)->nullable();
            $table->string('to_name', 191)->nullable();
            $table->string('from_email', 320)->nullable();
            $table->string('subject', 500)->nullable();
            $table->string('mailer', 60)->nullable();
            $table->string('status', 40)->default('sent');
            $table->text('error')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index('created_at');
            $table->index('to_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
