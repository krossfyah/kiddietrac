<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use App\Support\Live;
use App\Support\ParentLine;
use App\Support\PhoneMenu;
use App\Support\PlatformSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * The KiddieTrac line for parents (2026-11-24): report absent / late by phone. App\Support\ParentLine has the rules.
 *
 *   start   every call now begins here (Telnyx TeXML voice_url; its fallback is the hosted script, so a slow
 *           server still answers). A number that isn't a parent's gets the usual menu, word for word.
 *   greet → code → check → child → action → reason | time → confirm → done          (verified: applied at once)
 *                         └→ msg → action → reason | time → confirm → record → bye   (not verified: staff approve)
 *
 * Staff: GET /safe-arrival/phone-messages, …/{id}/audio, POST …/{id}/approve {child_id}, …/{id}/decline.
 * Parents: GET /parent/phone-line, PUT /parent/phone-line/pin {pin}, DELETE /parent/phone-line/pin.
 */
class ParentLineController extends Controller
{
    private function ok(string $token): bool
    {
        $expected = (string) PlatformSettings::get('voice.voicemail_token', '');

        return $expected !== '' && hash_equals($expected, $token);
    }

    private function xml(string $inner): Response
    {
        return new Response(PhoneMenu::xml($inner), 200, ['Content-Type' => 'text/xml']);
    }

    private function say(string $t): string
    {
        return PhoneLineController::say($t, (string) PhoneLineController::config()['voice']);
    }

    private function url(string $step, array $q = []): string
    {
        return PhoneMenu::url('parent/' . $step, $q);
    }

    private function gather(string $step, string $prompt, int $digits = 1, int $timeout = 7, string $finish = '#'): string
    {
        return '<Gather action="' . $this->url($step) . '" method="POST" numDigits="' . $digits . '" timeout="' . $timeout . '" finishOnKey="' . $finish . '">'
            . $this->say($prompt) . '</Gather>';
    }

    private function redirect(string $step): string
    {
        return '<Redirect method="POST">' . $this->url($step) . '</Redirect>';
    }

    /** The usual menu without its intro (a parent who pressed 2, or anything else gone wrong). */
    private function usual(bool $withIntro = false): string
    {
        $line = PhoneLineController::config();
        $menu = PhoneMenu::config();
        $has = (bool) array_filter($menu['departments'], fn ($d) => $d['enabled']);

        return ($menu['enabled'] && $has ? PhoneMenu::gather($menu, (string) $line['voice'], $withIntro) : '') . PhoneLineController::voicemailBlock($line);
    }

    private function digitsIn(Request $r): string
    {
        return substr(preg_replace('/\D/', '', (string) ($r->input('Digits') ?? $r->input('digits') ?? '')), 0, 6);
    }

    /** ANY /voice/ivr/{token}/start - the first thing every call to the line hears. */
    public function start(Request $request, string $token): Response
    {
        if (! $this->ok($token)) return new Response('', 404);
        $sid = (string) $request->input('CallSid', '');
        $from = (string) $request->input('From', '');
        $users = [];
        try {
            if (ParentLine::enabled() && $sid !== '') $users = ParentLine::match($from);
            $kids = ParentLine::children($users);
        } catch (\Throwable $e) {
            Log::warning('Parent line lookup failed', ['error' => $e->getMessage()]);
            $kids = [];
        }
        if (! $kids) {
            // Not a parent: exactly the script the line played before this existed.
            return new Response(PhoneLineController::script(PhoneLineController::config()), 200, ['Content-Type' => 'text/xml']);
        }
        ParentLine::save($sid, ['from' => $from, 'users' => $users, 'agencies' => array_values(array_unique(array_column($kids, 'agency_id'))),
            'verified' => false, 'tries' => 0, 'loops' => 0]);
        $intro = (string) PhoneMenu::config()['intro'];

        return $this->xml($this->gather('greet', trim($intro . ' If you are calling about your child today, to report an absence or a late drop-off or pickup, press 1. For anything else, press 2.'), 1, 6)
            . $this->redirect('other'));
    }

