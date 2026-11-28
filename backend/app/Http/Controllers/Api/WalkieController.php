<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Services\FcmService;
use App\Support\AgencyRole;
use App\Support\Audit;
use App\Support\Sensitive;
use App\Support\Walkie;
use App\Support\WalkiePush;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 📻 WALKIE-TALKIE (2026-11-27) - the API. How it works and who may talk to whom: App\Support\Walkie.
 * Every list and every write is inside the ACTIVE agency and its own staff (fails closed: an outsider, a parent or a
 * leaver gets 403/404). The audio never appears in a log, push, email or audit row; AuditActivity skips walkie/ and
 * this controller writes its own entries (who, which channel, how many people, how long).
 */
final class WalkieController extends Controller
{
    use ResolvesCentreContext;

    private const CHUNK_MAX = 12000;   // bytes of mu-law per piece (0.75 s at 16 kHz)

    private function ctx(Request $request): array
    {
        $aid = (int) ($this->resolveAgencyId($request) ?: 0);
        abort_unless($aid > 0, 400, 'Select an agency first.');
        $me = (int) $request->user()->id;
        // A super admin joins the walkie of the agency he is active in, and only that one (Walkie::guests, 2026-11-27).
        $guest = Walkie::enterAsGuest($aid, $me);
        abort_unless($guest || Walkie::isStaff($aid, $me), 403, 'The walkie-talkie is for your agency\'s own staff.');
        $roles = AgencyRole::names($request);

        return [$aid, $me, Walkie::who($aid, $me, $roles), in_array('agency_admin', $roles, true) || $guest];
    }

    private function needOn(int $aid): array
    {
        $s = Walkie::settings($aid);
        abort_unless($s['enabled'], 403, 'The walkie-talkie is switched off for your agency.');

        return $s;
    }

    /** A stored time (app timezone) as the agency's wall-clock time - every displayed time is in agency time. */
    private static function local($v, int $aid): ?string
    {
        if (! $v) return null;

        return \Carbon\Carbon::parse((string) $v, config('app.timezone'))->setTimezone(\App\Support\AgencyTime::tz($aid))->format('Y-m-d H:i:s');
    }

    private const ROLE_LABEL = ['agency_admin' => 'Admin', 'centre_director' => 'Director', 'educator' => 'Educator', 'home_visitor' => 'Home visitor'];

