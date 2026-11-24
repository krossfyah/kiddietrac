<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\PublicDemo;
use App\Support\SafeArrivalCall;
use App\Support\WebDemoPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The website demo phone's calls, texts and "try it" scenarios (2026-10-30; see App\Support\WebDemoPhone).
 * Every route answers only a demo-only person of a public demo agency, about their own calls and texts.
 *
 *   GET  /demo/phone/events?after=N        what rang / arrived for me since N (and runs any scripted step now due)
 *   POST /demo/phone/calls/{id}            {action: answer|decline|missed|key, digit}  -> what the call says back
 *   GET  /demo/phone/texts                 my text thread with the centre
 *   POST /demo/phone/texts                 {body}  a reply from my phone (filed as an inbound text, into chat)
 *   POST /demo/phone/try                   {what: safe_arrival|text}  a scripted moment, the real code underneath
 */
class WebDemoPhoneController extends Controller
{
    private function me(Request $request): ?object
    {
        $u = $request->user();
        if (! $u || ! PublicDemo::isUser((int) $u->id)) return null;
        // Their OWN demo agency (2026-10-30: Brightwood and the white-label Starlight are both public demos).
        $agency = PublicDemo::agencyOf((int) $u->id);
        return $agency ? (object) ['id' => (int) $u->id, 'agency' => (int) $agency, 'phone' => (string) $u->phone, 'first' => (string) $u->first_name,
            'role' => (string) (PublicDemo::roleOf((int) $u->id) ?: 'other')] : null;
    }

    private function no(): JsonResponse
    {
        return response()->json(['message' => 'Not found.'], 404);
    }

    public function events(Request $request): JsonResponse
    {
        if (! ($me = $this->me($request))) return $this->no();
        // No ?after: the first look of a new sign-in - only where the list stands now, nothing old rings.
        $base = ! $request->has('after');
        $after = max(0, (int) $request->query('after', 0));
        $this->closeArrived($me);
        if ($base) {
            return response()->json(['events' => [], 'last' => (int) (DB::table('web_demo_events')->where('user_id', $me->id)->where('deliver_at', '<=', now())->max('id') ?? 0)]);
        }
        $rows = DB::table('web_demo_events')->where('user_id', $me->id)->where('id', '>', $after)
            ->where('deliver_at', '<=', now())->orderBy('id')->limit(20)->get();
        $out = [];
        foreach ($rows as $r) {
            $p = json_decode((string) $r->payload, true) ?: [];
            if ($r->kind === 'step') { if (! $r->seen_at) $this->step($me, $p); continue; }      // a scripted server step, run once, not shown
            $out[] = ['id' => (int) $r->id, 'kind' => $r->kind] + $p;
        }
        if ($rows->count()) DB::table('web_demo_events')->whereIn('id', $rows->pluck('id'))->whereNull('seen_at')->update(['seen_at' => now()]);
        $last = (int) ($rows->max('id') ?? $after);
        if ($base) $last = (int) (DB::table('web_demo_events')->where('user_id', $me->id)->where('deliver_at', '<=', now())->max('id') ?? 0);
        return response()->json(['events' => $base ? [] : $out, 'last' => $last]);
    }

    public function call(Request $request, int $id): JsonResponse
    {
        if (! ($me = $this->me($request))) return $this->no();
        $data = $request->validate(['action' => ['required', 'in:answer,decline,missed,key,hangup'], 'digit' => ['nullable', 'regex:/^[0-9*#]$/']]);
        $call = DB::table('voice_calls')->where('id', $id)->where('to_user_id', $me->id)->where('provider', WebDemoPhone::PROVIDER)->first();
        if (! $call) return $this->no();
        $a = $data['action'];
        if ($a === 'answer') {
            DB::table('voice_calls')->where('id', $id)->update(['status' => 'answered', 'answered_at' => now(), 'updated_at' => now()]);
            return response()->json(['ok' => true]);
        }
        if ($a === 'key') {
            $digit = (string) ($data['digit'] ?? '');
            $reply = 'Goodbye.';
            if ($call->ref_type === 'safe_arrival_check' && $call->ref_id) {
                $reply = SafeArrivalCall::record($call, $digit);       // the carrier webhook's own path
            }
            $this->end($call, 'completed', $digit);
            return response()->json(['ok' => true, 'reply' => $reply]);
        }
        $this->end($call, $a === 'hangup' ? 'completed' : 'no-answer', null);
        $say = null;
        if ($a !== 'hangup' && $call->category === SafeArrivalCall::CATEGORY) {
            $missed = WebDemoPhone::caller($me->agency, $me->id);
            WebDemoPhone::push($me->id, 'missed', ['from' => $missed['name'], 'label' => 'Safe Arrival']);
        }
        return response()->json(['ok' => true, 'reply' => $say]);
    }