    /** ANY /voice/ivr/{token}/parent/{step} */
    public function step(Request $request, string $token, string $step): Response
    {
        if (! $this->ok($token)) return new Response('', 404);
        $sid = (string) $request->input('CallSid', '');
        $s = ParentLine::state($sid);
        if (! $s || $step === 'other') return $this->xml($this->usual(false));
        $d = $this->digitsIn($request);
        $s['loops'] = (int) ($s['loops'] ?? 0) + 1;
        if ($s['loops'] > 40) return $this->xml($this->say('Sorry, we could not finish this call. Please contact your centre. Goodbye.') . '<Hangup/>');
        try {
            $out = $this->run($step, $d, $s, $sid, $request);
        } catch (\Throwable $e) {
            Log::warning('Parent line step failed', ['step' => $step, 'error' => $e->getMessage()]);
            $out = $this->say('Sorry, something went wrong. Please try the app or contact your centre. Goodbye.') . '<Hangup/>';
        }
        ParentLine::save($sid, $s);

        return $this->xml($out);
    }

    private function kid(array $s): ?array
    {
        foreach (($s['kids'] ?? []) as $k) if ((int) $k['child_id'] === (int) ($s['child_id'] ?? 0)) return $k;

        return null;
    }

    private function name(array $s): string
    {
        $k = $this->kid($s);

        return $k ? $k['first_name'] : 'your child';
    }

