<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * CALLS AND VOICEMAILS ON THE LEAD, WITH TRANSCRIPTS (2026-10-23, Anthony: "are transcripts
 * from calls being saved associated to the number and then attached to the lead once
 * created?" - they were not; he chose "link by number + transcribe" and recording answered
 * incoming calls with notice).
 *
 * Three kinds of audio on the KiddieTrac line:
 *   voicemails      (from_number)  - left by a caller
 *   inbound_calls   (from_number)  - an answered incoming call, recorded with notice
 *   outbound_calls  (to_number)    - a call made from the dial pad, recorded with notice
 *
 * run(), every two minutes (kt:call-transcripts):
 *   1. TRANSCRIBE each new recording (Telnyx speech-to-text, Whisper large v3 turbo).
 *   2. FILE IT ON THE LEAD: a dial-pad call made from a lead belongs to that lead; anything
 *      else goes to the open lead whose phone number matches (last ten digits). It becomes a
 *      "call" activity with the recording attached and the transcript in its text. Waits up
 *      to ten minutes for the transcript before filing without one; a transcript that lands
 *      later is added to the activity it was filed under.
 *   A recording with no matching lead stays unfiled, and is filed the moment a lead with
 *   that number exists (a lead made from a voicemail files the rest straight away).
 */
final class CallRecords
{
    private const LINE_AGENCY = 2;
    private const MODEL = 'openai/whisper-large-v3-turbo';
    private const DAYS = 90;

    /* WHO SAID WHAT (2026-10-23, Anthony: "when you transcribe calls can you break it out with
       who is saying what? and not in all one line"). A call is recorded on two channels - the
       person whose call it is on the first, the other party on the second - so each side is
       found on its own channel: ffmpeg's silence detection gives the stretches each side is
       talking, a run of one side becomes a turn, each turn is transcribed, and the turns are
       written in order, one line per turn: "Anthony: …" / "Caroline: …". Speech-to-text gives
       no timings or speaker labels of its own (tried both engines). A voicemail has one
       speaker and is written a sentence per line. */
    private static function ffmpeg(): ?string
    {
        $f = dirname(base_path(), 2) . '/bin/ffmpeg';
        return is_file($f) && is_executable($f) ? $f : null;
    }

    private static function stt(string $bytes, string $name, string $model): ?string
    {
        // Telnyx allows only a few transcriptions a second: wait and try again when it says so.
        foreach ([0, 2, 5, 10, 20] as $wait) {
            if ($wait) sleep($wait);
            $r = Http::withToken(Telnyx::apiKey(self::LINE_AGENCY))->timeout(170)
                ->attach('file', $bytes, $name)
                ->post('https://api.telnyx.com/v2/ai/audio/transcriptions', ['model' => $model, 'response_format' => 'json']
                    // capitals and punctuation, so it reads like sentences
                    + (str_starts_with($model, 'deepgram/') ? ['model_config' => json_encode(['smart_format' => true, 'punctuate' => true])] : []));
            if ($r->status() !== 429) break;
        }
        if (! $r->successful()) throw new \RuntimeException('HTTP ' . $r->status() . ' ' . mb_substr((string) $r->body(), 0, 150));
        // the engine runs sentences together ("Hi.It's"): put the space back
        return trim(preg_replace('/([.?!,])(?=[A-Za-z])/u', '$1 ', (string) $r->json('text')));
    }

    /** Speech stretches per channel: [[ch, start, end], ...] and the length. */
    private static function speech(string $ff, string $file, int $ch): array
    {
        $cmd = escapeshellarg($ff) . ' -hide_banner -nostats -i ' . escapeshellarg($file)
            . ' -af ' . escapeshellarg('pan=mono|c0=c' . $ch . ',silencedetect=noise=-33dB:d=0.55') . ' -f null - 2>&1';
        $out = (string) shell_exec($cmd);
        $dur = 0.0;
        if (preg_match('/Duration: (\d+):(\d+):([\d.]+)/', $out, $m)) $dur = $m[1] * 3600 + $m[2] * 60 + (float) $m[3];
        preg_match_all('/silence_(start|end): ([\d.]+)/', $out, $mm, PREG_SET_ORDER);
        $res = [];
        $cur = 0.0;
        foreach ($mm as $x) {
            if ($x[1] === 'start') { if ((float) $x[2] - $cur > 0.3) $res[] = [$ch, $cur, (float) $x[2]]; }
            else $cur = (float) $x[2];
        }
        if ($dur - $cur > 0.3 && (! $mm || end($mm)[1] === 'end')) $res[] = [$ch, $cur, $dur];
        return $res;
    }

