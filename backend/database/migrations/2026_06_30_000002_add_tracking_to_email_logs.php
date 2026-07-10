<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v22p93 — open-tracking for outbound emails. A tracking pixel keyed on
 * `tracking_token` records the first open + a running open count.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('email_logs')) return;
        Schema::table('email_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('email_logs', 'tracking_token')) $table->string('tracking_token', 64)->nullable()->index();
            if (! Schema::hasColumn('email_logs', 'opened_at')) $table->timestamp('opened_at')->nullable();
            if (! Schema::hasColumn('email_logs', 'opens')) $table->unsignedInteger('opens')->default(0);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('email_logs')) return;
        Schema::table('email_logs', function (Blueprint $table) {
            foreach (['tracking_token', 'opened_at', 'opens'] as $c) {
                if (Schema::hasColumn('email_logs', $c)) $table->dropColumn($c);
            }
        });
    }
};