    private function run(string $step, string $d, array &$s, string $sid, Request $request): string
    {
        switch ($step) {
            case 'greet':
                return $d === '1' ? $this->run('code', '', $s, $sid, $request) : $this->usual(false);

            case 'code':
                $res = ParentLine::sendCode($sid, (string) $s['from'], $s['agencies']);
                $pin = ParentLine::anyPin($s['users']);
                $s['code'] = $res;
                ParentLine::audit(null, null, 'phone_line.call', null, 'Parent line: caller ' . ParentLine::masked($s['from']) . ' recognised; code ' . $res . ($pin ? ', has a phone PIN' : ''));
                if ($res === 'sent') {
                    return $this->gather('check', 'To keep your child\'s information safe, we just texted a 4 digit code to this phone. Please enter it now.'
                        . ($pin ? ' You can also enter your phone PIN.' : '') . ' If you can\'t get the text, stay on the line.', 4, 25)
                        . $this->redirect('msg');
                }
                if ($pin) {
                    return $this->gather('check', 'Please enter your 4 digit phone PIN. If you don\'t have one, stay on the line.', 4, 15)
                        . $this->redirect('msg');
                }

                return $this->run('msg', '', $s, $sid, $request);

            case 'check':
                $via = null;
                $who = [];
                if (strlen($d) === 4 && ParentLine::codeMatches($sid, $d)) { $via = 'code'; $who = $s['users']; }
                elseif (strlen($d) === 4 && ($uid = ParentLine::pinUser($s['users'], $d))) { $via = 'pin'; $who = [$uid]; }
                if (! $via) {
                    $s['tries'] = (int) $s['tries'] + 1;
                    if ($s['tries'] >= 3) {
                        ParentLine::audit(null, null, 'phone_line.unverified', null, 'Parent line: caller ' . ParentLine::masked($s['from']) . ' could not verify after 3 tries');
                        return $this->say('Sorry, that didn\'t match.') . $this->run('msg', '', $s, $sid, $request);
                    }

                    return $this->gather('check', 'Sorry, that didn\'t match. Please try again.', 4, 20) . $this->redirect('msg');
                }
                $s['verified'] = true;
                $s['via'] = $via;
                $s['vusers'] = $who;
                $s['kids'] = ParentLine::children($who);
                ParentLine::audit($s['kids'][0]['agency_id'] ?? null, $who[0] ?? null, 'phone_line.verified', null,
                    'Parent line: caller ' . ParentLine::masked($s['from']) . ' verified by ' . ($via === 'pin' ? 'phone PIN' : 'texted code'));

                return $this->run('child', '', $s, $sid, $request);

            case 'child':
                $kids = $s['kids'] ?? [];
                if (! $kids) return $this->say('We couldn\'t find a child on your account at a centre right now.') . $this->usual(false);
                if (count($kids) === 1) { $s['child_id'] = $kids[0]['child_id']; return $this->actionMenu($s); }
                $p = [];
                foreach ($kids as $i => $k) $p[] = 'For ' . $k['first_name'] . ', press ' . ($i + 1) . '.';

                return $this->gather('pick', 'Which child is this about? ' . implode(' ', $p), 1, 8) . $this->redirect('child');

            case 'pick':
                $k = ($s['kids'] ?? [])[(int) $d - 1] ?? null;
                if (! $k || $d === '') return $this->say('Sorry, that isn\'t one of the options.') . $this->run('child', '', $s, $sid, $request);
                $s['child_id'] = $k['child_id'];

                return $this->actionMenu($s);

            case 'msg':
                // Not verified: they can still leave a message for the centre to confirm.
                $s['verified'] = false;
                $s['kids'] = ParentLine::children($s['users']);
                $s['child_id'] = null;

                return $this->say('No problem. You can leave a message instead, and your centre will confirm it.') . $this->actionMenu($s);

            case 'act':
                $a = ParentLine::ACTIONS[$d] ?? null;
                if (! $a) return $this->say('Sorry, that isn\'t one of the options.') . $this->actionMenu($s);
                $s['action'] = $a;
                $s['reason'] = null;
                $s['by'] = null;
                $n = $this->name($s);
                if (str_starts_with($a, 'absent')) {
                    return $this->gather('reason', 'If ' . $n . ' is sick, press 1. For an appointment, press 2. For a holiday, press 3. For family reasons, press 4. For anything else, press 5.', 1, 8)
                        . $this->redirect('reason');
                }

                return $this->timeMenu($s);

            case 'reason':
                $s['reason'] = ParentLine::REASONS[$d] ?? 'other';

                return $this->confirm($s);

            case 'time':
                $k = $this->kid($s) ?? (($s['kids'] ?? [])[0] ?? null);
                $by = $k ? ParentLine::lateTime($k, (string) $s['action'], $d) : null;
                if (! $by) {
                    $s['time_miss'] = (int) ($s['time_miss'] ?? 0) + 1;
                    if ($s['time_miss'] > 2) return $this->say('Sorry, we didn\'t get a time. Please try the app or contact your centre. Goodbye.') . '<Hangup/>';

                    return $this->say('Sorry, we didn\'t get that.') . $this->timeMenu($s);
                }
                $s['by'] = $by;

                return $this->confirm($s);

            case 'confirm':
                if ($d === '2') return $s['verified'] ? $this->actionMenu($s) : $this->run('msg', '', $s, $sid, $request);
                if ($d !== '1') return $this->confirm($s);
                if (! $s['verified']) {
                    return $this->say('After the beep, please say your name and your child\'s full name, and anything the centre should know. Press pound when you\'re done.')
                        . '<Record action="' . $this->url('bye') . '" method="POST" recordingStatusCallback="' . $this->url('recording') . '" recordingStatusCallbackMethod="POST"'
                        . ' maxLength="90" timeout="6" playBeep="true" finishOnKey="#" />'
                        . $this->say('We didn\'t hear a message, so nothing was sent. Please try again or contact your centre. Goodbye.') . '<Hangup/>';
                }
                $k = $this->kid($s);
                if (! $k) return $this->run('child', '', $s, $sid, $request);
                [$ok, $said] = ParentLine::apply($k, (string) $s['action'], $s['reason'] ?? null, $s['by'] ?? null, $this->guardianFor($k, $s['vusers'] ?? []), (string) $s['via']);
                $s['done'] = $ok;

                return $this->say($said) . $this->gather('again', 'To report something else, press 1. Otherwise, you can hang up. Goodbye.', 1, 5) . '<Hangup/>';

            case 'again':
                return $d === '1' ? $this->run('child', '', $s, $sid, $request) : $this->say('Goodbye.') . '<Hangup/>';

            case 'bye':
                $this->keepRecording($request, $s, $sid);

                return $this->say('Thank you. Your centre will listen to your message and confirm it, and you\'ll get a text when they do. If it\'s urgent, please call your centre. Goodbye.') . '<Hangup/>';

            case 'recording':
                $this->keepRecording($request, $s, $sid);

                return '';
        }

        return $this->usual(false);
    }