    private static function channels(string $ff, string $file): int
    {
        $out = (string) shell_exec(escapeshellarg($ff) . ' -hide_banner -i ' . escapeshellarg($file) . ' 2>&1');
        return preg_match('/Audio:[^\n]*\b(stereo|2 channels)\b/', $out) ? 2 : 1;
    }

    /** Names for the two sides of a call: [first channel, second channel]. */
    private static function names(string $table, ?int $id): array
    {
        $first = fn ($n) => trim((string) strtok(trim((string) $n), ' ')) ?: null;
        if ($table === 'outbound_calls' && $id) {
            $c = DB::table('outbound_calls')->where('id', $id)->first(['user_id', 'to_number', 'to_name', 'lead_id']);
            $me = $c && $c->user_id ? $first(DB::table('users')->where('id', $c->user_id)->value('first_name')) : null;
            $them = $c ? $first($c->to_name) : null;
            if (! $them && $c) {
                $l = $c->lead_id ? DB::table('sales_leads')->where('id', $c->lead_id)->first(['name']) : self::leadFor(self::digits($c->to_number));
                $them = $l ? $first($l->name) : null;
            }
            return [$me ?: 'KiddieTrac', $them ?: 'Caller'];
        }
        if ($table === 'inbound_calls' && $id) {
            $c = DB::table('inbound_calls')->where('id', $id)->first(['from_number']);
            $l = $c ? self::leadFor(self::digits($c->from_number)) : null;
            return [($l ? $first($l->name) : null) ?: 'Caller', 'KiddieTrac'];
        }
        return ['Caller', 'KiddieTrac'];
    }

    /** Speech to text for one stored recording. [text|null, error|null] */
    public static function transcribe(string $path, string $table = 'voicemails', ?int $id = null): array
    {
        try {
            if (! Storage::disk('local')->exists($path)) return [null, 'recording missing'];
            if (Telnyx::apiKey(self::LINE_AGENCY) === '') return [null, 'no Telnyx key'];
            $file = Storage::disk('local')->path($path);
            $ff = self::ffmpeg();

            if ($table !== 'voicemails' && $ff && self::channels($ff, $file) === 2) {
                // Turns: each side's speech in time order, a run of one side merged into one turn.
                $all = array_merge(self::speech($ff, $file, 0), self::speech($ff, $file, 1));
                usort($all, fn ($a, $b) => $a[1] <=> $b[1]);
                $turns = [];
                foreach ($all as $iv) {
                    $n = count($turns);
                    if ($n && $turns[$n - 1][0] === $iv[0]) { $turns[$n - 1][2] = max($turns[$n - 1][2], $iv[2]); continue; }
                    $turns[] = $iv;
                }
                $turns = array_values(array_filter($turns, fn ($t) => $t[2] - $t[1] >= 0.4));
                if ($turns && count($turns) <= 160) {
                    [$n0, $n1] = self::names($table, $id);
                    $lines = [];
                    $tmp = sys_get_temp_dir() . '/kt-turn-' . getmypid() . '.mp3';
                    foreach ($turns as [$ch, $a, $b]) {
                        $cut = escapeshellarg($ff) . ' -hide_banner -loglevel error -y -ss ' . max(0, $a - 0.2) . ' -to ' . ($b + 0.25) . ' -i ' . escapeshellarg($file)
                            . ' -af ' . escapeshellarg('pan=mono|c0=c' . $ch) . ' -ar 16000 -b:a 48k ' . escapeshellarg($tmp) . ' 2>&1';
                        shell_exec($cut);
                        if (! is_file($tmp) || filesize($tmp) < 800) continue;
                        usleep(400000);
                        $t = self::stt((string) file_get_contents($tmp), 'turn.mp3', 'deepgram/nova-3');
                        @unlink($tmp);
                        if ($t === null || $t === '') continue;
                        $who = $ch === 0 ? $n0 : $n1;
                        $k = count($lines);
                        if ($k && str_starts_with($lines[$k - 1], $who . ': ')) $lines[$k - 1] .= ' ' . $t;
                        else $lines[] = $who . ': ' . $t;
                    }
                    @unlink($tmp);
                    if ($lines) return [implode("\n", $lines), null];
                }
            }

            // One speaker (a voicemail), or no way to split: the whole recording, a sentence per line.
            $t = self::stt(Storage::disk('local')->get($path), basename($path), self::MODEL);
            $t = trim(preg_replace('/(?<=[.?!])\s+(?=[A-Z0-9"“])/u', "\n", (string) $t));
            return [$t, null];
        } catch (\Throwable $e) {
            return [null, mb_substr($e->getMessage(), 0, 200)];
        }
    }

