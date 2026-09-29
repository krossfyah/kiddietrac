<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whose consent a text or call went out on (2026-09-29, App\Support\Handset).
 *
 * One phone can carry several accounts, and a send to one of them may rely on an
 * opt-in given on another account on the same phone in the same agency. The row
 * records which account's consent that was, so "who agreed to this?" always has an
 * answer. It is null when no consent was needed (an emergency call) or the send
 * never got that far.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['sms_messages', 'voice_calls'] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'consent_user_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->unsignedBigInteger('consent_user_id')->nullable()->after('to_user_id');
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['sms_messages', 'voice_calls'] as $table) {
            if (Schema::hasColumn($table, 'consent_user_id')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('consent_user_id'));
            }
        }
    }
};