    private function actionMenu(array $s): string
    {
        $n = $this->name($s);

        return $this->gather('act', 'To report ' . $n . ' absent today, press 1. If you\'ll drop ' . $n . ' off late today, press 2. If you\'ll pick ' . $n . ' up late today, press 3. To report ' . $n . ' absent tomorrow, press 4.', 1, 8)
            . $this->say('Sorry, we didn\'t get that.') . $this->redirect($s['verified'] ? 'child' : 'msg');
    }

    private function timeMenu(array $s): string
    {
        $p = $s['action'] === 'late_pickup'
            ? 'If you\'ll pick up within 15 minutes of the usual time, press 1. Within 30 minutes, press 2. Within an hour, press 3.'
            : 'If you\'ll be there within 30 minutes, press 1. Within an hour, press 2. Within two hours, press 3.';

        return $this->gather('time', $p . ' Or enter the time as 4 digits, like 1 0 3 0 for 10:30.', 4, 5) . $this->redirect('time');
    }

    private function confirm(array $s): string
    {
        $n = $this->name($s);
        $what = match ($s['action']) {
            'absent_today' => $n . ' is absent today',
            'absent_tomorrow' => $n . ' will be absent tomorrow',
            'late_arrival' => $n . ' will be dropped off by ' . ParentLine::spoken((string) $s['by']),
            default => 'you\'ll pick ' . $n . ' up by ' . ParentLine::spoken((string) $s['by']),
        };

        return $this->gather('confirm', ($s['verified'] ? 'We\'ll tell the centre ' : 'Your message will say ') . $what . '. To confirm, press 1. To start again, press 2.', 1, 8)
            . $this->redirect('confirm');
    }

    /** Of the verified parents, one who is this child's guardian. */
    private function guardianFor(array $k, array $users): int
    {
        $g = DB::table('guardians as g')->join('children as c', 'c.family_id', '=', 'g.family_id')
            ->where('c.id', $k['child_id'])->whereIn('g.user_id', $users ?: [0])->value('g.user_id');

        return (int) ($g ?: $k['guardian_user_id']);
    }

