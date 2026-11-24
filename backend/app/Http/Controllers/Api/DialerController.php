<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Dialer;
use App\Support\PortalPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * The outbound dialer (2026-10-23) — see App\Support\Dialer for how a call is placed and
 * who may dial. The signed-in half: the phone session, placing and ending calls, the call
 * log and recordings, and the settings. The Telnyx half (hook()) runs the call itself and
 * is protected by each call's own token.
 */
class DialerController extends Controller
{
    private function activeAgency(Request $r): ?int
    {
        $a = (int) $r->header('X-Active-Agency-Id');
        return $a > 0 ? $a : null;
    }

    private function isPlatformAdmin(int $uid): bool
    {
        return DB::table('role_assignments')->where('user_id', $uid)->where('role', 'platform_admin')->where('active', true)->exists();
    }

    private function isAgencyAdmin(int $uid, ?int $agencyId): bool
    {
        if (! $agencyId) {
            return false;
        }
        return $this->isPlatformAdmin($uid) || DB::table('role_assignments')->where('user_id', $uid)
            ->where('role', 'agency_admin')->where('agency_id', $agencyId)->where('active', true)->exists();
    }

    /**
     * GET /voice/phone/session — everything the portal's phone needs: whether the toll-free
     * menu rings this person, whether they may dial and from which line, and one 24-hour
     * WebRTC login token for the account that line is on.
     */
    public function session(Request $request): JsonResponse
    {
        $uid = (int) $request->user()->id;
        $line = Dialer::lineFor($uid, $this->activeAgency($request));

        $depts = [];
        $menu = \App\Support\PhoneMenu::config();
        if ($menu['enabled']) {
            foreach ($menu['departments'] as $d) {
                if ($d['enabled'] && $d['ring_portal'] && in_array($uid, $d['alert_user_ids'], true)) {
                    $depts[] = $d['name'];
                }
            }
        }
        // Menu calls ring on the phone line's account; a dialler on another account uses theirs.
        $account = $line ? (int) $line['account'] : ($depts ? PortalPhone::LINE_ACCOUNT : null);
        if (! $account) {
            return response()->json(['enabled' => false, 'dial' => null]);
        }
        $phone = PortalPhone::session($uid, $account);
        if (! $phone) {
            return response()->json(['enabled' => false, 'dial' => null, 'problem' => 'The portal phone could not be set up with Telnyx just now.']);
        }

        return response()->json([
            'enabled' => true, 'token' => $phone['token'], 'ttl' => $phone['ttl'],
            'departments' => $account === PortalPhone::LINE_ACCOUNT ? $depts : [],
            'dial' => $line ? ['from' => $line['from'], 'label' => $line['label'], 'record' => $line['record'], 'scope' => $line['scope']] : null,
        ]);
    }