    private static function digits(?string $n): string
    {
        return substr(preg_replace('/\D/', '', (string) $n), -10);
    }

    /** The open lead with this number (most recently active first), or null. */
    public static function leadFor(string $digits): ?object
    {
        if (strlen($digits) !== 10) return null;
        return DB::table('sales_leads')->whereNull('deleted_at')->where('status', 'open')->whereNotNull('phone')
            ->orderByDesc('last_activity_at')->get(['id', 'name', 'company', 'phone'])
            ->first(fn ($l) => self::digits($l->phone) === $digits);
    }

    /** One pass: transcribe up to $max recordings, then file what is ready. */
    public static function run(int $max = 6, ?string $onlyDigits = null): array
    {
        $done = ['transcribed' => 0, 'filed' => 0, 'updated' => 0];
        $since = now()->subDays(self::DAYS);

        // 1. Transcribe.
        $todo = [];
        foreach ([['voicemails', 'stored_path'], ['inbound_calls', 'recording_path'], ['outbound_calls', 'recording_path']] as [$t, $col]) {
            $q = DB::table($t)->whereNotNull($col)->whereNull('transcribed_at')->where('created_at', '>=', $since);
            if ($t === 'voicemails') $q->whereNull('deleted_at');
            foreach ($q->orderBy('id')->limit($t === 'voicemails' ? $max : min($max, 2))->get(['id', $col . ' as path']) as $r) $todo[] = [$t, $r];
        }
        foreach (array_slice($todo, 0, $max) as [$t, $r]) {
            [$text, $err] = self::transcribe((string) $r->path, $t, (int) $r->id);
            if ($err && str_starts_with($err, 'HTTP 429')) break;   // busy: the next pass picks it up
            DB::table($t)->where('id', $r->id)->update(['transcript' => $text !== '' ? $text : null, 'transcribed_at' => now(),
                'transcript_error' => $err ? mb_substr($err, 0, 250) : null]);
            if ($text !== null) $done['transcribed']++;
        }

        // 2. File on leads (and add late transcripts to what was filed earlier).
        foreach (self::pending($since, $onlyDigits) as $item) {
            try {
                if ($item['activity_id']) {
                    if (self::addTranscript($item)) $done['updated']++;
                    continue;
                }
                $ready = $item['transcribed'] || Carbon::parse($item['created_at'])->lt(now()->subMinutes(10));
                if (! $ready) continue;
                if (self::file($item)) $done['filed']++;
            } catch (\Throwable $e) {
                Log::warning('CallRecords: could not file', ['item' => $item['table'] . '#' . $item['id'], 'error' => $e->getMessage()]);
            }
        }
        return $done;
    }