    private function end(object $call, string $status, ?string $digit): void
    {
        $upd = ['status' => $status, 'ended_at' => now(), 'updated_at' => now()];
        if ($digit !== null && $digit !== '') $upd['dtmf'] = $digit;
        if ($call->answered_at) $upd['duration_secs'] = max(1, now()->getTimestamp() - Carbon::parse($call->answered_at)->getTimestamp());
        DB::table('voice_calls')->where('id', $call->id)->update($upd);
    }

    public function texts(Request $request): JsonResponse
    {
        if (! ($me = $this->me($request))) return $this->no();
        $rows = DB::table('sms_messages')->where('agency_id', $me->agency)->where('to_user_id', $me->id)
            ->whereIn('status', ['sent', 'delivered', 'received'])->orderByDesc('id')->limit(60)->get(['id', 'direction', 'body', 'created_at']);
        $from = WebDemoPhone::caller($me->agency, $me->id);
        return response()->json(['from' => $from['name'], 'number' => $from['number'], 'messages' => $rows->reverse()->values()->map(fn ($r) => [
            'id' => (int) $r->id, 'mine' => $r->direction === 'in', 'body' => (string) $r->body,
            'at' => Carbon::parse($r->created_at)->toIso8601String(),
        ])->all()]);
    }

    public function reply(Request $request): JsonResponse
    {
        if (! ($me = $this->me($request))) return $this->no();
        $data = $request->validate(['body' => ['required', 'string', 'max:480']]);
        $body = trim($data['body']);
        if ($body === '') return response()->json(['message' => 'Type a message first.'], 422);
        $id = \App\Support\SmsInbound::record($me->agency, $me->phone ?: '000', $body, $me->id, null, 'demo-' . bin2hex(random_bytes(6)), WebDemoPhone::PROVIDER);
        return response()->json(['ok' => (bool) $id, 'id' => $id]);
    }

