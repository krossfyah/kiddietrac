<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Calls INTO the toll-free line, for the dialer's Missed calls list and the 📞 badge
 * (2026-10-23). One row per call (Telnyx CallSid): ringing → answered | missed, and
 * voicemail when the caller left one. dialer_seen = when each person last looked at
 * their missed calls (voicemails keep their own shared "heard" mark).
 * The rows the audit log already holds (voice.menu) are copied in once.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inbound_calls')) {
            Schema::create('inbound_calls', function (Blueprint $t) {
                $t->id();
                $t->string('call_sid', 100)->unique();
                $t->string('from_number', 40)->nullable();
                $t->string('department', 80)->nullable();
                $t->string('outcome', 16)->default('ringing');
                $t->unsignedBigInteger('voicemail_id')->nullable();
                $t->timestamps();
                $t->index('created_at');
            });
            DB::statement('ALTER TABLE inbound_calls CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        }
        if (! Schema::hasTable('dialer_seen')) {
            Schema::create('dialer_seen', function (Blueprint $t) {
                $t->unsignedBigInteger('user_id')->primary();
                $t->timestamp('missed_seen_at')->nullable();
            });
        }

        // History: the call menu's audit lines (the summary names caller + department).
        $rows = DB::table('audit_logs')->where('action', 'voice.menu')->where('created_at', '>=', now()->subDays(60))->orderBy('id')->get(['payload', 'created_at']);
        foreach ($rows as $r) {
            $p = json_decode((string) $r->payload, true) ?: [];
            $sid = (string) ($p['call'] ?? '');
            $s = (string) ($p['summary'] ?? '');
            if (! str_starts_with($sid, 'v3:')) continue;
            $from = preg_match('/\((\d{3})\) (\d{3})-(\d{4})/', $s, $m) ? '+1' . $m[1] . $m[2] . $m[3] : null;
            $outcome = null; $dept = null;
            if (preg_match('/chose (.+?) — (ringing|after hours|nothing to ring)/u', $s, $m)) { $dept = $m[1]; $outcome = $m[2] === 'ringing' ? 'ringing' : 'missed'; }
            elseif (preg_match('/^(.+?): nobody accepted/u', $s, $m)) { $dept = $m[1]; $outcome = 'missed'; }
            elseif (preg_match('/^(.+?): call from .+ was answered/u', $s, $m)) { $dept = $m[1]; $outcome = 'answered'; }
            if (! $outcome) continue;
            $have = DB::table('inbound_calls')->where('call_sid', $sid)->first();
            if ($have) {
                if ($have->outcome === 'ringing' || ($outcome === 'answered')) DB::table('inbound_calls')->where('id', $have->id)->update(['outcome' => $outcome, 'updated_at' => $r->created_at]);
                continue;
            }
            DB::table('inbound_calls')->insert(['call_sid' => $sid, 'from_number' => $from, 'department' => $dept, 'outcome' => $outcome, 'created_at' => $r->created_at, 'updated_at' => $r->created_at]);
        }
        // Voicemails left on those calls (and on calls the menu never saw).
        foreach (DB::table('voicemails')->whereNotNull('call_sid')->where('created_at', '>=', now()->subDays(60))->get() as $v) {
            $have = DB::table('inbound_calls')->where('call_sid', $v->call_sid)->first();
            if ($have) {
                DB::table('inbound_calls')->where('id', $have->id)->update(['outcome' => 'voicemail', 'voicemail_id' => $v->id, 'from_number' => $have->from_number ?: $v->from_number]);
            } else {
                DB::table('inbound_calls')->insert(['call_sid' => $v->call_sid, 'from_number' => $v->from_number, 'department' => $v->department, 'outcome' => 'voicemail',
                    'voicemail_id' => $v->id, 'created_at' => $v->created_at, 'updated_at' => $v->created_at]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_calls');
        Schema::dropIfExists('dialer_seen');
    }
};