    /** Every recording not yet filed, plus filed ones whose transcript has not been added. */
    private static function pending($since, ?string $onlyDigits): array
    {
        $out = [];
        $vm = DB::table('voicemails')->whereNull('deleted_at')->whereNotNull('stored_path')->where('created_at', '>=', $since)
            ->where(fn ($q) => $q->whereNull('lead_activity_id')->orWhere(fn ($w) => $w->whereNotNull('transcript')->whereNotNull('lead_activity_id')))
            ->get(['id', 'from_number', 'department', 'duration', 'stored_path', 'transcript', 'transcribed_at', 'lead_activity_id', 'lead_id', 'created_at']);
        foreach ($vm as $v) $out[] = ['table' => 'voicemails', 'id' => $v->id, 'kind' => 'voicemail', 'number' => $v->from_number, 'dept' => $v->department,
            'seconds' => (int) $v->duration, 'path' => $v->stored_path, 'transcript' => $v->transcript, 'transcribed' => (bool) $v->transcribed_at,
            'activity_id' => $v->lead_activity_id, 'lead_id' => $v->lead_id, 'created_at' => $v->created_at];
        $in = DB::table('inbound_calls')->whereNotNull('recording_path')->where('created_at', '>=', $since)
            ->where(fn ($q) => $q->whereNull('lead_activity_id')->orWhere(fn ($w) => $w->whereNotNull('transcript')->whereNotNull('lead_activity_id')))
            ->get(['id', 'from_number', 'department', 'recording_seconds', 'recording_path', 'transcript', 'transcribed_at', 'lead_activity_id', 'created_at']);
        foreach ($in as $c) $out[] = ['table' => 'inbound_calls', 'id' => $c->id, 'kind' => 'incoming', 'number' => $c->from_number, 'dept' => $c->department,
            'seconds' => (int) $c->recording_seconds, 'path' => $c->recording_path, 'transcript' => $c->transcript, 'transcribed' => (bool) $c->transcribed_at,
            'activity_id' => $c->lead_activity_id, 'lead_id' => null, 'created_at' => $c->created_at];
        $outC = DB::table('outbound_calls')->whereNotNull('recording_path')->where('created_at', '>=', $since)
            ->where(fn ($q) => $q->whereNull('lead_activity_id')->orWhere(fn ($w) => $w->whereNotNull('transcript')->whereNotNull('lead_activity_id')))
            ->get(['id', 'to_number', 'to_name', 'user_id', 'recording_seconds', 'duration_seconds', 'recording_path', 'transcript', 'transcribed_at', 'lead_activity_id', 'lead_id', 'created_at']);
        foreach ($outC as $c) $out[] = ['table' => 'outbound_calls', 'id' => $c->id, 'kind' => 'outgoing', 'number' => $c->to_number, 'dept' => null,
            'seconds' => (int) ($c->recording_seconds ?: $c->duration_seconds), 'path' => $c->recording_path, 'transcript' => $c->transcript, 'transcribed' => (bool) $c->transcribed_at,
            'activity_id' => $c->lead_activity_id, 'lead_id' => $c->lead_id, 'user_id' => $c->user_id, 'created_at' => $c->created_at];
        if ($onlyDigits) $out = array_values(array_filter($out, fn ($i) => self::digits($i['number']) === $onlyDigits));
        return $out;
    }

    private static function header(array $i): string
    {
        $pretty = $i['number'] ? Dialer::pretty((string) $i['number']) : 'an unknown caller';
        $when = Carbon::parse($i['created_at'])->tz('America/Toronto')->format('D M j, g:i A') . ' ET';
        $len = $i['seconds'] ? ' · ' . gmdate($i['seconds'] >= 3600 ? 'G:i:s' : 'i:s', $i['seconds']) : '';
        return match ($i['kind']) {
            'voicemail' => '📼 Voicemail from ' . $pretty . ($i['dept'] ? ' to ' . $i['dept'] : '') . ' · ' . $when . $len,
            'incoming' => '📞 Call from ' . $pretty . ($i['dept'] ? ' (' . $i['dept'] . ')' : '') . ' · ' . $when . $len . ' · recorded',
            default => '📞 Call to ' . $pretty . ' · ' . $when . $len . ' · recorded',
        };
    }

    private static function transcriptText(?string $t): string
    {
        $t = trim((string) $t);
        return $t !== '' ? "\nTranscript:\n" . $t : '';
    }