    /** POST /voice/dialer/call {to, name?, lead_id?, chat_id?, context?} */
    public function call(Request $request): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', 'string', 'max:40'],
            'name' => ['nullable', 'string', 'max:160'],
            'lead_id' => ['nullable', 'integer'],
            'chat_id' => ['nullable', 'integer'],
            'context' => ['nullable', 'string', 'max:80'],
        ]);
        $uid = (int) $request->user()->id;
        /* A page still running the dial code from before the browser placed its own leg
           would start a call nobody connects. Tell it to reload instead. */
        if ($request->input('flow') !== 'browser') {
            return response()->json(['message' => 'KiddieTrac was just updated. Reload the page, then call again.'], 409);
        }
        $line = Dialer::lineFor($uid, $this->activeAgency($request));
        if (! $line) {
            return response()->json(['message' => 'Calling from the portal is not switched on for you here.'], 403);
        }
        $to = Dialer::normalise($data['to']);
        if (! $to) {
            return response()->json(['message' => 'That is not a Canadian or US phone number we can call. Check the number, with its area code.',
                'errors' => ['to' => ['Not a number we can call.']]], 422);
        }
        if ($to === $line['from']) {
            return response()->json(['message' => 'That is the number the call would come from.'], 422);
        }
        // A sales lead only from the platform line; nobody else's lead is touched.
        $leadId = ($line['scope'] === 'platform' && ! empty($data['lead_id'])
            && DB::table('sales_leads')->where('id', $data['lead_id'])->exists()) ? (int) $data['lead_id'] : null;
        /* A CALL THAT NEVER CONNECTED NEVER BLOCKS THE NEXT ONE (2026-10-23, Anthony: "it
           doesnt work and I try again and it shows already in a call"). An attempt whose
           browser leg never reached Telnyx stays 'dialing' for ever; 30 seconds on, it is
           closed as "Couldn't connect" and stops counting as a call in progress. */
        DB::table('outbound_calls')->where('user_id', $uid)->where('status', 'dialing')->whereNull('call_sid')
            ->where('created_at', '<', now()->subSeconds(30))
            ->update(['status' => 'failed', 'ended_at' => now(), 'updated_at' => now()]);
        $busy = DB::table('outbound_calls')->where('user_id', $uid)
            ->where(function ($q) {
                $q->where(fn ($w) => $w->where('status', 'dialing')->where('created_at', '>', now()->subSeconds(30)))
                  ->orWhere(fn ($w) => $w->whereIn('status', ['ringing', 'answered'])->where('updated_at', '>', now()->subMinutes(2)));
            })->exists();
        if ($busy) {
            return response()->json(['message' => 'You already have a call starting. Hang it up first, or wait a moment.'], 409);
        }

        [$call, $err] = Dialer::place($uid, $line, $to, [
            'name' => $data['name'] ?? null, 'lead_id' => $leadId,
            'chat_id' => $data['chat_id'] ?? null, 'context' => $data['context'] ?? null,
        ]);
        if (! $call) {
            return response()->json(['message' => $err ?: 'The call could not be placed.'], 502);
        }
        \App\Support\Audit::write([
            'user_id' => $uid, 'agency_id' => $line['agency_id'],
            'action' => 'call.placed', 'entity_type' => 'outbound_call', 'entity_id' => $call->id,
            'payload' => json_encode(['summary' => 'Called ' . ($data['name'] ? $data['name'] . ' ' : '') . Dialer::pretty($to) . ' from ' . $line['label'] . ($line['record'] ? ' (recorded)' : '') . '.',
                'to' => $to, 'from' => $line['from'], 'lead_id' => $leadId, 'recorded' => $line['record']]),
            'created_at' => now(),
        ]);

        return response()->json(['call' => $this->present($call) + ['sip' => Dialer::sipFor($call)],
            'line' => ['label' => $line['label'], 'record' => $line['record']]], 201);
    }

    /** POST /voice/dialer/calls/{id}/hangup — the dialler ends it before it connected. */
    public function hangup(Request $request, int $id): JsonResponse
    {
        $call = DB::table('outbound_calls')->where('id', $id)->where('user_id', $request->user()->id)->first();
        if (! $call) {
            return response()->json(['message' => 'Not found.'], 404);
        }
        /* The browser's own leg never reached Telnyx: say "Couldn't connect", and keep the
           reason the phone gave, so a failure can be traced instead of guessed at. */
        if ($request->input('reason') === 'not-connected' && $call->status === 'dialing' && ! $call->call_sid) {
            DB::table('outbound_calls')->where('id', $id)->update(['status' => 'failed', 'ended_at' => now(), 'updated_at' => now()]);
            \App\Support\Audit::write([
                'user_id' => $call->user_id, 'agency_id' => $call->agency_id,
                'action' => 'call.not_connected', 'entity_type' => 'outbound_call', 'entity_id' => $call->id,
                'payload' => json_encode(['summary' => 'A portal call to ' . Dialer::pretty((string) $call->to_number) . ' did not connect: '
                    . mb_substr((string) $request->input('cause', 'no reason given'), 0, 200) . '.',
                    'cause' => mb_substr((string) $request->input('cause', ''), 0, 200), 'ua' => mb_substr((string) $request->userAgent(), 0, 200)]),
                'created_at' => now(),
            ]);
            return response()->json(['ok' => true, 'status' => 'failed']);
        }
        Dialer::cancel($call);
        /* REAL STATUSES (Anthony, 2026-10-23: "if i hung up the call show status as hung up
           in the call log - real statuses"). The dialler's portal says when THEY ended it:
           after the other person answered = "You hung up" (with the talk time); before =
           "Hung up before they answered". It wins over Telnyx's own end-of-call report,
           which only says the dial stopped (no-answer / completed) and arrives either side
           of this. Anything already decided more than 15 seconds ago is left alone. */
        $recent = ! $call->ended_at || strtotime((string) $call->ended_at) >= time() - 15;
        if ($recent && in_array($call->status, ['dialing', 'ringing', 'answered', 'no-answer', 'completed', 'ended-by-them'], true)
            && ! ($call->status === 'ended-by-them' && $call->ended_at)) {
            $answered = ! empty($call->answered_at);
            $dur = $answered ? max(0, time() - strtotime((string) $call->answered_at)) : null;
            DB::table('outbound_calls')->where('id', $id)->update([
                'status' => $answered ? 'hung-up' : 'canceled',
                'duration_seconds' => $answered ? ($call->duration_seconds ?: $dur) : null,
                'ended_at' => $call->ended_at ?: now(), 'updated_at' => now(),
            ]);
            $call = DB::table('outbound_calls')->find($id);
            $this->logOnLead($call, $call->status, (int) $call->duration_seconds);
        }
        return response()->json(['ok' => true, 'status' => $call->status]);
    }

    /** Rows this person may see: their own; an admin also sees their line's calls. */
    private function visible(Request $request)
    {
        $uid = (int) $request->user()->id;
        $agency = $this->activeAgency($request);
        $all = $request->query('who') === 'all';
        $q = DB::table('outbound_calls')->leftJoin('users', 'users.id', '=', 'outbound_calls.user_id');
        if (! $all) {
            return $q->where('outbound_calls.user_id', $uid);
        }
        return $q->where(function ($w) use ($uid, $agency) {
            $w->where('outbound_calls.user_id', $uid);
            if ($this->isPlatformAdmin($uid)) {
                $w->orWhere('outbound_calls.scope', 'platform');
            }
            if ($agency && $this->isAgencyAdmin($uid, $agency)) {
                $w->orWhere(fn ($x) => $x->where('outbound_calls.scope', 'agency')->where('outbound_calls.agency_id', $agency));
            }
        });
    }

    private function present(object $c): array
    {
        return [
            'id' => (int) $c->id, 'to' => $c->to_number, 'to_pretty' => Dialer::pretty((string) $c->to_number),
            'name' => $c->to_name, 'from' => $c->from_number, 'status' => $c->status, 'recorded' => (bool) $c->recorded,
            'has_recording' => ! empty($c->recording_path), 'duration' => $c->duration_seconds, 'lead_id' => $c->lead_id,
            'by' => isset($c->first_name) ? trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')) : null,
            'created_at' => $c->created_at, 'answered_at' => $c->answered_at, 'ended_at' => $c->ended_at,
        ];
    }

    /** GET /voice/dialer/calls?who=mine|all */
    public function calls(Request $request): JsonResponse
    {
        $rows = $this->visible($request)->orderByDesc('outbound_calls.id')->limit(100)
            ->get(['outbound_calls.*', 'users.first_name', 'users.last_name']);
        return response()->json(['calls' => $rows->map(fn ($c) => $this->present($c))->values()]);
    }

    /** GET /voice/dialer/calls/{id} — one call, for the live card. */
    public function show(Request $request, int $id): JsonResponse
    {
        $c = DB::table('outbound_calls')->where('id', $id)->where('user_id', $request->user()->id)->first();
        return $c ? response()->json(['call' => $this->present($c)]) : response()->json(['message' => 'Not found.'], 404);
    }

    /** GET /voice/dialer/calls/{id}/recording — the audio, for someone who may hear it. */
    public function recording(Request $request, int $id)
    {
        $c = $this->visible($request->merge(['who' => 'all']))->where('outbound_calls.id', $id)->first(['outbound_calls.*']);
        if (! $c || empty($c->recording_path) || ! Storage::disk('local')->exists($c->recording_path)) {
            return response()->json(['message' => 'That recording is not available.'], 404);
        }
        \App\Support\Audit::write([
            'user_id' => $request->user()->id, 'agency_id' => $c->agency_id,
            'action' => 'call.recording_played', 'entity_type' => 'outbound_call', 'entity_id' => $c->id,
            'payload' => json_encode(['summary' => 'Played the recording of the call to ' . Dialer::pretty((string) $c->to_number) . '.']),
            'created_at' => now(),
        ]);
        $ext = pathinfo($c->recording_path, PATHINFO_EXTENSION) ?: 'mp3';
        return response()->file(Storage::disk('local')->path($c->recording_path), [
            'Content-Type' => $ext === 'wav' ? 'audio/wav' : 'audio/mpeg',
            'Content-Disposition' => 'inline; filename="call-' . $c->id . '.' . $ext . '"',
            'Cache-Control' => 'private, max-age=600',
        ]);
    }

    /**
     * GET /voice/dialer/inbox/count — the number on the 📞 badge: voicemails nobody has
     * heard + missed calls since this person last looked (App\Support\InboundCalls).
     */
    public function inboxCount(Request $request): JsonResponse
    {
        $c = \App\Support\InboundCalls::counts((int) $request->user()->id);
        unset($c['missed_seen_at']);
        $c['texts'] = \App\Support\LineTexts::allowed((int) $request->user()->id) ? \App\Support\LineTexts::unread() : 0;
        return response()->json($c);
    }

    /** GET /voice/dialer/inbox — missed calls and voicemails on the toll-free line, scoped. */
    public function inbox(Request $request): JsonResponse
    {
        $uid = (int) $request->user()->id;
        $scope = \App\Support\InboundCalls::scope($uid);
        if (! \App\Support\InboundCalls::allowed($scope)) {
            return response()->json(['allowed' => false, 'missed' => [], 'voicemails' => [], 'counts' => ['voicemail' => 0, 'missed' => 0, 'total' => 0]]);
        }
        $counts = \App\Support\InboundCalls::counts($uid);
        $seen = $counts['missed_seen_at'] ?? null;
        unset($counts['missed_seen_at']);
        $since = $seen ?: now()->subDays(7);
        $vms = \App\Support\InboundCalls::voicemailQuery($scope)->orderByDesc('id')->limit(60)
            ->get(['id', 'from_number', 'department', 'duration', 'stored_path', 'listened_at', 'created_at', 'lead_id', 'transcript'])->keyBy('id');
        $missed = \App\Support\InboundCalls::missedQuery($scope)->orderByDesc('id')->limit(60)->get()->map(function ($c) use ($since, $vms) {
            $vm = $c->voicemail_id ? $vms->get($c->voicemail_id) : null;
            return ['id' => $c->id, 'from' => (string) $c->from_number, 'from_pretty' => $c->from_number ? Dialer::pretty((string) $c->from_number) : 'Unknown caller',
                'department' => $c->department, 'created_at' => $c->created_at, 'voicemail_id' => $vm ? $c->voicemail_id : null, 'voicemail_heard' => $vm ? (bool) $vm->listened_at : null,
                'new' => ! $c->voicemail_id && (string) $c->created_at > (string) $since];
        })->values();
        $voicemails = $vms->values()->map(fn ($v) => ['id' => $v->id, 'from' => (string) $v->from_number,
            'from_pretty' => $v->from_number ? Dialer::pretty((string) $v->from_number) : 'Unknown caller', 'department' => $v->department,
            'duration' => (int) $v->duration, 'heard' => (bool) $v->listened_at, 'has_audio' => (bool) $v->stored_path, 'created_at' => $v->created_at,
            'lead_id' => $v->lead_id ? (int) $v->lead_id : null, 'transcript' => $v->transcript]);

        return response()->json(['allowed' => true, 'missed' => $missed, 'voicemails' => $voicemails, 'counts' => $counts]);
    }

    /**
     * POST /voice/dialer/voicemails/{id}/lead {name?, company?, note?, existing_id?}
     * (2026-10-23, Anthony: "with voicemails received, I want to be able to convert this to a
     * lead"). Sales and platform admins, on a voicemail they can see. If an open lead already
     * has this phone number it is offered first (409 with the match) unless existing_id says
     * which lead to use, or new=1 asks for a fresh one. The voicemail becomes an activity on
     * the lead with the recording attached, and the voicemail remembers its lead.
     */
    public function voicemailLead(Request $request, int $id): JsonResponse
    {
        $uid = (int) $request->user()->id;
        $roles = DB::table('role_assignments')->where('user_id', $uid)->where('active', true)->pluck('role')->all();
        abort_unless(array_intersect($roles, ['platform_admin', 'sales_rep']), 403, 'Only the sales team can create leads.');
        $vm = \App\Support\InboundCalls::voicemailQuery(\App\Support\InboundCalls::scope($uid))->where('id', $id)->first();
        abort_unless($vm, 404, 'That voicemail is not available.');
        $data = $request->validate(['name' => ['nullable', 'string', 'max:190'], 'company' => ['nullable', 'string', 'max:190'],
            'note' => ['nullable', 'string', 'max:2000'], 'existing_id' => ['nullable', 'integer'], 'new' => ['nullable', 'boolean']]);

        $digits = substr(preg_replace('/\D/', '', (string) $vm->from_number), -10);
        $pretty = $vm->from_number ? Dialer::pretty((string) $vm->from_number) : 'Unknown caller';
        $lead = null;
        if (! empty($data['existing_id'])) {
            $lead = \App\Models\SalesLead::where('id', (int) $data['existing_id'])->first();
            abort_unless($lead, 404, 'That lead was not found.');
        } elseif (empty($data['new']) && strlen($digits) === 10) {
            $match = \App\Models\SalesLead::where('status', 'open')->whereNotNull('phone')->get(['id', 'name', 'company', 'phone'])
                ->first(fn ($l) => substr(preg_replace('/\D/', '', (string) $l->phone), -10) === $digits);
            if ($match) {
                return response()->json(['message' => 'An open lead already has this number.', 'code' => 'match',
                    'match' => ['id' => $match->id, 'name' => $match->company ?: $match->name]], 409);
            }
        }
        $created = false;
        if (! $lead) {
            $lead = \App\Models\SalesLead::create([
                'name' => trim((string) ($data['name'] ?? '')) ?: 'Caller ' . $pretty,
                'company' => trim((string) ($data['company'] ?? '')) ?: null,
                'phone' => $vm->from_number ?: null, 'source' => 'voicemail', 'stage' => 'new', 'status' => 'open',
                'owner_id' => $uid, 'last_activity_at' => now(),
                'notes' => 'Left a voicemail on the KiddieTrac line' . ($vm->department ? ' (' . $vm->department . ')' : '') . '.',
            ]);
            $created = true;
        }
        $when = \Carbon\Carbon::parse($vm->created_at)->tz('America/Toronto')->format('D M j, g:i A') . ' ET';
        $act = \App\Models\SalesActivity::create(['lead_id' => $lead->id, 'user_id' => $uid, 'type' => 'call', 'done' => true, 'due_date' => now()->toDateString(),
            'body' => '📼 Voicemail from ' . $pretty . ($vm->department ? ' to ' . $vm->department : '') . ' · ' . $when
                . ($vm->duration ? ' · ' . gmdate($vm->duration >= 3600 ? 'G:i:s' : 'i:s', (int) $vm->duration) : '')
                . (trim((string) ($data['note'] ?? '')) !== '' ? "\n" . trim($data['note']) : '')]);
        // The recording goes with it, so the lead can be played back from Sales.
        try {
            if ($vm->stored_path && Storage::exists($vm->stored_path)) {
                $ext = pathinfo($vm->stored_path, PATHINFO_EXTENSION) ?: 'mp3';
                $rel = 'sales-activity/' . $lead->id . '/' . (string) \Illuminate\Support\Str::uuid() . '.' . $ext;
                Storage::disk('public')->put($rel, Storage::get($vm->stored_path));
                DB::table('sales_activity_files')->insert(['activity_id' => $act->id, 'lead_id' => $lead->id, 'kind' => 'audio',
                    'original_name' => 'voicemail-' . $id . '.' . $ext, 'file_url' => '/storage/' . $rel, 'mime' => $ext === 'wav' ? 'audio/wav' : 'audio/mpeg',
                    'size' => Storage::disk('public')->size($rel), 'uploaded_by_id' => $uid, 'created_at' => now(), 'updated_at' => now()]);
            }
        } catch (\Throwable $e) {
            Log::warning('voicemail to lead: recording copy failed', ['vm' => $id, 'error' => $e->getMessage()]);
        }
        DB::table('voicemails')->where('id', $id)->update(['lead_id' => $lead->id, 'lead_activity_id' => $act->id, 'updated_at' => now()]);
        // Everything else from this number - earlier voicemails, recorded calls - joins the lead now.
        try { if (strlen($digits) === 10) \App\Support\CallRecords::run(0, $digits); } catch (\Throwable $e) {}
        $lead->last_activity_at = now(); $lead->save();
        \App\Support\Audit::write(['user_id' => $uid, 'agency_id' => null, 'action' => 'sales.lead_from_voicemail', 'entity_type' => 'sales_lead', 'entity_id' => $lead->id,
            'payload' => json_encode(['summary' => ($created ? 'Created lead from' : 'Added to lead') . ' “' . ($lead->company ?: $lead->name) . '” from voicemail #' . $id . ' (' . $pretty . ')']),
            'ip_address' => $request->ip(), 'created_at' => now()]);

        return response()->json(['ok' => true, 'lead_id' => $lead->id, 'created' => $created, 'name' => $lead->company ?: $lead->name]);
    }

    /* TEXTS ON THE LINE (2026-10-23): Phone → Texts. App\Support\LineTexts. */

    /** GET /voice/dialer/texts — one row per number that has texted the line. */
    public function texts(Request $request): JsonResponse
    {
        $uid = (int) $request->user()->id;
        if (! \App\Support\LineTexts::allowed($uid)) return response()->json(['allowed' => false, 'conversations' => [], 'unread' => 0]);
        return response()->json(['allowed' => true, 'conversations' => \App\Support\LineTexts::conversations(), 'unread' => \App\Support\LineTexts::unread()]);
    }

    /** GET /voice/dialer/texts/{digits} — the conversation (marks it read). */
    public function textThread(Request $request, string $digits): JsonResponse
    {
        $uid = (int) $request->user()->id;
        if (! \App\Support\LineTexts::allowed($uid)) return response()->json(['message' => 'Texts on the KiddieTrac line are for the line\'s team.'], 403);
        $d = \App\Support\LineTexts::digits($digits);
        return response()->json(\App\Support\LineTexts::thread($d, $uid) + ['unread' => \App\Support\LineTexts::unread()]);
    }

    /** POST /voice/dialer/texts/{digits} {body} — reply from the line. */
    public function textSend(Request $request, string $digits): JsonResponse
    {
        $uid = (int) $request->user()->id;
        if (! \App\Support\LineTexts::allowed($uid)) return response()->json(['message' => 'Texts on the KiddieTrac line are for the line\'s team.'], 403);
        $data = $request->validate(['body' => ['required', 'string', 'max:1000'], 'attested' => ['nullable', 'boolean']]);
        $d = \App\Support\LineTexts::digits($digits);
        [$ok, $err, $id, $basis] = \App\Support\LineTexts::send($d, trim($data['body']), $uid, (bool) ($data['attested'] ?? false));
        if ($err === \App\Support\LineTexts::NEEDS_PERMISSION) {
            return response()->json(['message' => 'This number is not a KiddieTrac contact or lead and has not texted the line. Confirm you have their permission to text them.', 'needs_permission' => true], 422);
        }
        $why = ['texted_us' => 'they texted the line before', 'lead' => 'a sales lead', 'contact' => 'a KiddieTrac contact', 'permission_confirmed' => 'sender confirmed they have permission'][$basis] ?? null;
        \App\Support\Audit::write(['user_id' => $uid, 'agency_id' => null, 'action' => 'line.text_sent', 'entity_type' => 'sms_message', 'entity_id' => $id,
            'payload' => json_encode(['summary' => ($ok ? 'Texted ' : 'Text failed to ') . Dialer::pretty($d) . ' from the KiddieTrac line' . ($why ? ' (' . $why . ')' : '') . '.', 'to' => $d, 'ok' => $ok, 'consent' => $basis]),
            'created_at' => now()]);
        if (! $ok) return response()->json(['message' => $err], 422);
        return response()->json(['ok' => true] + \App\Support\LineTexts::thread($d, $uid));
    }

    /**
     * GET /voice/dialer/text-contacts?q= — people to start a text with: KiddieTrac contacts and sales
     * leads with a textable number, and recent text threads. Line team only.
     */
    public function textContacts(Request $request): JsonResponse
    {
        $uid = (int) $request->user()->id;
        if (! \App\Support\LineTexts::allowed($uid)) return response()->json(['message' => 'Texts on the KiddieTrac line are for the line\'s team.'], 403);
        $q = trim((string) $request->query('q', ''));
        $out = [];
        $add = function ($name, $sub, $phone, $source) use (&$out) {
            $d = \App\Support\LineTexts::digits($phone);
            if (strlen($d) !== 10 || isset($out[$d])) return;
            $out[$d] = ['name' => trim((string) $name) ?: Dialer::pretty($d), 'sub' => $sub, 'digits' => $d, 'pretty' => Dialer::pretty($d), 'source' => $source];
        };
        // The line's own contacts, and the contacts of the agency you are working in (those ask for
        // the permission tick when texted: they gave their number to the agency, not to the line).
        $act = (int) $request->header('X-Active-Agency-Id');
        if ($act && ! DB::table('role_assignments')->where('user_id', $uid)->where('active', 1)
            ->where(fn ($w) => $w->where('role', 'platform_admin')->orWhere('agency_id', $act))->exists()) $act = 0;   // only an agency you belong to
        $agencies = array_values(array_unique(array_filter([\App\Support\LineTexts::AGENCY, $act])));
        $cq = DB::table('contacts')->whereIn('agency_id', $agencies)->whereNull('deleted_at')
            ->where(fn ($w) => $w->whereNotNull('phone')->orWhereNotNull('mobile'));
        if ($q !== '') $cq->where(fn ($w) => $w->where('first_name', 'like', "%$q%")->orWhere('last_name', 'like', "%$q%")->orWhere('company', 'like', "%$q%")->orWhere('phone', 'like', "%$q%")->orWhere('mobile', 'like', "%$q%"));
        foreach ($cq->orderBy('first_name')->limit(60)->get() as $c) {
            $nm = trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')) ?: ($c->company ?? '');
            $add($nm, $c->company ?? 'Contact', $c->mobile ?: $c->phone, 'contact');
        }
        $lq = DB::table('sales_leads')->whereNull('deleted_at')->where(fn ($w) => $w->whereNotNull('phone')->orWhereNotNull('owner_phone'));
        if ($q !== '') $lq->where(fn ($w) => $w->where('name', 'like', "%$q%")->orWhere('company', 'like', "%$q%")->orWhere('phone', 'like', "%$q%"));
        foreach ($lq->orderByDesc('last_activity_at')->limit(60)->get(['name', 'company', 'phone', 'owner_name', 'owner_phone']) as $l) {
            if ($l->phone) $add($l->name ?: $l->company, ($l->company ?: 'Lead') . ' · lead', $l->phone, 'lead');
            if ($l->owner_phone) $add($l->owner_name ?: $l->company, ($l->company ?: 'Lead') . ' · owner', $l->owner_phone, 'lead');
        }
        return response()->json(['contacts' => array_values(array_slice($out, 0, 120))]);
    }

    /** POST /voice/dialer/inbox/seen — this person has looked at their missed calls. */
    public function inboxSeen(Request $request): JsonResponse
    {
        \App\Support\InboundCalls::markSeen((int) $request->user()->id);
        $c = \App\Support\InboundCalls::counts((int) $request->user()->id);
        unset($c['missed_seen_at']);
        return response()->json($c);
    }

    /**
     * DELETE /voice/dialer/voicemails/{id} — delete one from the dial pad (2026-10-25). Same scope
     * as playing it: only a voicemail this person is meant to see. The recording goes from our
     * storage and from Telnyx (PhoneLineController::destroy, which Platform → Phone line uses);
     * the row stays, marked deleted, so the call log and the audit trail keep what happened.
     */
    public function voicemailDelete(Request $request, int $id): JsonResponse
    {
        $uid = (int) $request->user()->id;
        $vm = \App\Support\InboundCalls::voicemailQuery(\App\Support\InboundCalls::scope($uid))->where('id', $id)->first();
        if (! $vm) {
            return response()->json(['message' => 'That voicemail is not available.'], 404);
        }

        return app(\App\Http\Controllers\Api\PhoneLineController::class)->destroy($request, $id);
    }

    /** GET /voice/dialer/voicemails/{id}/audio — play one (in scope); the first play marks it heard for everyone. */
    public function voicemailAudio(Request $request, int $id)
    {
        $uid = (int) $request->user()->id;
        $vm = \App\Support\InboundCalls::voicemailQuery(\App\Support\InboundCalls::scope($uid))->where('id', $id)->first();
        if (! $vm || ! $vm->stored_path || ! Storage::exists($vm->stored_path)) {
            return response()->json(['message' => 'That voicemail is not available.'], 404);
        }
        if (! $vm->listened_at) {
            DB::table('voicemails')->where('id', $id)->update(['listened_at' => now(), 'listened_by' => $uid]);
        }
        $ext = pathinfo($vm->stored_path, PATHINFO_EXTENSION);
        return response(Storage::get($vm->stored_path), 200, [
            'Content-Type' => $ext === 'wav' ? 'audio/wav' : 'audio/mpeg',
            'Content-Disposition' => 'inline; filename="voicemail-' . $id . '.' . $ext . '"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * GET /voice/dialer/alerts — how this person hears about missed calls and voicemails
     * (App\Support\CallAlerts), with what we would send to. PUT saves the switches.
     */
    public function alerts(Request $request): JsonResponse
    {
        $uid = (int) $request->user()->id;
        if (! \App\Support\InboundCalls::allowed(\App\Support\InboundCalls::scope($uid))) {
            return response()->json(['allowed' => false]);
        }
        $u = DB::table('users')->where('id', $uid)->first(['email', 'phone']);
        $mine = \App\Support\CallAlerts::recipients(null);
        return response()->json(['allowed' => true, 'prefs' => \App\Support\CallAlerts::prefs($uid, $mine[$uid] ?? null),
            'email' => $u->email ?? null, 'phone' => $u && $u->phone ? Dialer::pretty((string) $u->phone) : null,
            'on_alert_list' => isset($mine[$uid])]);
    }

    public function saveAlerts(Request $request): JsonResponse
    {
        $uid = (int) $request->user()->id;
        abort_unless(\App\Support\InboundCalls::allowed(\App\Support\InboundCalls::scope($uid)), 403);
        $data = $request->validate(['missed' => ['array'], 'voicemail' => ['array']]);
        $p = \App\Support\CallAlerts::savePrefs($uid, $data);
        \App\Support\Audit::write(['user_id' => $uid, 'agency_id' => null, 'action' => 'call.alerts_changed', 'entity_type' => 'user', 'entity_id' => $uid,
            'payload' => json_encode(['summary' => 'Missed-call / voicemail alerts: missed ' . implode('+', array_keys(array_filter($p['missed']))) . ', voicemail ' . implode('+', array_keys(array_filter($p['voicemail'])))]),
            'created_at' => now()]);
        return response()->json(['ok' => true, 'prefs' => $p]);
    }

    /** GET /voice/dialer/settings — the settings this person may change. */
    public function settings(Request $request): JsonResponse
    {
        $uid = (int) $request->user()->id;
        $agency = $this->activeAgency($request);
        $out = ['platform' => null, 'agency' => null];
        if ($this->isPlatformAdmin($uid)) {
            $out['platform'] = Dialer::platformConfig() + ['line' => Dialer::pretty(Dialer::LINE)];
        }
        if ($agency && $this->isAgencyAdmin($uid, $agency)) {
            $a = Dialer::agencyConfig($agency);
            $a['numbers'] = array_map(fn ($n) => ['value' => $n, 'label' => Dialer::pretty($n)], $a['numbers']);
            $out['agency'] = $a + ['agency_name' => (string) DB::table('agencies')->where('id', $agency)->value('name')];
        }
        return response()->json($out);
    }

    /** PUT /voice/dialer/settings {scope: platform|agency, enabled, roles[], record, caller_id?} */
    public function saveSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope' => ['required', 'in:platform,agency'],
            'enabled' => ['required', 'boolean'],
            'roles' => ['present', 'array'],
            'roles.*' => ['string', 'max:40'],
            'record' => ['required', 'boolean'],
            'caller_id' => ['nullable', 'string', 'max:20'],
        ]);
        $uid = (int) $request->user()->id;
        if ($data['scope'] === 'platform') {
            if (! $this->isPlatformAdmin($uid)) {
                return response()->json(['message' => 'Only a platform admin can change this.'], 403);
            }
            Dialer::savePlatformConfig($data);
            $agencyId = null;
        } else {
            $agencyId = $this->activeAgency($request);
            if (! $agencyId || ! $this->isAgencyAdmin($uid, $agencyId)) {
                return response()->json(['message' => 'Only an agency admin can change this.'], 403);
            }
            if (! Dialer::agencyConfig($agencyId)['available']) {
                return response()->json(['message' => 'Calling needs your agency\'s own Telnyx number first (Settings → Phone & carriers).'], 422);
            }
            Dialer::saveAgencyConfig($agencyId, $data);
        }
        \App\Support\Audit::write([
            'user_id' => $uid, 'agency_id' => $agencyId, 'action' => 'dialer.settings_saved', 'entity_type' => 'settings', 'entity_id' => null,
            'payload' => json_encode(['summary' => 'Changed who can call from the portal (' . $data['scope'] . '): '
                . ($data['enabled'] ? 'on for ' . (implode(', ', $data['roles']) ?: 'nobody') : 'off') . ($data['record'] ? ', recorded' : ', not recorded') . '.']),
            'created_at' => now(),
        ]);
        return $this->settings($request);
    }

    /* ── Telnyx → us, during a call ──────────────────────────────────── */

    private function xml(string $body)
    {
        return response('<?xml version="1.0" encoding="UTF-8"?>' . "\n<Response>" . $body . "</Response>\n", 200)
            ->header('Content-Type', 'application/xml');
    }

    /** /voice/dialer/hook/{id}/{token}/{step} — connect | notice | done | recorded | status */
    public function hook(Request $request, string $id, string $token = '', string $step = '')
    {
        if ($id === 'fallback') {
            /* The dialler's browser calling the app's SIP address: sip:kt{id}x{secret}@…
               That address IS the permission (the secret is the first 24 characters of the
               call's own token), and it starts the call exactly as 'connect' did. */
            // A browser's call arrives as "kt…@kiddietrac-dialer.sip.telnyx.com", WITHOUT the
            // "sip:" a server-placed one carries (2026-10-23: every browser call was hung up).
            $to = (string) $request->input('To', '') . ' ' . (string) $request->input('ToSipUri', '');
            if (! preg_match('/(?:^|[\s:])kt(\d+)x([A-Za-z0-9]{24})@/', $to, $m)) {
                return $this->xml('<Hangup/>');
            }
            $call = DB::table('outbound_calls')->where('id', (int) $m[1])->first();
            // The address works once, for five minutes: it must be this call's, unused and fresh.
            if (! $call || ! hash_equals(substr((string) $call->hook_token, 0, 24), $m[2])
                || $call->created_at < now()->subMinutes(5)->format('Y-m-d H:i:s')
                || ! empty($call->call_sid) || $call->status !== 'dialing') {
                return $this->xml('<Hangup/>');
            }
            $token = (string) $call->hook_token;
            $step = 'connect';
            DB::table('outbound_calls')->where('id', $call->id)->update(['call_sid' => mb_substr((string) $request->input('CallSid', ''), 0, 120) ?: null, 'updated_at' => now()]);
        } else {
            $call = DB::table('outbound_calls')->where('id', (int) $id)->first();
            if (! $call || ! hash_equals((string) $call->hook_token, $token)) {
                return $this->xml('<Hangup/>');
            }
        }
        $upd = fn (array $u) => DB::table('outbound_calls')->where('id', $call->id)->update($u + ['updated_at' => now()]);
        $attr = fn ($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        switch ($step) {
            case 'connect':
                // The dialler's portal answered: now ring the person they are calling.
                if (in_array($call->status, ['canceled', 'completed', 'failed'], true)) {
                    return $this->xml('<Hangup/>');
                }
                $upd(['status' => 'ringing']);
                $rec = $call->recorded
                    ? ' record="record-from-answer-dual" recordingStatusCallback="' . $attr(Dialer::hook($call, 'recorded')) . '" recordingStatusCallbackMethod="POST"'
                    : '';
                return $this->xml('<Dial callerId="' . $attr($call->from_number) . '" timeout="40" action="' . $attr(Dialer::hook($call, 'done')) . '" method="POST"' . $rec . '>'
                    . '<Number url="' . $attr(Dialer::hook($call, 'notice')) . '" method="POST">' . $attr($call->to_number) . '</Number></Dial>');

            case 'notice':
                // They answered. Before they are joined: who is calling, and the recording notice.
                $upd(['status' => 'answered', 'answered_at' => now()]);
                $who = $call->scope === 'agency'
                    ? (string) (DB::table('agencies')->where('id', $call->agency_id)->value('name') ?: 'your childcare centre')
                    : 'KiddieTrac';
                $text = 'Hello, this is a call from ' . $who . '.' . ($call->recorded ? ' This call may be recorded.' : '');
                $say = $call->scope === 'platform'
                    ? PhoneLineController::say($text)
                    : '<Say>' . htmlspecialchars($text, ENT_XML1, 'UTF-8') . '</Say>';
                return $this->xml($say);

            case 'done':
                $st = strtolower((string) $request->input('DialCallStatus', $request->input('CallStatus', 'completed')));
                $dur = (int) $request->input('DialCallDuration', 0);
                // The dialler already said they hung up: keep that, take Telnyx's talk time.
                if (in_array($call->status, ['hung-up', 'canceled'], true)) {
                    if ($dur && $call->status === 'hung-up') { $upd(['duration_seconds' => $dur]); }
                    return $this->xml('<Hangup/>');
                }
                $map = ['completed' => 'ended-by-them', 'answered' => 'ended-by-them', 'no-answer' => 'no-answer', 'busy' => 'busy', 'failed' => 'failed', 'canceled' => 'canceled'];
                $status = $map[$st] ?? 'ended-by-them';
                if ($status === 'ended-by-them' && ! $call->answered_at) {
                    $status = 'no-answer';
                }
                $upd(['status' => $status, 'duration_seconds' => $dur ?: null, 'ended_at' => now()]);
                $this->logOnLead($call, $status, $dur);
                return $this->xml('<Hangup/>');

            case 'recorded':
                $this->keepRecording($call, $request);
                return response('', 204);

            case 'status':
                // The dialler's own leg. If it ended before the call was put through, say so.
                $cs = strtolower((string) $request->input('CallStatus', ''));
                if (in_array($cs, ['completed', 'no-answer', 'busy', 'failed', 'canceled'], true) && $call->status === 'dialing') {
                    $upd(['status' => 'canceled', 'ended_at' => now()]);
                } elseif ($cs === 'completed' && in_array($call->status, ['ringing', 'answered'], true) && ! $call->ended_at) {
                    $upd(['ended_at' => now()]);
                }
                return response('', 204);
        }

        return $this->xml('<Hangup/>');
    }

    /** A call to a sales lead goes on the lead's activity. */
    private function logOnLead(object $call, string $status, int $dur): void
    {
        if (! $call->lead_id) {
            return;
        }
        try {
            $talk = intdiv($dur, 60) . ' min ' . ($dur % 60) . ' s';
            $words = ['completed' => 'Talked for ' . $talk, 'hung-up' => 'Talked for ' . $talk . ', then hung up',
                'ended-by-them' => 'Talked for ' . $talk . ', then they hung up',
                'no-answer' => 'No answer', 'busy' => 'Line busy', 'failed' => 'The call did not go through', 'canceled' => 'Hung up before they answered'][$status] ?? $status;
            \App\Models\SalesActivity::create([
                'lead_id' => $call->lead_id, 'user_id' => $call->user_id, 'type' => 'call', 'done' => true,
                'body' => 'Called ' . Dialer::pretty((string) $call->to_number) . ' from the portal — ' . $words . '.' . ($call->recorded && in_array($status, ['completed', 'hung-up', 'ended-by-them'], true) ? ' Recorded (Phone → Recent calls).' : ''),
            ]);
            DB::table('sales_leads')->where('id', $call->lead_id)->update(['last_activity_at' => now()]);
        } catch (\Throwable $e) {
            Log::warning('Dialer: lead activity not written', ['call' => $call->id, 'error' => $e->getMessage()]);
        }
    }

    /** Telnyx's recording link expires: fetch the audio now and keep it privately. */
    private function keepRecording(object $call, Request $request): void
    {
        $url = (string) $request->input('RecordingUrl', '');
        if ($url === '' || ! preg_match('#^https://#', $url)) {
            return;
        }
        try {
            $r = Http::timeout(60)->get($url);
            if (! $r->successful() || strlen($r->body()) < 200) {
                $r = Http::withToken(\App\Support\Telnyx::apiKey((int) $call->account_agency_id))->timeout(60)->get($url);
            }
            if (! $r->successful() || strlen($r->body()) < 200) {
                Log::warning('Dialer: recording not fetched', ['call' => $call->id, 'status' => $r->status()]);
                return;
            }
            $ext = str_contains(strtolower((string) $r->header('Content-Type')), 'wav') || preg_match('/\.wav(\?|$)/i', $url) ? 'wav' : 'mp3';
            $path = 'call-recordings/' . date('Y/m') . '/call-' . $call->id . '-' . \Illuminate\Support\Str::random(16) . '.' . $ext;
            Storage::disk('local')->put($path, $r->body());
            DB::table('outbound_calls')->where('id', $call->id)->update([
                'recording_path' => $path, 'recording_sid' => mb_substr((string) $request->input('RecordingSid', ''), 0, 120) ?: null,
                'recording_seconds' => (int) $request->input('RecordingDuration', 0) ?: null, 'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Dialer: recording not kept', ['call' => $call->id, 'error' => $e->getMessage()]);
        }
    }
}
