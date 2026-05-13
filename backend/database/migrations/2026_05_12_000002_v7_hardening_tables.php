<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ─── Password reset tokens ──────────────────────────────────
        if (! Schema::hasTable('password_resets')) {
            Schema::create('password_resets', function (Blueprint $t) {
                $t->id();
                $t->string('email', 180);
                $t->string('token', 64)->unique();
                $t->timestamp('expires_at');
                $t->timestamp('used_at')->nullable();
                $t->string('requester_ip', 45)->nullable();
                $t->timestamp('created_at')->useCurrent();
                $t->index('email');
                $t->index('expires_at');
            });
        }

        // ─── Photo usage tracking per centre ────────────────────────
        if (! Schema::hasTable('storage_usage')) {
            Schema::create('storage_usage', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('centre_id');
                $t->unsignedBigInteger('total_bytes')->default(0);
                $t->unsignedInteger('total_files')->default(0);
                $t->unsignedBigInteger('quota_bytes')->default(1073741824); // 1 GB default
                $t->timestamp('last_calculated_at')->useCurrent();
                $t->timestamps();
                $t->unique('centre_id');
            });
        }

        // ─── Audit log for sensitive actions ────────────────────────
        if (! Schema::hasTable('audit_logs')) {
            Schema::create('audit_logs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->unsignedBigInteger('centre_id')->nullable();
                $t->string('action', 80); // 'login', 'password_reset', 'data_export', 'centre_signup', etc.
                $t->string('target_type', 60)->nullable(); // 'user', 'child', 'invoice', etc.
                $t->unsignedBigInteger('target_id')->nullable();
                $t->text('details')->nullable();
                $t->string('ip_address', 45)->nullable();
                $t->string('user_agent', 500)->nullable();
                $t->timestamp('created_at')->useCurrent();
                $t->index(['user_id', 'created_at']);
                $t->index(['centre_id', 'action']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('storage_usage');
        Schema::dropIfExists('password_resets');
    }
};
