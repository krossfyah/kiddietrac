<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transcripts for voicemails and recorded calls, recordings of answered incoming calls, and
 * the lead activity each one was filed under (2026-10-23, App\Support\CallRecords).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['voicemails', 'outbound_calls', 'inbound_calls'] as $t) {
            Schema::table($t, function (Blueprint $b) use ($t) {
                if (! Schema::hasColumn($t, 'transcript')) $b->text('transcript')->nullable();
                if (! Schema::hasColumn($t, 'transcribed_at')) $b->timestamp('transcribed_at')->nullable();
                if (! Schema::hasColumn($t, 'transcript_error')) $b->string('transcript_error', 255)->nullable();
                if (! Schema::hasColumn($t, 'lead_activity_id')) $b->unsignedBigInteger('lead_activity_id')->nullable();
            });
        }
        Schema::table('inbound_calls', function (Blueprint $b) {
            if (! Schema::hasColumn('inbound_calls', 'recording_sid')) $b->string('recording_sid', 120)->nullable();
            if (! Schema::hasColumn('inbound_calls', 'recording_path')) $b->string('recording_path', 255)->nullable();
            if (! Schema::hasColumn('inbound_calls', 'recording_seconds')) $b->unsignedInteger('recording_seconds')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['voicemails', 'outbound_calls', 'inbound_calls'] as $t) {
            Schema::table($t, fn (Blueprint $b) => $b->dropColumn(['transcript', 'transcribed_at', 'transcript_error', 'lead_activity_id']));
        }
        Schema::table('inbound_calls', fn (Blueprint $b) => $b->dropColumn(['recording_sid', 'recording_path', 'recording_seconds']));
    }
};
