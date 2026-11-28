<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SUBSTITUTES: POOL + CLAIMING (2026-11-28). Contact choices per pool member, and what a cover request needs to be
 * claimed, confirmed or released (App\Support\SubstituteCover).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('substitute_pool', function (Blueprint $t) {
            if (! Schema::hasColumn('substitute_pool', 'notify_app')) $t->boolean('notify_app')->default(true)->after('active');
            if (! Schema::hasColumn('substitute_pool', 'notify_email')) $t->boolean('notify_email')->default(true)->after('notify_app');
        });
        Schema::table('substitute_requests', function (Blueprint $t) {
            if (! Schema::hasColumn('substitute_requests', 'room_id')) $t->unsignedBigInteger('room_id')->nullable()->after('centre_id');
            if (! Schema::hasColumn('substitute_requests', 'claimed_at')) $t->dateTime('claimed_at')->nullable()->after('assigned_user_id');
            if (! Schema::hasColumn('substitute_requests', 'confirmed_by_id')) $t->unsignedBigInteger('confirmed_by_id')->nullable()->after('filled_at');
            if (! Schema::hasColumn('substitute_requests', 'shift_id')) $t->unsignedBigInteger('shift_id')->nullable()->after('confirmed_by_id');
            if (! Schema::hasColumn('substitute_requests', 'cancelled_at')) $t->dateTime('cancelled_at')->nullable()->after('shift_id');
        });
    }

    public function down(): void
    {
        Schema::table('substitute_requests', function (Blueprint $t) {
            $t->dropColumn(['room_id', 'claimed_at', 'confirmed_by_id', 'shift_id', 'cancelled_at']);
        });
        Schema::table('substitute_pool', function (Blueprint $t) {
            $t->dropColumn(['notify_app', 'notify_email']);
        });
    }
};
