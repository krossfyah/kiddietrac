<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Safe Arrival phone call (2026-10-13): which check a call belongs to, the key the parent
 * pressed, and the parent's answer on the check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_calls', function (Blueprint $t) {
            if (! Schema::hasColumn('voice_calls', 'ref_type')) $t->string('ref_type', 32)->nullable()->after('category');
            if (! Schema::hasColumn('voice_calls', 'ref_id')) $t->unsignedBigInteger('ref_id')->nullable()->after('ref_type');
            if (! Schema::hasColumn('voice_calls', 'dtmf')) $t->string('dtmf', 8)->nullable()->after('ref_id');
        });
        Schema::table('safe_arrival_checks', function (Blueprint $t) {
            if (! Schema::hasColumn('safe_arrival_checks', 'call_started_at')) $t->timestamp('call_started_at')->nullable();
            if (! Schema::hasColumn('safe_arrival_checks', 'calls_placed')) $t->unsignedTinyInteger('calls_placed')->default(0);
            if (! Schema::hasColumn('safe_arrival_checks', 'parent_response')) $t->string('parent_response', 16)->nullable();
            if (! Schema::hasColumn('safe_arrival_checks', 'parent_response_at')) $t->timestamp('parent_response_at')->nullable();
            if (! Schema::hasColumn('safe_arrival_checks', 'parent_response_by_id')) $t->unsignedBigInteger('parent_response_by_id')->nullable();
        });
        try {
            Schema::table('voice_calls', fn (Blueprint $t) => $t->index(['ref_type', 'ref_id']));
        } catch (\Throwable $e) {
        }
    }

    public function down(): void
    {
        Schema::table('voice_calls', fn (Blueprint $t) => $t->dropColumn(['ref_type', 'ref_id', 'dtmf']));
        Schema::table('safe_arrival_checks', fn (Blueprint $t) => $t->dropColumn(['call_started_at', 'calls_placed', 'parent_response', 'parent_response_at', 'parent_response_by_id']));
    }
};