    /** GET walkie/me - channels, people, my settings, whether anyone is talking. */
    public function me(Request $request): JsonResponse
    {
        [$aid, $me, $who, $isAdmin] = $this->ctx($request);
        $s = Walkie::settings($aid);
        $channels = $s['enabled'] ? array_values(Walkie::channels($aid, $me, $who)) : [];
        $floor = DB::table('walkie_floor')->where('agency_id', $aid)->where('until', '>', now())->whereNotNull('speaker_user_id')->get()->keyBy('channel');
        foreach ($channels as &$c) {
            $f = $floor[$c['key']] ?? null;
            $c['talking'] = $f ? Walkie::firstName((int) $f->speaker_user_id) : null;
        }
        unset($c);
        $people = [];
        if ($s['enabled']) {
            $ids = Walkie::reach($aid, $me, $who);
            // Leak-guard canary people stay staff (kt:leak-probe walks these routes as them) but are not shown to real people.
            if (! \App\Support\LeakGuard::isCanaryUser($me)) $ids = array_values(array_filter($ids, fn ($i) => ! \App\Support\LeakGuard::isCanaryUser((int) $i)));
            $centres = DB::table('centres')->where('agency_id', $aid)->pluck('name', 'id');
            $guests = Walkie::guests($aid);
            $roles = [];
            foreach (DB::table('role_assignments')->whereIn('user_id', $ids ?: [0])->where('active', 1)->whereIn('role', Walkie::STAFF)
                ->where(fn ($w) => $w->where('agency_id', $aid)->orWhereIn('centre_id', $centres->keys()->all() ?: [0]))->get(['user_id', 'role', 'centre_id']) as $r) {
                $roles[(int) $r->user_id]['r'][$r->role] = true;
                if ($r->centre_id && isset($centres[$r->centre_id])) $roles[(int) $r->user_id]['c'][$centres[$r->centre_id]] = true;
            }
            foreach (DB::table('users')->whereIn('id', $ids ?: [0])->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'preferred_name', 'photo_url', 'sex']) as $u) {
                $rs = array_keys($roles[(int) $u->id]['r'] ?? []);
                usort($rs, fn ($a, $b) => array_search($a, Walkie::STAFF, true) <=> array_search($b, Walkie::STAFF, true));
                $key = Walkie::dmKey($me, (int) $u->id);
                $f = $floor[$key] ?? null;
                $people[] = ['id' => (int) $u->id, 'key' => $key, 'name' => trim((($u->preferred_name ?: $u->first_name) ?? '') . ' ' . ($u->last_name ?? '')),
                    'photo_url' => $u->photo_url, 'sex' => $u->sex, 'role' => in_array((int) $u->id, $guests, true) ? 'Super admin' : (self::ROLE_LABEL[$rs[0] ?? ''] ?? ''),
                    'centres' => array_keys($roles[(int) $u->id]['c'] ?? []), 'talking' => $f && (int) $f->speaker_user_id !== $me ? Walkie::firstName((int) $f->speaker_user_id) : null];
            }
        }
        $p = DB::table('walkie_prefs')->where('user_id', $me)->first();

        return response()->json([
            'enabled' => $s['enabled'], 'me' => ['id' => $me, 'kind' => $who['kind'], 'name' => Walkie::firstName($me)],
            'bell' => Walkie::bellPath($me), 'channels' => $channels, 'people' => $people,
            'prefs' => ['dnd' => Walkie::dnd($me), 'dnd_until' => $p && $p->dnd ? self::local($p->dnd_until, $aid) : null, 'sounds' => Walkie::sounds($me)],
            'settings' => $isAdmin ? $s : ['listen' => $s['listen'], 'retention_days' => $s['retention_days'], 'emergency' => $s['emergency'], 'emergency_name' => $s['emergency_name']],
            'can_admin' => $isAdmin, 'rate' => Walkie::SAMPLE_RATE, 'max_secs' => Walkie::MAX_SECS,
        ]);
    }

    /** POST walkie/talk {channel} - take the floor and start a transmission. 409 when someone else is talking. */
    public function start(Request $request): JsonResponse
    {
        [$aid, $me, $who] = $this->ctx($request);
        $s = $this->needOn($aid);
        $key = (string) $request->input('channel', '');
        $label = '';
        $to = null;
        $emergency = false;
        $centre = null;
        if (($o = Walkie::dmOther($key, $me)) !== null) {
            abort_unless(in_array($o, Walkie::reach($aid, $me, $who), true), 404, 'That person was not found.');
            $to = $o;
            $label = 'Direct';
        } else {
            $ch = Walkie::channels($aid, $me, $who)[$key] ?? null;
            abort_unless($ch, 404, 'That channel was not found.');
            abort_unless($ch['can_talk'], 403, $key === 'agency' ? 'Only admins can talk to all staff.' : 'You can listen to this channel but not talk on it.');
            $label = $ch['label'];
            $emergency = (bool) $ch['emergency'];
            $centre = $ch['centre_id'] ?? null;
        }

        $busy = null;
        $txId = DB::transaction(function () use ($aid, $key, $me, $label, $to, $emergency, $centre, &$busy) {
            DB::table('walkie_floor')->insertOrIgnore(['agency_id' => $aid, 'channel' => $key]);
            $f = DB::table('walkie_floor')->where('agency_id', $aid)->where('channel', $key)->lockForUpdate()->first();
            if ($f && $f->speaker_user_id && (int) $f->speaker_user_id !== $me && $f->until && strtotime((string) $f->until) > time()) {
                $busy = (int) $f->speaker_user_id;

                return 0;
            }
            $now = now();
            $id = (int) DB::table('walkie_transmissions')->insertGetId([
                'agency_id' => $aid, 'channel' => $key, 'channel_label' => $label, 'centre_id' => $centre, 'speaker_user_id' => $me,
                'to_user_id' => $to, 'emergency' => $emergency, 'status' => 'live', 'live_dir' => bin2hex(random_bytes(16)),
                'live_key' => Sensitive::seal(base64_encode(random_bytes(32)), 'text'), 'started_at' => $now, 'last_chunk_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('walkie_floor')->where('agency_id', $aid)->where('channel', $key)
                ->update(['transmission_id' => $id, 'speaker_user_id' => $me, 'until' => $now->copy()->addSeconds(Walkie::FLOOR_SECS)]);

            return $id;
        });
        if (! $txId) {
            return response()->json(['message' => Walkie::firstName((int) $busy) . ' is talking. Try again in a moment.', 'talking' => Walkie::firstName((int) $busy)], 409);
        }

        // Who hears it: the channel's people, less Do not disturb (and, when the agency chose it, people not clocked in).
        // The emergency channel always plays.
        $members = Walkie::members($aid, $key, $me);
        $onShift = $s['listen'] === 'on_shift' && ! $emergency ? Walkie::onShift($members) : null;
        $rows = [];
        $sent = [];
        foreach ($members as $u) {
            $st = 'sent';
            if (! $emergency && Walkie::dnd($u)) $st = 'dnd';
            elseif ($onShift !== null && ! in_array($u, $onShift, true)) $st = 'off_shift';
            $rows[] = ['transmission_id' => $txId, 'user_id' => $u, 'status' => $st];
            if ($st === 'sent') $sent[] = $u;
        }
        foreach (array_chunk($rows, 500) as $part) DB::table('walkie_recipients')->insert($part);
        DB::table('walkie_transmissions')->where('id', $txId)->update(['recipients' => count($sent)]);
        Walkie::ring($sent);
        $wakeLabel = $to ? 'Direct' : $label;
        if ($sent) {
            dispatch(function () use ($aid, $sent, $txId, $me, $wakeLabel, $emergency) {
                WalkiePush::wake($aid, $sent, $txId, $me, $wakeLabel, $emergency);
            })->afterResponse();
        }

        return response()->json(['id' => $txId, 'recipients' => count($sent), 'skipped' => count($members) - count($sent), 'emergency' => $emergency]);
    }

    private function myLive(int $id, int $me): object
    {
        $t = DB::table('walkie_transmissions')->where('id', $id)->where('speaker_user_id', $me)->first();
        abort_unless($t, 404, 'That talk was not found.');

        return $t;
    }

    /** POST walkie/talk/{id}/chunk {seq, data: base64 mu-law} */
    public function chunk(Request $request, int $id): JsonResponse
    {
        $me = (int) $request->user()->id;
        $t = $this->myLive($id, $me);
        if ($t->status !== 'live') return response()->json(['stop' => true, 'message' => 'This talk has ended.']);
        $seq = (int) $request->input('seq', -1);
        $raw = base64_decode((string) $request->input('data', ''), true);
        abort_unless($seq >= 0 && $seq < 400 && $raw !== false && $raw !== '' && strlen($raw) <= self::CHUNK_MAX, 422, 'That piece of audio could not be read.');
        $key = (string) Sensitive::open((string) $t->live_key);
        $ins = DB::table('walkie_chunks')->insertOrIgnore(['transmission_id' => $id, 'seq' => $seq, 'data' => Sensitive::seal(base64_encode($raw), 'text'), 'created_at' => now()]);
        if ($ins) Walkie::writeLive((string) $t->live_dir, $key, $seq, $raw);
        $now = now();
        DB::table('walkie_transmissions')->where('id', $id)->update(['chunks' => DB::raw('GREATEST(chunks, ' . ($seq + 1) . ')'), 'last_chunk_at' => $now]);
        DB::table('walkie_floor')->where('agency_id', $t->agency_id)->where('channel', $t->channel)->where('transmission_id', $id)
            ->update(['until' => $now->copy()->addSeconds(Walkie::FLOOR_SECS)]);
        $stop = strtotime((string) $t->started_at) + Walkie::MAX_SECS <= time();

        return response()->json(['ok' => true, 'stop' => $stop]);
    }

    /** POST walkie/talk/{id}/end {chunks} */
    public function end(Request $request, int $id): JsonResponse
    {
        $me = (int) $request->user()->id;
        $t = $this->myLive($id, $me);
        $want = max(0, min(400, (int) $request->input('chunks', 0)));
        // The last piece may still be on its way: wait for it, briefly.
        for ($i = 0; $i < 8 && $want > 0 && DB::table('walkie_chunks')->where('transmission_id', $id)->count() < $want; $i++) usleep(200000);
        $r = self::finish($id);

        return response()->json(['ok' => true] + $r);
    }

    /**
     * The talk is over (the speaker let go, or kt:walkie-sweep found it silent): fold the pieces into one sealed
     * recording, tell live listeners how many pieces there are, free the channel, audit it. Nothing said = no record.
     */
    public static function finish(int $id): array
    {
        $t = DB::table('walkie_transmissions')->where('id', $id)->first(['id', 'agency_id', 'channel', 'channel_label', 'speaker_user_id', 'to_user_id', 'status', 'live_dir', 'recipients', 'emergency', 'started_at']);
        if (! $t || $t->status !== 'live') return ['duration_ms' => 0];
        $raw = '';
        $n = 0;
        foreach (DB::table('walkie_chunks')->where('transmission_id', $id)->orderBy('seq')->get(['seq', 'data']) as $c) {
            $raw .= (string) base64_decode((string) Sensitive::open((string) $c->data));
            $n = max($n, (int) $c->seq + 1);
        }
        DB::table('walkie_floor')->where('agency_id', $t->agency_id)->where('channel', $t->channel)->where('transmission_id', $id)
            ->update(['speaker_user_id' => null, 'transmission_id' => null, 'until' => null]);
        Walkie::endLive((string) $t->live_dir, $n);
        if ($raw === '') {
            DB::table('walkie_recipients')->where('transmission_id', $id)->delete();
            DB::table('walkie_transmissions')->where('id', $id)->delete();
            DB::table('walkie_chunks')->where('transmission_id', $id)->delete();

            return ['duration_ms' => 0, 'kept' => false];
        }
        $ms = (int) round(strlen($raw) * 1000 / Walkie::SAMPLE_RATE);
        DB::table('walkie_transmissions')->where('id', $id)->update(['status' => 'done', 'ended_at' => now(), 'chunks' => $n, 'duration_ms' => $ms,
            'audio' => Sensitive::seal(base64_encode($raw), 'text'), 'updated_at' => now()]);
        DB::table('walkie_chunks')->where('transmission_id', $id)->delete();
        Audit::write(['user_id' => (int) $t->speaker_user_id, 'agency_id' => (int) $t->agency_id, 'action' => 'walkie.talk',
            'entity_type' => 'walkie_transmission', 'entity_id' => $id,
            'payload' => ['channel' => $t->to_user_id ? 'Direct to ' . Walkie::fullName((int) $t->to_user_id) : $t->channel_label,
                'people' => (int) $t->recipients, 'seconds' => round($ms / 1000, 1), 'emergency' => (bool) $t->emergency]]);

        return ['duration_ms' => $ms, 'kept' => true];
    }

    /** The live talks for a person (web inbox and the phone listener share this). */
    public static function liveFor(int $uid): array
    {
        $rows = DB::table('walkie_recipients as r')->join('walkie_transmissions as t', 't.id', '=', 'r.transmission_id')
            ->where('r.user_id', $uid)->where('r.status', 'sent')->where('t.started_at', '>=', now()->subSeconds(90))
            ->where(fn ($q) => $q->where('t.status', 'live')->orWhere('t.ended_at', '>=', now()->subSeconds(40)))
            ->orderBy('t.id')->limit(10)
            ->get(['t.id', 't.channel', 't.channel_label', 't.speaker_user_id', 't.to_user_id', 't.emergency', 't.status', 't.live_dir', 't.live_key', 't.chunks', 't.started_at']);
        $out = [];
        foreach ($rows as $t) {
            $out[] = ['id' => (int) $t->id, 'from' => Walkie::firstName((int) $t->speaker_user_id), 'from_id' => (int) $t->speaker_user_id,
                'label' => $t->to_user_id ? 'Direct' : (string) $t->channel_label, 'channel' => $t->to_user_id ? Walkie::dmKey($uid, (int) $t->speaker_user_id) : (string) $t->channel,
                'emergency' => (bool) $t->emergency, 'done' => $t->status !== 'live', 'chunks' => (int) $t->chunks,
                'live' => '/wt/' . $t->live_dir . '/', 'key' => (string) Sensitive::open((string) $t->live_key), 'started_at' => (string) $t->started_at];
        }

        return $out;
    }

    /** GET walkie/inbox - live talks to me, and quick replies to my own talks. */
    public function inbox(Request $request): JsonResponse
    {
        [$aid, $me] = $this->ctx($request);
        $replies = DB::table('walkie_recipients as r')->join('walkie_transmissions as t', 't.id', '=', 'r.transmission_id')
            ->where('t.speaker_user_id', $me)->where('t.agency_id', $aid)->where('r.status', 'busy')->where('r.replied_at', '>=', now()->subMinutes(2))
            ->get(['r.user_id', 'r.replied_at', 't.id'])->map(fn ($r) => ['tx' => (int) $r->id, 'from' => Walkie::firstName((int) $r->user_id), 'at' => self::local($r->replied_at, $aid)])->values()->all();

        return response()->json(['live' => array_values(array_filter(self::liveFor($me), fn ($x) => true)), 'replies' => $replies]);
    }

    /** POST walkie/tx/{id}/heard {busy} - played it (or: "Busy, can't talk"). */
    public function heard(Request $request, int $id): JsonResponse
    {
        $me = (int) $request->user()->id;

        return response()->json(self::markHeard($id, $me, (bool) $request->boolean('busy')));
    }

    public static function markHeard(int $id, int $me, bool $busy): array
    {
        $r = DB::table('walkie_recipients')->where('transmission_id', $id)->where('user_id', $me)->first();
        abort_unless($r, 404, 'That talk was not found.');
        $now = now();
        DB::table('walkie_recipients')->where('id', $r->id)->update(['status' => $busy ? 'busy' : ($r->status === 'busy' ? 'busy' : 'heard'),
            'heard_at' => $r->heard_at ?: $now, 'replied_at' => $busy ? $now : $r->replied_at]);
        if ($busy && $r->status !== 'busy') {
            $t = DB::table('walkie_transmissions')->where('id', $id)->first(['speaker_user_id', 'agency_id']);
            if ($t) {
                Walkie::ring([(int) $t->speaker_user_id]);
                $name = Walkie::firstName($me);
                rescue(fn () => app(FcmService::class)->sendToUser((int) $t->speaker_user_id, '📻 ' . $name . ' can\'t talk right now', 'Walkie-talkie reply.', '#walkie'), null, false);
                Audit::write(['user_id' => $me, 'agency_id' => (int) $t->agency_id, 'action' => 'walkie.busy', 'entity_type' => 'walkie_transmission', 'entity_id' => $id,
                    'payload' => ['to' => Walkie::fullName((int) $t->speaker_user_id)]]);
            }
        }

        return ['ok' => true];
    }

    /** GET walkie/history?channel= - talks I gave or was sent, newest first (within the agency's retention). */
    public function history(Request $request): JsonResponse
    {
        [$aid, $me] = $this->ctx($request);
        $q = DB::table('walkie_transmissions as t')->where('t.agency_id', $aid)->where('t.status', 'done')
            ->where(fn ($w) => $w->where('t.speaker_user_id', $me)->orWhereExists(fn ($x) => $x->from('walkie_recipients as r')
                ->whereColumn('r.transmission_id', 't.id')->where('r.user_id', $me)));
        $ch = (string) $request->query('channel', '');
        if ($ch !== '') $q->where('t.channel', $ch);
        $rows = $q->orderByDesc('t.id')->limit(150)->get(['t.id', 't.channel', 't.channel_label', 't.speaker_user_id', 't.to_user_id', 't.emergency', 't.duration_ms', 't.recipients', 't.started_at']);
        $ids = $rows->pluck('id')->all();
        $mine = DB::table('walkie_recipients')->whereIn('transmission_id', $ids ?: [0])->where('user_id', $me)->pluck('status', 'transmission_id');
        $stats = DB::table('walkie_recipients')->whereIn('transmission_id', $ids ?: [0])->select('transmission_id', 'status', DB::raw('count(*) n'))
            ->groupBy('transmission_id', 'status')->get()->groupBy('transmission_id');
        $out = [];
        foreach ($rows as $t) {
            $isMine = (int) $t->speaker_user_id === $me;
            $st = [];
            foreach ($stats[$t->id] ?? [] as $s) $st[$s->status] = (int) $s->n;
            $out[] = ['id' => (int) $t->id, 'channel' => $t->to_user_id ? Walkie::dmKey((int) $t->speaker_user_id, (int) $t->to_user_id) : $t->channel,
                'label' => $t->to_user_id ? ($isMine ? 'To ' . Walkie::fullName((int) $t->to_user_id) : 'To you') : $t->channel_label,
                'from' => $isMine ? 'You' : Walkie::fullName((int) $t->speaker_user_id), 'mine' => $isMine, 'emergency' => (bool) $t->emergency,
                'seconds' => round((int) $t->duration_ms / 1000, 1), 'at' => self::local($t->started_at, $aid), 'my_status' => $mine[$t->id] ?? null,
                'heard' => $isMine ? (($st['heard'] ?? 0) + ($st['busy'] ?? 0)) : null, 'busy' => $isMine ? ($st['busy'] ?? 0) : null,
                'sent' => $isMine ? (int) $t->recipients : null];
        }

        return response()->json(['items' => $out, 'retention_days' => Walkie::settings($aid)['retention_days']]);
    }

    /** GET walkie/tx/{id}/audio - the recording, for its speaker and the people it was sent to. Audited. */
    public function audio(Request $request, int $id): JsonResponse
    {
        [$aid, $me] = $this->ctx($request);
        $t = DB::table('walkie_transmissions')->where('id', $id)->where('agency_id', $aid)->where('status', 'done')->first(['id', 'speaker_user_id', 'audio', 'channel_label', 'to_user_id']);
        abort_unless($t && ((int) $t->speaker_user_id === $me || DB::table('walkie_recipients')->where('transmission_id', $id)->where('user_id', $me)->exists()), 404, 'That recording was not found.');
        if ((int) $t->speaker_user_id !== $me) {
            DB::table('walkie_recipients')->where('transmission_id', $id)->where('user_id', $me)->whereNull('heard_at')->update(['heard_at' => now(), 'status' => DB::raw("IF(status='busy','busy','heard')")]);
        }
        Audit::write(['user_id' => $me, 'agency_id' => $aid, 'action' => 'walkie.listen', 'entity_type' => 'walkie_transmission', 'entity_id' => $id,
            'payload' => ['from' => Walkie::fullName((int) $t->speaker_user_id)]]);

        return response()->json(['rate' => Walkie::SAMPLE_RATE, 'codec' => 'mulaw', 'data' => (string) Sensitive::open((string) $t->audio)]);
    }

    /**
     * PUT walkie/prefs {dnd, minutes} and/or {sounds} - my own Do not disturb (minutes empty = until I turn it off) and
     * Walkie sounds (the radio beeps, 2026-11-27). Each is saved only when sent. Shown in My settings › Notifications.
     */
    public function prefs(Request $request): JsonResponse
    {
        [$aid, $me] = $this->ctx($request);
        $set = ['updated_at' => now()];
        if ($request->has('dnd')) {
            $on = $request->boolean('dnd');
            $min = (int) $request->input('minutes', 0);
            $until = $on && $min > 0 ? now()->addMinutes(min($min, 24 * 60)) : null;
            $set += ['dnd' => $on, 'dnd_until' => $until];
            Audit::write(['user_id' => $me, 'agency_id' => $aid, 'action' => 'walkie.dnd', 'entity_type' => 'user', 'entity_id' => $me,
                'payload' => ['do_not_disturb' => $on, 'until' => $until ? (string) $until : null]]);
        }
        if ($request->has('sounds')) {
            $set['sounds'] = $request->boolean('sounds');
            Audit::write(['user_id' => $me, 'agency_id' => $aid, 'action' => 'walkie.sounds', 'entity_type' => 'user', 'entity_id' => $me,
                'payload' => ['walkie_sounds' => $set['sounds']]]);
        }
        if (! DB::table('walkie_prefs')->where('user_id', $me)->exists()) $set['created_at'] = now();
        DB::table('walkie_prefs')->updateOrInsert(['user_id' => $me], $set);
        $p = DB::table('walkie_prefs')->where('user_id', $me)->first();

        return response()->json(['dnd' => Walkie::dnd($me), 'dnd_until' => $p && $p->dnd && $p->dnd_until ? self::local($p->dnd_until, $aid) : null, 'sounds' => Walkie::sounds($me)]);
    }

    /** PUT walkie/settings - agency admins: on/off, listening, retention, emergency channel. */
    public function settings(Request $request): JsonResponse
    {
        [$aid, $me, , $isAdmin] = $this->ctx($request);
        abort_unless($isAdmin, 403, 'Only an agency admin can change this.');
        $before = Walkie::settings($aid);
        $after = Walkie::saveSettings($aid, $request->only(['enabled', 'listen', 'retention_days', 'emergency', 'emergency_name', 'emergency_talkers', 'educators_agency']));
        Audit::write(['user_id' => $me, 'agency_id' => $aid, 'action' => 'walkie.settings', 'entity_type' => 'agency', 'entity_id' => $aid,
            'payload' => ['before' => $before, 'after' => $after]]);

        return response()->json(['settings' => $after]);
    }

    // ---- the phone's listener (Android app 3.13+) -------------------------------------------------------------------

    /** POST walkie/device {push_token, platform, app_build} - this phone listens; returns its secret (shown once). */
    public function device(Request $request): JsonResponse
    {
        [$aid, $me] = $this->ctx($request);
        $tok = mb_substr(trim((string) $request->input('push_token', '')), 0, 2048);
        abort_unless($tok !== '', 422, 'The phone did not give a push token.');
        $platform = in_array($request->input('platform'), ['android', 'ios'], true) ? (string) $request->input('platform') : 'android';
        $secret = Str::random(48);
        DB::table('walkie_devices')->where('push_token', $tok)->delete();
        DB::table('walkie_devices')->insert(['user_id' => $me, 'platform' => $platform, 'secret_hash' => hash('sha256', $secret), 'push_token' => $tok,
            'app_build' => mb_substr((string) $request->input('app_build', ''), 0, 32) ?: null, 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        Audit::write(['user_id' => $me, 'agency_id' => $aid, 'action' => 'walkie.device', 'entity_type' => 'user', 'entity_id' => $me, 'payload' => ['platform' => $platform, 'listening' => true]]);

        return response()->json(['secret' => $secret]);
    }

    /** POST walkie/device/remove {push_token} - stop listening on this phone. */
    public function deviceRemove(Request $request): JsonResponse
    {
        $me = (int) $request->user()->id;
        $n = DB::table('walkie_devices')->where('user_id', $me)->where('push_token', (string) $request->input('push_token', ''))->delete();

        return response()->json(['removed' => $n]);
    }

    private static function deviceUser(Request $request): object
    {
        $s = (string) $request->header('X-KT-Walkie', '');
        $d = strlen($s) >= 32 ? DB::table('walkie_devices')->where('secret_hash', hash('sha256', $s))->first() : null;
        abort_unless($d, 401, 'This phone is not signed up for the walkie-talkie.');
        $u = DB::table('users')->where('id', $d->user_id)->whereNull('deleted_at')->first(['id', 'status']);
        abort_unless($u && $u->status !== 'deactivated', 401, 'This account is closed.');
        DB::table('walkie_devices')->where('id', $d->id)->update(['last_seen_at' => now()]);

        return $d;
    }

    /** GET walkie/device/wake (header X-KT-Walkie) - the phone's listener: live talks for its person, with keys. */
    public function wake(Request $request): JsonResponse
    {
        $d = self::deviceUser($request);

        return response()->json(['live' => self::liveFor((int) $d->user_id), 'dnd' => Walkie::dnd((int) $d->user_id), 'sounds' => Walkie::sounds((int) $d->user_id)]);
    }

    /** POST walkie/device/heard {tx, busy} (header X-KT-Walkie) */
    public function wakeHeard(Request $request): JsonResponse
    {
        $d = self::deviceUser($request);

        return response()->json(self::markHeard((int) $request->input('tx', 0), (int) $d->user_id, (bool) $request->boolean('busy')));
    }
}
