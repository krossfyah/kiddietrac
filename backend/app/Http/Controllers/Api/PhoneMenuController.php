<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\PhoneMenu;
use App\Support\PlatformSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The toll-free line's call menu (2026-10-22). Settings for platform admins, plus the
 * TeXML steps Telnyx calls during a call. See App\Support\PhoneMenu for the flow.
 */
class PhoneMenuController extends Controller
{
    /* ── settings (Platform → Phone & voicemail → Call menu) ─────────────────── */

    /** GET /platform/phone/menu */
    public function show(): JsonResponse
    {
        $c = PhoneMenu::config();
        $people = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
            ->whereIn('ra.role', ['platform_admin', 'sales_rep'])->where('ra.active', 1)->whereNull('u.deleted_at')
            ->select('u.id', 'u.first_name', 'u.last_name', 'u.email', 'u.phone')->distinct()->orderBy('u.first_name')->get();

        return response()->json([
            'menu' => $c,
            'prompt' => PhoneMenu::prompt($c),
            'open_now' => PhoneMenu::open($c),
            'people' => $people,
            'number' => PhoneLineController::NUMBER,
        ]);
    }

    /** PUT /platform/phone/menu — saves, then republishes the hosted greeting script. */
    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'intro' => ['nullable', 'string', 'max:400'],
            'invalid' => ['nullable', 'string', 'max:200'],
            'connecting' => ['nullable', 'string', 'max:200'],
            'hours' => ['required', 'array'],
            'hours.days' => ['array'],
            'hours.open' => ['required', 'regex:/^\d{2}:\d{2}$/'],
            'hours.close' => ['required', 'regex:/^\d{2}:\d{2}$/'],
            'hours.tz' => ['nullable', 'string', 'max:60'],
            'departments' => ['required', 'array', 'max:9'],
            'departments.*.key' => ['required', 'regex:/^[1-9]$/'],
            'departments.*.name' => ['required', 'string', 'max:40'],
            'departments.*.numbers' => ['array', 'max:10'],
            'departments.*.numbers.*' => ['string', 'max:30'],
            'departments.*.emails' => ['array', 'max:5'],
            'departments.*.emails.*' => ['email'],
            'departments.*.rings' => ['integer', 'min:1', 'max:10'],
            'departments.*.greeting' => ['nullable', 'string', 'max:600'],
            'departments.*.alert_user_ids' => ['array'],
        ]);
        $keys = array_column($data['departments'], 'key');
        if (count($keys) !== count(array_unique($keys))) {
            return response()->json(['message' => 'Two departments use the same key. Give each its own number.'], 422);
        }
        foreach ($data['departments'] as $i => $d) {
            foreach ((array) ($d['numbers'] ?? []) as $n) {
                if (\App\Support\Telnyx::e164((string) $n) === '') {
                    return response()->json(['message' => '"' . $n . '" (' . $d['name'] . ') is not a phone number we can dial. Use 10 digits, e.g. 416 555 0123.'], 422);
                }
            }
        }

        $before = PlatformSettings::get(PhoneMenu::KEY);
        $after = PhoneMenu::save($request->all());
        // The hosted greeting is what Telnyx plays first; it must match the menu.
        $err = PhoneLineController::publish(PhoneLineController::config());
        if ($err !== null) {
            PlatformSettings::set(PhoneMenu::KEY, $before ?? json_encode(PhoneMenu::DEFAULTS));
            return response()->json(['message' => $err . ' The menu was not changed.'], 502);
        }
        // Portal ringing needs the Telnyx connection; set it up the first time it is asked for.
        $portalProblem = null;
        if (array_filter($after['departments'], fn ($d) => $d['enabled'] && $d['ring_portal'])) {
            [, $portalProblem] = \App\Support\PortalPhone::connection();
        }
        try {
            \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => null, 'action' => 'voice.menu_saved',
                'entity_type' => 'phone_line', 'entity_id' => null,
                'payload' => json_encode(['summary' => 'Call menu ' . ($after['enabled'] ? 'ON' : 'off') . ': '
                    . implode(', ', array_map(fn ($d) => $d['key'] . ' ' . $d['name'] . ' (' . count($d['numbers']) . ' phone' . (count($d['numbers']) === 1 ? '' : 's') . ')', $after['departments']))]),
                'created_at' => now()]);
        } catch (\Throwable $e) {
        }

        $out = $this->show();
        if ($portalProblem) {
            $out->setData($out->getData(true) + ['portal_problem' => $portalProblem . ' Calls still ring the phones; try saving again later for the portal.']);
        }
        return $out;
    }

    /** GET /platform/phone/webrtc/caller — the caller now ringing through the menu (last 90 s). */
    public function lastCaller(Request $request): JsonResponse
    {
        $c = \Illuminate\Support\Facades\Cache::get('kt-ivr-last-caller');
        if (! is_array($c) || time() - (int) ($c['at'] ?? 0) > 90) {
            return response()->json(['caller' => null]);
        }
        /* ONLY THE PEOPLE THAT DEPARTMENT RINGS (2026-11-06 role sweep). This answered any signed-in account - a
           parent included - with the last caller's number for 90 s after every call. */
        $uid = (int) $request->user()->id;
        $rung = false;
        foreach (PhoneMenu::config()['departments'] as $d) {
            if (($d['name'] ?? null) === $c['dept'] && in_array($uid, (array) ($d['alert_user_ids'] ?? []), true)) {
                $rung = true;
                break;
            }
        }
        if (! $rung && ! in_array('platform_admin', \App\Support\UserRoles::names($request), true)) {
            return response()->json(['caller' => null]);
        }
        return response()->json(['caller' => $c['from'], 'department' => $c['dept']]);
    }

    /**
     * GET /platform/phone/webrtc — for the portal of a signed-in staff member: whether any
     * department rings them in the portal, and if so a 24-hour Telnyx login token.
     */
    public function webrtc(Request $request): JsonResponse
    {
        $uid = (int) $request->user()->id;
        $c = PhoneMenu::config();
        $depts = [];
        if ($c['enabled']) {
            foreach ($c['departments'] as $d) {
                if ($d['enabled'] && $d['ring_portal'] && in_array($uid, $d['alert_user_ids'], true)) {
                    $depts[] = $d['name'];
                }
            }
        }
        if (! $depts) {
            return response()->json(['enabled' => false]);
        }
        $token = \App\Support\PortalPhone::token($uid);
        if (! $token) {
            return response()->json(['enabled' => false, 'problem' => 'Portal ringing could not be set up with Telnyx just now.']);
        }

        return response()->json(['enabled' => true, 'token' => $token, 'departments' => $depts, 'ttl' => 86400]);
    }

    /* ── during a call (Telnyx → us), protected by the voicemail token ───────── */

    private function ok(string $token): bool
    {
        $expected = (string) PlatformSettings::get('voice.voicemail_token', '');

        return $expected !== '' && hash_equals($expected, $token);
    }

    private function xml(string $inner): Response
    {
        return new Response(PhoneMenu::xml($inner), 200, ['Content-Type' => 'text/xml']);
    }

    /** POST /voice/ivr/{token}/choose — the key the caller pressed. */
    public function choose(Request $request, string $token): Response
    {
        if (! $this->ok($token)) {
            return new Response('', 404);
        }
        $c = PhoneMenu::config();
        $line = PhoneLineController::config();
        $digit = substr(preg_replace('/\D/', '', (string) ($request->input('Digits') ?? $request->input('digits') ?? '')), 0, 1);
        $d = $digit !== '' ? PhoneMenu::find($c, $digit) : null;

        if (! $d) {
            $this->audit($request, 'Phone menu: caller ' . $this->caller($request) . ' pressed ' . ($digit !== '' ? $digit : 'nothing') . ' (not an option) — asked again');
            // A wrong key: say so, ask once more, then the general voicemail.
            return $this->xml(PhoneLineController::say($c['invalid'], $line['voice'])
                . PhoneMenu::gather($c, $line['voice'], false)
                . PhoneLineController::voicemailBlock($line));
        }

        // Phones (screened: press 1) and, stage 2, the portal of the department's people who
        // have it open (App\Support\PortalPhone) — all at the same time; first to answer wins.
        $phones = $d['ring_phones'] ? $d['numbers'] : [];
        $sips = $d['ring_portal'] ? \App\Support\PortalPhone::sipTargets($d['alert_user_ids']) : [];
        $ring = PhoneMenu::open($c) && ($phones || $sips);
        $what = [];
        if ($phones) $what[] = count($phones) . ' phone' . (count($phones) === 1 ? '' : 's');
        if ($sips) $what[] = count($sips) . ' portal' . (count($sips) === 1 ? '' : 's');
        $this->audit($request, 'Phone menu: caller ' . $this->caller($request) . ' chose ' . $d['name'] . ' — '
            . ($ring ? 'ringing ' . implode(' and ', $what) : (PhoneMenu::open($c) ? 'nothing to ring, sent to voicemail' : 'after hours, sent to voicemail')));
        /* THE APP RINGS TOO (2026-10-23, Anthony: "native ringing in the app"). The
           department's people with the Android app get a ringing push; tapping it opens the
           app, which takes the call (answer()). While that can happen the caller is held
           rather than sent to voicemail. */
        $mobiles = (PhoneMenu::open($c) && $d['ring_portal']) ? $this->ringMobiles($request, $d) : 0;
        if ($mobiles) $what[] = $mobiles . ' app' . ($mobiles === 1 ? '' : 's');
        $held = ! $ring && $mobiles > 0;
        // The dialer's Missed calls list (App\Support\InboundCalls).
        \App\Support\InboundCalls::note((string) $request->input('CallSid', ''), (string) $request->input('From', ''), $d['name'], ($ring || $held) ? 'ringing' : 'missed');
        if ($held) {
            $this->audit($request, 'Phone menu: caller ' . $this->caller($request) . ' chose ' . $d['name'] . ' — ringing ' . $mobiles . ' app' . ($mobiles === 1 ? '' : 's') . ', caller on hold');
            return $this->xml($this->holdXml($d, $line));
        }
        if ($ring) {
            // Who is calling, for the portal's ringing card (the call itself carries our number).
            \Illuminate\Support\Facades\Cache::put('kt-ivr-last-caller', ['from' => $this->caller($request), 'dept' => $d['name'], 'at' => time()], 120);
            $numbers = '';
            foreach ($phones as $n) {
                $numbers .= '<Number url="' . PhoneMenu::url('screen', ['dept' => $d['key']]) . '" method="POST">' . htmlspecialchars($n, ENT_XML1, 'UTF-8') . '</Number>';
            }
            foreach ($sips as $sip) {
                $numbers .= '<Sip>' . htmlspecialchars($sip, ENT_XML1, 'UTF-8') . '</Sip>';
            }

            /* RECORDED, WITH NOTICE (2026-10-23, Anthony: "yes, record with notice"). The caller
               hears it before the ringing; the recording comes to /voice/ivr/{token}/recorded. */
            $rec = (string) \App\Support\PlatformSettings::get('voice.inbound.record', '1') === '1';
            return $this->xml(PhoneLineController::say(str_replace('{dept}', $d['name'], $c['connecting']), $line['voice'])
                . ($rec ? PhoneLineController::say('This call may be recorded for quality and training.', $line['voice']) : '')
                . '<Dial timeout="' . ($d['rings'] * PhoneMenu::SECONDS_PER_RING) . '" callerId="+18554009996" action="' . PhoneMenu::url('dialed', ['dept' => $d['key']]) . '" method="POST"'
                . ($rec ? ' record="record-from-answer-dual" recordingStatusCallback="' . PhoneMenu::url('recorded') . '" recordingStatusCallbackMethod="POST"' : '') . '>'
                . $numbers . '</Dial>');
        }

        return $this->xml(PhoneMenu::voicemail($d, $line));
    }

    /** POST /voice/ivr/{token}/screen?dept= — what a staff phone hears when it picks up. */
    public function screen(Request $request, string $token): Response
    {
        if (! $this->ok($token)) {
            return new Response('', 404);
        }
        $d = PhoneMenu::find(PhoneMenu::config(), (string) $request->query('dept', ''));
        $name = $d ? $d['name'] : 'KiddieTrac';
        $from = preg_replace('/\D/', '', (string) $request->input('From', ''));
        $spoken = $from !== '' ? ' from ' . implode(' ', str_split(substr($from, -10))) : '';

        return $this->xml('<Gather action="' . PhoneMenu::url('screen-ok', ['dept' => $request->query('dept', '')]) . '" method="POST" numDigits="1" timeout="8">'
            . PhoneLineController::say('KiddieTrac call for ' . $name . $spoken . '. Press 1 to accept.', PhoneLineController::config()['voice'])
            . '</Gather><Hangup/>');
    }

    /** POST /voice/ivr/{token}/screen-ok — 1 connects; anything else (or a voicemail) hangs up this leg. */
    public function screenOk(Request $request, string $token): Response
    {
        if (! $this->ok($token)) {
            return new Response('', 404);
        }
        $digit = substr(preg_replace('/\D/', '', (string) $request->input('Digits', '')), 0, 1);

        return $digit === '1' ? $this->xml('') : $this->xml('<Hangup/>');
    }

    /** POST /voice/ivr/{token}/dialed?dept= — nobody accepted: the department's voicemail. */
    public function dialed(Request $request, string $token): Response
    {
        if (! $this->ok($token)) {
            return new Response('', 404);
        }
        $status = strtolower((string) ($request->input('DialCallStatus') ?? $request->input('dial_call_status') ?? ''));
        $d = PhoneMenu::find(PhoneMenu::config(), (string) $request->query('dept', ''));
        $name = $d ? $d['name'] : 'Phone menu';
        $answered = in_array($status, ['completed', 'answered'], true);
        $sid = (string) $request->input('CallSid', '');
        if (! $answered && $d && ! $request->query('final') && $this->appRingPending($sid)) {
            $this->audit($request, $name . ': phones/portal did not answer ' . $this->caller($request) . ' — holding for the app');
            return $this->xml($this->holdXml($d, PhoneLineController::config()));
        }
        \App\Support\InboundCalls::note((string) $request->input('CallSid', ''), (string) $request->input('From', ''), $d ? $d['name'] : null, $answered ? 'answered' : 'missed');
        if ($answered) {
            $this->audit($request, $name . ': call from ' . $this->caller($request) . ' was answered');
            return $this->xml('<Hangup/>');
        }
        $this->audit($request, $name . ': nobody accepted the call from ' . $this->caller($request) . ' (' . ($status ?: 'no answer') . ') — sent to voicemail');
        $line = PhoneLineController::config();

        return $this->xml($d ? PhoneMenu::voicemail($d, $line) : PhoneLineController::voicemailBlock($line));
    }

    /** Android app users of this department (the alert people with the app installed). */
    private function mobileTargets(array $d): array
    {
        $ids = array_map('intval', $d['alert_user_ids'] ?? []);
        if (! $ids) return [];
        return DB::table('device_tokens')->whereIn('user_id', $ids)->where('platform', 'android')
            ->where(function ($q) { $q->whereNull('last_active_at')->orWhere('last_active_at', '>=', now()->subDays(60)); })
            ->distinct()->pluck('user_id')->map(fn ($v) => (int) $v)->all();
    }

    /** Ring the department's apps (a loud 30-second ringing push). Returns how many people. */
    private function ringMobiles(Request $request, array $d): int
    {
        $sid = (string) $request->input('CallSid', '');
        $users = $this->mobileTargets($d);
        if ($sid === '' || ! $users) return 0;
        $tok = \Illuminate\Support\Str::random(24);
        $from = (string) $request->input('From', '');
        \Illuminate\Support\Facades\Cache::put('kt-mring:' . $sid, ['users' => $users, 'dept' => $d['key'], 'dept_name' => $d['name'], 'from' => $from, 'at' => time()], 180);
        \Illuminate\Support\Facades\Cache::put('kt-mring-t:' . $tok, $sid, 180);
        $name = \App\Support\CallAlerts::nameFor($from);
        $pretty = $from ? \App\Support\Dialer::pretty($from) : 'Unknown caller';
        $title = '📞 Incoming call · ' . $d['name'];
        $body = ($name ? $name . ' · ' . $pretty : $pretty) . ' — tap to answer';
        foreach ($users as $uid) {
            try {
                app(\App\Services\FcmService::class)->sendToUser($uid, $title, $body, '#phone?answer=' . $tok, true, true, false,
                    ['kt_ring_secs' => '30', 'kt_call' => '1']);
            } catch (\Throwable $e) {
                Log::warning('App ring failed', ['user' => $uid, 'error' => $e->getMessage()]);
            }
        }
        return count($users);
    }

    /** An app ring that has not been taken and is young enough to still be answered. */
    private function appRingPending(string $sid): bool
    {
        if ($sid === '') return false;
        $r = \Illuminate\Support\Facades\Cache::get('kt-mring:' . $sid);
        return is_array($r) && time() - (int) $r['at'] < 40 && ! \Illuminate\Support\Facades\Cache::has('kt-mring-claim:' . $sid);
    }

    /** ~35 seconds of "please hold" while an app answers, then the department's voicemail. */
    private function holdXml(array $d, array $line): string
    {
        $v = $line['voice'];
        return PhoneLineController::say('Please hold while we connect you.', $v)
            . '<Pause length="12"/>' . PhoneLineController::say('Still connecting you. Please hold.', $v)
            . '<Pause length="12"/>' . PhoneLineController::say('Thank you for holding.', $v)
            . '<Pause length="8"/>'
            . '<Redirect method="POST">' . PhoneMenu::url('dialed', ['dept' => $d['key'], 'final' => 1]) . '</Redirect>';
    }

    /** POST /voice/ivr/{token}/connect?dept=&user= — the held caller, put through to the app that took it. */
    public function connect(Request $request, string $token): Response
    {
        if (! $this->ok($token)) {
            return new Response('', 404);
        }
        $d = PhoneMenu::find(PhoneMenu::config(), (string) $request->query('dept', ''));
        $sips = \App\Support\PortalPhone::sipTargets([(int) $request->query('user', 0)]);
        if (! $d || ! $sips) {
            return $this->xml($d ? PhoneMenu::voicemail($d, PhoneLineController::config()) : PhoneLineController::voicemailBlock(PhoneLineController::config()));
        }
        $this->audit($request, $d['name'] . ': ' . $this->caller($request) . ' put through to the app');
        $rec = (string) \App\Support\PlatformSettings::get('voice.inbound.record', '1') === '1';
        return $this->xml(($rec ? PhoneLineController::say('This call may be recorded for quality and training.', PhoneLineController::config()['voice']) : '')
            . '<Dial timeout="25" callerId="+18554009996" action="' . PhoneMenu::url('dialed', ['dept' => $d['key'], 'final' => 1]) . '" method="POST"'
            . ($rec ? ' record="record-from-answer-dual" recordingStatusCallback="' . PhoneMenu::url('recorded') . '" recordingStatusCallbackMethod="POST"' : '') . '>'
            . '<Sip>' . htmlspecialchars($sips[0], ENT_XML1, 'UTF-8') . '</Sip></Dial>');
    }

    /**
     * POST /voice/ivr/{token}/recorded — Telnyx's recording of an answered incoming call. The
     * link expires, so the audio is fetched now and kept privately with the call; App\Support\
     * CallRecords transcribes it and files it on the caller's lead.
     */
    public function recorded(Request $request, string $token): Response
    {
        if (! $this->ok($token)) {
            return new Response('', 404);
        }
        $url = (string) $request->input('RecordingUrl', '');
        $sid = (string) $request->input('CallSid', '');
        if ($url === '' || $sid === '' || ! preg_match('#^https://#', $url)) {
            return new Response('', 204);
        }
        try {
            \App\Support\InboundCalls::note($sid, (string) $request->input('From', ''), null, 'answered');
            $row = \Illuminate\Support\Facades\DB::table('inbound_calls')->where('call_sid', $sid)->first(['id', 'recording_path']);
            if ($row && ! $row->recording_path) {
                $r = \Illuminate\Support\Facades\Http::timeout(60)->get($url);
                if (! $r->successful() || strlen($r->body()) < 200) {
                    $r = \Illuminate\Support\Facades\Http::withToken(\App\Support\Telnyx::apiKey(2))->timeout(60)->get($url);
                }
                if ($r->successful() && strlen($r->body()) >= 200) {
                    $ext = str_contains(strtolower((string) $r->header('Content-Type')), 'wav') ? 'wav' : 'mp3';
                    $path = 'call-recordings/' . date('Y/m') . '/in-' . $row->id . '-' . \Illuminate\Support\Str::random(16) . '.' . $ext;
                    \Illuminate\Support\Facades\Storage::disk('local')->put($path, $r->body());
                    \Illuminate\Support\Facades\DB::table('inbound_calls')->where('id', $row->id)->update(['recording_path' => $path,
                        'recording_sid' => mb_substr((string) $request->input('RecordingSid', ''), 0, 120) ?: null,
                        'recording_seconds' => (int) $request->input('RecordingDuration', 0) ?: null, 'updated_at' => now()]);
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Inbound call recording not kept', ['call' => $sid, 'error' => $e->getMessage()]);
        }
        return new Response('', 204);
    }

    /**
     * POST /voice/phone/answer {token} — the app (opened from the ringing push) is ready:
     * move the held caller to this person's portal phone. First to answer wins.
     */
    public function answer(Request $request): \Illuminate\Http\JsonResponse
    {
        $uid = (int) $request->user()->id;
        $tok = (string) $request->validate(['token' => ['required', 'string', 'max:64']])['token'];
        $sid = (string) \Illuminate\Support\Facades\Cache::get('kt-mring-t:' . $tok, '');
        $r = $sid !== '' ? \Illuminate\Support\Facades\Cache::get('kt-mring:' . $sid) : null;
        if (! is_array($r) || ! in_array($uid, $r['users'], true)) {
            return response()->json(['ok' => false, 'reason' => 'gone', 'message' => 'That call has ended.'], 410);
        }
        if (! \Illuminate\Support\Facades\Cache::add('kt-mring-claim:' . $sid, $uid, 180)) {
            return response()->json(['ok' => false, 'reason' => 'taken', 'message' => 'Someone else answered this call.'], 409);
        }
        $url = html_entity_decode(PhoneMenu::url('connect', ['dept' => $r['dept'], 'user' => $uid]), ENT_QUOTES | ENT_XML1);
        try {
            $res = \Illuminate\Support\Facades\Http::withToken(\App\Support\Telnyx::apiKey(2))->timeout(10)
                ->post('https://api.telnyx.com/v2/texml/calls/' . rawurlencode($sid) . '/update', ['Url' => $url, 'Method' => 'POST']);
            $ok = $res->successful();
        } catch (\Throwable $e) {
            $ok = false;
        }
        if (! $ok) {
            \Illuminate\Support\Facades\Cache::forget('kt-mring-claim:' . $sid);
            return response()->json(['ok' => false, 'reason' => 'gone', 'message' => 'That call has ended.'], 410);
        }
        // Who is calling, for the portal's call card.
        \Illuminate\Support\Facades\Cache::put('kt-ivr-last-caller', ['from' => $r['from'] ? \App\Support\Dialer::pretty($r['from']) : 'unknown', 'dept' => $r['dept_name'], 'at' => time()], 120);
        \App\Support\Audit::write(['user_id' => $uid, 'agency_id' => null, 'action' => 'call.answered_in_app', 'entity_type' => 'phone_line', 'entity_id' => null,
            'payload' => json_encode(['summary' => 'Answered a ' . $r['dept_name'] . ' call from ' . ($r['from'] ? \App\Support\Dialer::pretty($r['from']) : 'an unknown caller') . ' in the app', 'call' => $sid]),
            'created_at' => now()]);
        return response()->json(['ok' => true, 'caller' => $r['from'] ? \App\Support\Dialer::pretty($r['from']) : null, 'department' => $r['dept_name']]);
    }

    private function caller(Request $request): string
    {
        $n = preg_replace('/\D/', '', (string) $request->input('From', ''));
        if (strlen($n) === 11 && $n[0] === '1') {
            $n = substr($n, 1);
        }

        return strlen($n) === 10 ? '(' . substr($n, 0, 3) . ') ' . substr($n, 3, 3) . '-' . substr($n, 6) : 'unknown';
    }

    /** A call-history line in the audit log (platform level: the line is KiddieTrac's own). */
    private function audit(Request $request, string $summary): void
    {
        try {
            \App\Support\Audit::write(['user_id' => null, 'agency_id' => null, 'action' => 'voice.menu', 'entity_type' => 'phone_line', 'entity_id' => null,
                'payload' => json_encode(['summary' => $summary, 'call' => (string) $request->input('CallSid', '')]),
                'ip_address' => $request->ip(), 'created_at' => now()]);
        } catch (\Throwable $e) {
            Log::warning('Phone menu audit failed', ['error' => $e->getMessage()]);
        }
    }
}