    /** A scripted moment on demand ("Try it" beside the phone). */
    public function tryIt(Request $request): JsonResponse
    {
        if (! ($me = $this->me($request))) return $this->no();
        $what = (string) $request->validate(['what' => ['required', 'in:safe_arrival,text']])['what'];
        $parentId = PublicDemo::idFor($me->id, 'parent') ?? PublicDemo::USERS['parent'];   // the parent of the same demo
        /* Staff, with the parent phone showing another family (2026-10-31): that family's child and parent. */
        $pickChild = (int) $request->input('child_id', 0);
        if ($pickChild && PublicDemo::roleOf($me->id) !== 'parent') {
            $p2 = PublicDemo::parentOfChild($pickChild, (int) $me->agency);
            if ($p2) $parentId = $p2; else $pickChild = 0;
        }
        $parent = DB::table('users')->where('id', $parentId)->first(['id', 'first_name', 'phone']);
        $child = DB::table('guardians as g')->join('children as c', 'c.family_id', '=', 'g.family_id')->join('families as f', 'f.id', '=', 'c.family_id')
            ->where('g.user_id', $parentId)->whereNull('c.deleted_at')->when($pickChild, fn ($q) => $q->orderByRaw('c.id = ? desc', [$pickChild]))->orderBy('c.id')
            ->first(['c.id', 'c.first_name', 'c.preferred_name', 'c.primary_room_id', 'f.centre_id']);
        if (! $parent || ! $child) return response()->json(['message' => 'The demo family is not set up.'], 422);
        $name = $child->preferred_name ?: $child->first_name;
        $tz = \App\Support\AgencyTime::tz($me->agency);
        $centre = (string) DB::table('centres')->where('id', $child->centre_id)->value('name');
        $isParent = $me->id === $parentId;

        if ($what === 'text') {
            if ($isParent) {
                $body = "{$centre}: Reminder - picture day is tomorrow! Photos start at 9:30 AM in {$name}'s room. Reply here if you have any questions.";
                app(SmsController::class)->sendOne($me->agency, $me->id, $me->phone ?: (string) $parent->phone, $body, 'announcement');
                return response()->json(['ok' => true, 'message' => 'A text from the centre is on its way.']);
            }
            // Staff: Megan texts the centre; it is filed and bridged into her family's chat with the room.
            \App\Support\SmsInbound::record($me->agency, (string) $parent->phone, "Hi, it's {$parent->first_name}. {$name} has a dentist appointment this afternoon - I'll pick {$name} up at 2:30. Thank you!",
                $parentId, null, 'demo-' . bin2hex(random_bytes(6)), WebDemoPhone::PROVIDER);
            return response()->json(['ok' => true, 'message' => "{$parent->first_name} just texted the centre."]);
        }

        // Safe Arrival: today's check for the child, as kiddietrac:safe-arrival would open it.
        $date = \App\Support\AgencyTime::today($me->agency);
        $expected = Carbon::now($tz)->subMinutes(35)->format('H:i');
        $vals = ['agency_id' => $me->agency, 'centre_id' => $child->centre_id, 'room_id' => $child->primary_room_id, 'expected_time' => $expected . ':00',
            'due_at' => now()->subMinutes(5), 'status' => 'notified', 'parents_notified_at' => now(), 'parents_notified' => 1,
            'escalated_at' => null, 'staff_alerted' => 0, 'resolved_at' => null, 'resolution' => null, 'resolved_by_id' => null, 'arrived_at' => null,
            'call_started_at' => null, 'calls_placed' => 0, 'parent_response' => null, 'parent_response_at' => null, 'parent_response_by_id' => null, 'updated_at' => now()];
        $existing = DB::table('safe_arrival_checks')->where('child_id', $child->id)->where('check_date', $date)->value('id');
        if ($existing) DB::table('safe_arrival_checks')->where('id', $existing)->update($vals);
        else $existing = DB::table('safe_arrival_checks')->insertGetId($vals + ['child_id' => $child->id, 'check_date' => $date, 'created_at' => now()]);
        $check = DB::table('safe_arrival_checks')->where('id', $existing)->first();
        $at = Carbon::parse($expected, $tz)->format('g:i A');

        if ($isParent) {
            // 1. The app's question and the text (the real wording of SafeArrivalCommand::askParents).
            $title = "🛡️ Has {$name} arrived?";
            $body = "{$name} was expected at {$centre} around {$at} and hasn't been signed in. If they're not coming in today, please tell us in the app (Not attending today). If they're on the way, no need to reply.";
            \App\Support\Notify::write(['user_id' => $me->id, 'type' => 'safe_arrival', 'title' => $title, 'body' => $body,
                'data' => json_encode(['link' => '#today', 'child_id' => $child->id]), 'created_at' => now()]);
            app(SmsController::class)->sendOne($me->agency, $me->id, $me->phone, $body, 'checkin_reminder');
            // 2. No answer: the Safe Arrival phone call (SafeArrivalCall, the agency's own wording), a few seconds later.
            WebDemoPhone::$nextDelay = 12;
            $script = SafeArrivalCall::question(SafeArrivalCall::config($me->agency), SafeArrivalCall::vars($check));
            app(VoiceController::class)->callOne($me->agency, $me->id, $me->phone, $script, SafeArrivalCall::CATEGORY, null, false, false,
                ['type' => 'safe_arrival_check', 'id' => (int) $check->id]);
            DB::table('safe_arrival_checks')->where('id', $check->id)->update(['call_started_at' => now(), 'calls_placed' => 1, 'updated_at' => now()]);
            WebDemoPhone::$nextDelay = 0;
            return response()->json(['ok' => true, 'message' => "Safe Arrival is checking on {$name}: watch for a text, then a call."]);
        }

        // Staff: the room is alerted now; Megan answers the call a little later ("on the way"), and the staff are told.
        \App\Support\SafeArrival::alertUsers($me->agency, [$me->id], "⚠️ Safe Arrival: {$name} hasn't arrived",
            "{$name} was expected around {$at} and hasn't been signed in. {$parent->first_name} has been texted and is being called now.", (int) $child->id, '#safe-arrival', ['email' => 'off']);
        /* Recorded as the 5-minute job records a staff alert (2026-11-02), so the check now ends the way a real one does:
           sign the child in (or report them absent) and the alert is read and an "all clear" comes back (closeArrived). */
        DB::table('safe_arrival_checks')->where('id', $check->id)->update(['status' => 'escalated', 'escalated_at' => now(), 'staff_alerted' => 1, 'updated_at' => now()]);
        /* THE PARENT'S PHONE IS OPEN BESIDE THIS ONE (2026-10-31, the website's Demo experience page): text Megan and ring her
           phone for real (simulated - WebDemoPhone, never a carrier), so the visitor can answer it there. If nobody answers
           within a minute, she answers "on the way" as before. */
        $comp = 0;
        try {
            $tok = $request->user()->currentAccessToken();
            $cid = (int) \Illuminate\Support\Facades\Cache::get('webdemo:companion:tok:' . (int) ($tok->id ?? 0), 0);
            if ($cid) $comp = (int) DB::table('personal_access_tokens')->where('id', $cid)->where('tokenable_id', $parentId)
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count();
        } catch (\Throwable $e) { $comp = 0; }
        if ($comp) {
            $askBody = "{$name} was expected at {$centre} around {$at} and hasn't been signed in. If they're not coming in today, please tell us in the app (Not attending today). If they're on the way, no need to reply.";
            \App\Support\Notify::write(['user_id' => $parentId, 'type' => 'safe_arrival', 'title' => "🛡️ Has {$name} arrived?", 'body' => $askBody,
                'data' => json_encode(['link' => '#today', 'child_id' => $child->id]), 'created_at' => now()]);
            app(SmsController::class)->sendOne($me->agency, $parentId, (string) $parent->phone, $askBody, 'checkin_reminder');
            WebDemoPhone::$nextDelay = 8;
            app(VoiceController::class)->callOne($me->agency, $parentId, (string) $parent->phone,
                SafeArrivalCall::question(SafeArrivalCall::config($me->agency), SafeArrivalCall::vars($check)), SafeArrivalCall::CATEGORY, null, false, false,
                ['type' => 'safe_arrival_check', 'id' => (int) $check->id]);
            WebDemoPhone::$nextDelay = 0;
            DB::table('safe_arrival_checks')->where('id', $check->id)->update(['call_started_at' => now(), 'calls_placed' => 1, 'updated_at' => now()]);
            $ringId = (int) DB::table('voice_calls')->where('to_user_id', $parentId)->where('provider', WebDemoPhone::PROVIDER)->max('id');
            if ($ringId) WebDemoPhone::push($me->id, 'step', ['do' => 'answer', 'call_id' => $ringId, 'digit' => '2', 'fallback' => 1], 60);
            return response()->json(['ok' => true, 'message' => "Safe Arrival alert for {$name}; {$parent->first_name}'s phone is ringing - answer it on the parent's phone."]);
        }
        $callId = DB::table('voice_calls')->insertGetId(['agency_id' => $me->agency, 'to_user_id' => $parentId, 'to_phone' => (string) $parent->phone,
            'body' => SafeArrivalCall::question(SafeArrivalCall::config($me->agency), SafeArrivalCall::vars($check)), 'category' => SafeArrivalCall::CATEGORY,
            'ref_type' => 'safe_arrival_check', 'ref_id' => $check->id, 'provider' => WebDemoPhone::PROVIDER, 'client_state' => 'kt_demo_' . bin2hex(random_bytes(10)),
            'status' => 'answered', 'answered_at' => now(), 'started_by_id' => $me->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('safe_arrival_checks')->where('id', $check->id)->update(['call_started_at' => now(), 'calls_placed' => 1, 'updated_at' => now()]);
        WebDemoPhone::push($me->id, 'step', ['do' => 'answer', 'call_id' => $callId, 'digit' => '2'], 15);
        return response()->json(['ok' => true, 'message' => "Safe Arrival alert for {$name}; {$parent->first_name} is being called."]);
    }

    /**
     * SAFE ARRIVAL "ALL CLEAR" ON THE DEMO (2026-11-02, App\Support\SafeArrivalAllClear). The demo agencies have Safe Arrival
     * switched off for the 5-minute job (kiddietrac:safe-arrival: no real-time checks for fictional children), so the job
     * never closes a check a visitor opened with "Try it". This does the job's own close for the person's demo agency, at
     * most every 8 s from the phone's event poll: a check still open today whose child has since been signed in (or
     * reported absent) is closed and, if staff were alerted, their alerts are read and everyone alerted gets the all-clear
     * - exactly SafeArrivalCommand's step, with its words. Never throws: the poll must answer.
     */
    private function closeArrived(object $me): void
    {
        try {
            if (! PublicDemo::isAgency($me->agency) || ! \Illuminate\Support\Facades\Cache::add('webdemo:saclose:' . $me->agency, 1, 8)) return;
            $date = \App\Support\AgencyTime::today($me->agency);
            $open = DB::table('safe_arrival_checks')->where('agency_id', $me->agency)->where('check_date', $date)->whereNull('resolved_at')->get();
            if ($open->isEmpty()) return;
            $rows = \App\Support\SafeArrival::today($me->agency, null, null, $date);
            $tz = \App\Support\AgencyTime::tz($me->agency);
            foreach ($open as $c) {
                $row = $rows[(int) $c->child_id] ?? null;
                if (! $row || ! in_array($row['state'], ['arrived', 'absent'], true)) continue;
                $n = DB::table('safe_arrival_checks')->where('id', $c->id)->whereNull('resolved_at')->update([
                    'status' => $row['state'], 'resolution' => $row['state'], 'arrived_at' => $row['arrived_at'], 'resolved_at' => now(), 'updated_at' => now()]);
                if (! $n) continue;                                     // another poll closed it first
                if ($row['state'] === 'arrived') {
                    [$t, $b] = \App\Support\SafeArrivalAllClear::arrivedText($row['name'], $row['room_name'] ?: null, (string) \App\Support\AgencyTime::fmt($row['arrived_at'], $tz));
                } else {
                    $by = DB::table('child_absences')->where('child_id', $row['child_id'])->whereDate('absent_on', $date)->value('reported_by_id');
                    [$t, $b] = \App\Support\SafeArrivalAllClear::absentText($row['name'], $row['room_name'] ?: null, $by ? (int) $by : null);
                }
                \App\Support\SafeArrivalAllClear::send($me->agency, 'arrival', $c, $t, $b);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('web demo Safe Arrival close failed', ['agency' => $me->agency, 'error' => $e->getMessage()]);
        }
    }

    /** A scripted server step that has come due (only ever for this same demo person's own scenario). */
    private function step(object $me, array $p): void
    {
        if (($p['do'] ?? '') === 'answer' && ! empty($p['call_id'])) {
            $call = DB::table('voice_calls')->where('id', (int) $p['call_id'])->where('provider', WebDemoPhone::PROVIDER)->first();
            $open = $call && ($call->status === 'answered' || (! empty($p['fallback']) && ! in_array((string) $call->status, ['completed', 'declined', 'missed', 'failed', 'no-answer', 'busy', 'canceled'], true)));
            if ($open && PublicDemo::isUser((int) $call->to_user_id)) {
                SafeArrivalCall::record($call, (string) ($p['digit'] ?? '2'));
                $this->end($call, 'completed', (string) ($p['digit'] ?? '2'));
            }
        }
    }
}