    /** File one recording on its lead: the activity, the audio, the link back. */
    private static function file(array $i): bool
    {
        $lead = null;
        if (! empty($i['lead_id'])) $lead = DB::table('sales_leads')->whereNull('deleted_at')->where('id', $i['lead_id'])->first(['id']);
        if (! $lead) $lead = self::leadFor(self::digits($i['number']));
        if (! $lead) return false;

        // A voicemail turned into this lead by hand already has its entry (the file voicemail-{id}).
        $act = null;
        if ($i['kind'] === 'voicemail') {
            $own = DB::table('sales_activity_files')->where('lead_id', $lead->id)->where('original_name', 'like', 'voicemail-' . $i['id'] . '.%')->value('activity_id');
            if ($own) {
                $a0 = DB::table('sales_activities')->where('id', $own)->first(['id', 'body']);
                if ($a0 && ! str_contains((string) $a0->body, "\nTranscript:\n")) {
                    DB::table('sales_activities')->where('id', $a0->id)->update(['body' => rtrim((string) $a0->body) . self::transcriptText($i['transcript']), 'updated_at' => now()]);
                }
                DB::table($i['table'])->where('id', $i['id'])->update(['lead_activity_id' => (int) $own, 'lead_id' => $lead->id]);
                return true;
            }
        }
        // A dial-pad call from a lead already has its "Called … from the portal" entry: add to that one.
        if ($i['kind'] === 'outgoing') {
            $act = DB::table('sales_activities')->where('lead_id', $lead->id)->where('type', 'call')->where('body', 'like', 'Called %from the portal%')
                ->whereBetween('created_at', [Carbon::parse($i['created_at'])->subMinutes(1), Carbon::parse($i['created_at'])->addHours(3)])
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('sales_activity_files')->whereColumn('sales_activity_files.activity_id', 'sales_activities.id'))
                ->orderBy('id')->first(['id', 'body']);
        }
        if ($act) {
            DB::table('sales_activities')->where('id', $act->id)->update(['body' => $act->body . self::transcriptText($i['transcript']), 'updated_at' => now()]);
            $actId = (int) $act->id;
        } else {
            $actId = (int) DB::table('sales_activities')->insertGetId(['lead_id' => $lead->id, 'user_id' => $i['user_id'] ?? null, 'type' => 'call', 'done' => 1,
                'due_date' => Carbon::parse($i['created_at'])->tz('America/Toronto')->toDateString(),
                'body' => self::header($i) . self::transcriptText($i['transcript']), 'created_at' => $i['created_at'], 'updated_at' => now()]);
        }
        self::attachAudio($actId, (int) $lead->id, $i);
        $upd = ['lead_activity_id' => $actId];
        if ($i['table'] === 'voicemails') $upd['lead_id'] = $lead->id;
        DB::table($i['table'])->where('id', $i['id'])->update($upd);
        DB::table('sales_leads')->where('id', $lead->id)->update(['last_activity_at' => now()]);
        return true;
    }

    private static function attachAudio(int $actId, int $leadId, array $i): void
    {
        try {
            if (! $i['path'] || ! Storage::disk('local')->exists($i['path'])) return;
            $ext = pathinfo($i['path'], PATHINFO_EXTENSION) ?: 'mp3';
            $rel = 'sales-activity/' . $leadId . '/' . (string) \Illuminate\Support\Str::uuid() . '.' . $ext;
            Storage::disk('public')->put($rel, Storage::disk('local')->get($i['path']));
            $name = ($i['kind'] === 'voicemail' ? 'voicemail-' : 'call-') . $i['id'] . '.' . $ext;
            DB::table('sales_activity_files')->insert(['activity_id' => $actId, 'lead_id' => $leadId, 'kind' => 'audio', 'original_name' => $name,
                'file_url' => '/storage/' . $rel, 'mime' => $ext === 'wav' ? 'audio/wav' : 'audio/mpeg', 'size' => Storage::disk('public')->size($rel),
                'transcript_text' => $i['transcript'] ?: null, 'created_at' => now(), 'updated_at' => now()]);
        } catch (\Throwable $e) {
            Log::warning('CallRecords: audio not attached', ['item' => $i['table'] . '#' . $i['id'], 'error' => $e->getMessage()]);
        }
    }

    /** A transcript that arrived after the recording was filed: add it once. */
    private static function addTranscript(array $i): bool
    {
        if (! $i['transcript']) return false;
        $a = DB::table('sales_activities')->where('id', $i['activity_id'])->first(['id', 'body']);
        if (! $a) return false;
        $body = (string) $a->body;
        $at = strpos($body, "\nTranscript:\n");
        $new = ($at === false ? rtrim($body) : substr($body, 0, $at)) . self::transcriptText($i['transcript']);
        if ($new === $body) return false;   // already there, word for word
        DB::table('sales_activities')->where('id', $a->id)->update(['body' => $new, 'updated_at' => now()]);
        DB::table('sales_activity_files')->where('activity_id', $a->id)->where('kind', 'audio')->update(['transcript_text' => $i['transcript']]);
        return true;
    }
}