    /** The unverified caller's message: kept privately, one row per agency, its approvers told. Safe to call twice. */
    private function keepRecording(Request $request, array $s, string $sid): void
    {
        $url = (string) $request->input('RecordingUrl', '');
        if ($url === '' || ! preg_match('#^https://#', $url) || empty($s['action'])) return;
        if (! Cache::add('kt-pline-rec:' . hash('sha256', $sid), 1, now()->addHour())) return;
        try {
            $body = null;
            foreach ([false, true] as $auth) {
                $r = $auth ? Http::withToken(\App\Support\Telnyx::apiKey(ParentLine::LINE_AGENCY))->timeout(60)->get($url) : Http::timeout(60)->get($url);
                if ($r->successful() && strlen($r->body()) >= 200) { $body = $r; break; }
            }
            $path = null;
            if ($body) {
                $ext = str_contains(strtolower((string) $body->header('Content-Type')), 'wav') ? 'wav' : 'mp3';
                $path = 'phone-reports/' . date('Y/m') . '/' . \Illuminate\Support\Str::random(24) . '.' . $ext;
                Storage::disk('local')->put($path, $body->body());
            }
            $kids = ParentLine::children($s['users']);
            $ids = [];
            foreach (array_values(array_unique(array_column($kids, 'agency_id'))) as $agencyId) {
                $mine = array_values(array_filter($kids, fn ($k) => (int) $k['agency_id'] === (int) $agencyId));
                $date = Carbon::now(AgencyTime::tz($agencyId));
                if ($s['action'] === 'absent_tomorrow') $date->addDay();
                $ids[] = $id = DB::table('phone_reports')->insertGetId([
                    'agency_id' => $agencyId, 'call_sid' => mb_substr($sid, 0, 120), 'from_number' => \App\Support\Telnyx::e164((string) $s['from']),
                    'via' => 'message', 'action' => $s['action'], 'reason' => $s['reason'] ?? null, 'arrive_by' => $s['by'] ?? null,
                    'for_date' => $date->toDateString(), 'candidates' => json_encode($mine), 'status' => 'pending',
                    'recording_path' => $path, 'recording_seconds' => (int) $request->input('RecordingDuration', 0) ?: null,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                ParentLine::audit($agencyId, null, 'phone_line.message', null, 'Parent line: message from ' . ParentLine::masked($s['from']) . ' (' . (ParentLine::LABEL[$s['action']] ?? '') . ') waiting for a director/admin');
                ParentLine::notifyApprovers($id);
            }
            // Speech to text for the approver, after the call has its answer (never for a test agency: no AI there).
            if ($path) {
                dispatch(function () use ($ids, $path) {
                    $real = DB::table('phone_reports')->whereIn('id', $ids)->pluck('agency_id')->filter(fn ($a) => ! \App\Support\TestAgency::blocks((int) $a))->all();
                    if (! $real) return;
                    [$t] = \App\Support\CallRecords::transcribe($path, 'phone_reports');
                    if ($t) DB::table('phone_reports')->whereIn('id', $ids)->update(['transcript' => mb_substr($t, 0, 5000), 'updated_at' => now()]);
                })->afterResponse();
            }
        } catch (\Throwable $e) {
            Cache::forget('kt-pline-rec:' . hash('sha256', $sid));
            Log::warning('Parent line message not kept', ['error' => $e->getMessage()]);
        }
    }

    /* ── staff: approve or decline a message ───────────────────────────────── */

    /** [agency, centre ids I decide for]. Agency admins: every open centre; directors: theirs. Nobody else. */
    private function scope(Request $request): array
    {
        $uid = (int) $request->user()->id;
        $agencyId = (int) $request->header('X-Active-Agency-Id');
        $ra = DB::table('role_assignments')->where('user_id', $uid)->where('active', 1)->get(['role', 'agency_id', 'centre_id']);
        if (! $agencyId) $agencyId = (int) ($ra->firstWhere('role', '!=', 'guardian')->agency_id ?? 0);
        abort_unless($agencyId, 400, 'Select an agency first.');
        if ($ra->contains(fn ($r) => $r->role === 'platform_admin' || ($r->role === 'agency_admin' && (int) $r->agency_id === $agencyId))) {
            return [$agencyId, array_map('intval', Live::centreIds($agencyId) ?: [0])];
        }
        $centres = Live::onlyOpenCentres($ra->filter(fn ($r) => $r->role === 'centre_director' && (int) $r->agency_id === $agencyId && $r->centre_id)
            ->pluck('centre_id')->map(fn ($x) => (int) $x)->unique()->values()->all());
        abort_unless($centres, 403, 'Only directors and agency admins approve phone messages.');

        return [$agencyId, array_map('intval', $centres)];
    }

    private function present(object $r, array $centres, string $tz): ?array
    {
        $kids = array_values(array_filter(json_decode((string) $r->candidates, true) ?: [], fn ($k) => in_array((int) $k['centre_id'], $centres, true)));
        if (! $kids) return null;
        $by = $r->decided_by_id ? DB::table('users')->where('id', $r->decided_by_id)->value('first_name') : null;

        return ['id' => (int) $r->id, 'at' => Carbon::parse($r->created_at)->timezone($tz)->format('M j, g:i A'),
            'from' => ParentLine::masked($r->from_number), 'action' => $r->action, 'action_label' => ParentLine::LABEL[$r->action] ?? $r->action,
            'reason' => $r->reason, 'by' => $r->arrive_by ? ParentLine::spoken(substr((string) $r->arrive_by, 0, 5)) : null,
            'for_date' => Carbon::parse($r->for_date)->format('D, M j'), 'children' => array_map(fn ($k) => ['id' => $k['child_id'], 'name' => $k['first_name']], $kids),
            'status' => $r->status, 'has_audio' => (bool) $r->recording_path, 'seconds' => $r->recording_seconds, 'transcript' => $r->transcript,
            'child_id' => $r->child_id ? (int) $r->child_id : null, 'decided_by' => $by, 'outcome' => $r->outcome,
            'decided_at' => $r->decided_at ? Carbon::parse($r->decided_at)->timezone($tz)->format('M j, g:i A') : null];
    }

    /** GET /safe-arrival/phone-messages - waiting ones, and the last 3 days' decided ones. */
    public function index(Request $request): JsonResponse
    {
        [$agencyId, $centres] = $this->scope($request);
        $tz = AgencyTime::tz($agencyId);
        $rows = DB::table('phone_reports')->where('agency_id', $agencyId)
            ->where(fn ($q) => $q->where('status', 'pending')->orWhere('created_at', '>=', now()->subDays(3)))
            ->orderByRaw("status = 'pending' desc")->orderByDesc('id')->limit(40)->get();
        $out = [];
        foreach ($rows as $r) if ($p = $this->present($r, $centres, $tz)) $out[] = $p;

        return response()->json(['messages' => $out]);
    }

    private function mine(Request $request, int $id): array
    {
        [$agencyId, $centres] = $this->scope($request);
        $r = DB::table('phone_reports')->where('id', $id)->where('agency_id', $agencyId)->first();
        abort_unless($r && $this->present($r, $centres, AgencyTime::tz($agencyId)), 404);

        return [$r, $agencyId, $centres];
    }

    /** GET /safe-arrival/phone-messages/{id}/audio */
    public function audio(Request $request, int $id)
    {
        [$r] = $this->mine($request, $id);
        abort_unless($r->recording_path && Storage::disk('local')->exists($r->recording_path), 404);
        ParentLine::audit((int) $r->agency_id, (int) $request->user()->id, 'phone_line.listened', null, 'Listened to phone message #' . $id);

        return response(Storage::disk('local')->get($r->recording_path), 200, [
            'Content-Type' => str_ends_with($r->recording_path, '.wav') ? 'audio/wav' : 'audio/mpeg', 'Cache-Control' => 'private, no-store']);
    }

    /** POST /safe-arrival/phone-messages/{id}/approve {child_id} */
    public function approve(Request $request, int $id): JsonResponse
    {
        [$r, $agencyId, $centres] = $this->mine($request, $id);
        $childId = (int) $request->validate(['child_id' => ['required', 'integer']])['child_id'];
        $kid = null;
        foreach (json_decode((string) $r->candidates, true) ?: [] as $k) {
            if ((int) $k['child_id'] === $childId && in_array((int) $k['centre_id'], $centres, true)) $kid = $k;
        }
        abort_unless($kid, 422, 'Pick one of the children on this message.');
        if (! Cache::add('kt-pline-decide:' . $id, 1, 30) || $r->status !== 'pending') {
            return response()->json(['message' => 'This message was already decided.'], 409);
        }
        $me = $request->user();
        [$ok, $said] = ParentLine::apply($kid, (string) $r->action, $r->reason, $r->arrive_by ? substr((string) $r->arrive_by, 0, 5) : null,
            (int) $kid['guardian_user_id'], 'message', (string) $r->for_date, (int) $me->id);
        if (! $ok) {
            Cache::forget('kt-pline-decide:' . $id);
            return response()->json(['message' => $said], 422);
        }
        DB::table('phone_reports')->where('id', $id)->update(['status' => 'approved', 'child_id' => $childId, 'decided_by_id' => $me->id,
            'decided_at' => now(), 'outcome' => mb_substr($said, 0, 500), 'updated_at' => now()]);
        $this->clearBells($id);
        $text = ParentLine::textOutcome($r, \App\Support\FamilyFacingName::textFrom((int) $r->agency_id,
            'your centre confirmed your phone message. ' . preg_replace('/^Thank you\. /', '', $said)));   // from the agency (2026-11-27)
        ParentLine::audit($agencyId, (int) $me->id, 'phone_line.approved', $childId, 'Approved phone message #' . $id . ': ' . $said . ' (caller texted: ' . $text . ')');

        return response()->json(['ok' => true, 'message' => $said]);
    }

    /** POST /safe-arrival/phone-messages/{id}/decline */
    public function decline(Request $request, int $id): JsonResponse
    {
        [$r, $agencyId] = $this->mine($request, $id);
        if (! Cache::add('kt-pline-decide:' . $id, 1, 30) || $r->status !== 'pending') {
            return response()->json(['message' => 'This message was already decided.'], 409);
        }
        DB::table('phone_reports')->where('id', $id)->update(['status' => 'declined', 'decided_by_id' => $request->user()->id,
            'decided_at' => now(), 'outcome' => 'Declined', 'updated_at' => now()]);
        $this->clearBells($id);
        $text = ParentLine::textOutcome($r, \App\Support\FamilyFacingName::textFrom((int) $r->agency_id,
            'your centre could not confirm your phone message about your child. Please contact the centre directly.'));
        ParentLine::audit($agencyId, (int) $request->user()->id, 'phone_line.declined', null, 'Declined phone message #' . $id . ' (caller texted: ' . $text . ')');

        return response()->json(['ok' => true]);
    }

    private function clearBells(int $id): void
    {
        try {
            DB::table('notifications')->where('type', 'safe_arrival')->whereNull('read_at')
                ->where('data', 'like', '%"phone_report":' . $id . '}%')->update(['read_at' => now()]);
        } catch (\Throwable $e) {
        }
    }

    /* ── parents: the number, and their phone PIN ──────────────────────────── */

    /** GET /parent/phone-line */
    public function mineParent(Request $request): JsonResponse
    {
        $u = DB::table('users')->where('id', $request->user()->id)->first(['phone', 'phone2', 'phone_pin_hash', 'phone_pin_set_at']);
        $phones = array_values(array_filter([ParentLine::masked($u->phone ?? null), ParentLine::masked($u->phone2 ?? null)]));

        return response()->json(['enabled' => ParentLine::enabled(), 'number' => PhoneLineController::NUMBER, 'phones' => $phones,
            'has_pin' => ! empty($u->phone_pin_hash), 'pin_set' => $u && $u->phone_pin_set_at ? Carbon::parse($u->phone_pin_set_at)->format('M j, Y') : null]);
    }

    /** PUT /parent/phone-line/pin {pin} */
    public function setPin(Request $request): JsonResponse
    {
        $pin = (string) $request->validate(['pin' => ['required', 'string']])['pin'];
        $uid = (int) $request->user()->id;
        $u = DB::table('users')->where('id', $uid)->first(['phone']);
        if ($why = ParentLine::weakPin($pin, $u->phone ?? null)) return response()->json(['message' => $why], 422);
        DB::table('users')->where('id', $uid)->update(['phone_pin_hash' => Hash::make($pin), 'phone_pin_set_at' => now()]);
        Cache::forget('kt-ppin-fail:' . $uid);
        Cache::forget('kt-ppin-lock:' . $uid);
        ParentLine::audit(null, $uid, 'phone_line.pin_set', null, 'Set their phone PIN for the KiddieTrac line');

        return $this->mineParent($request);
    }

    /** DELETE /parent/phone-line/pin */
    public function deletePin(Request $request): JsonResponse
    {
        $uid = (int) $request->user()->id;
        DB::table('users')->where('id', $uid)->update(['phone_pin_hash' => null, 'phone_pin_set_at' => null]);
        ParentLine::audit(null, $uid, 'phone_line.pin_removed', null, 'Removed their phone PIN for the KiddieTrac line');

        return $this->mineParent($request);
    }
}
