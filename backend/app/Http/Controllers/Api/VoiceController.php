<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\BroadcastAudience;
use App\Support\Telnyx;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * VOICE ANNOUNCEMENTS (2026-09-10).
 *
 * A phone that rings, saying a short message out loud. Built for the one class of
 * notice you cannot assume anybody read: a closure, an evacuation, a lockdown, an
 * illness at the centre. Parents who never open the app answer their phone.
 *
 * -- THE SHAPE OF A CALL --
 * A text is one event. A call is a conversation with the carrier spread over a minute,
 * and each step arrives as a separate signed webhook:
 *
 *   announce()  inserts a voice_calls row, then dials.       -> queued
 *   webhook     call.answered   -> speak the announcement    -> answered
 *   webhook     call.speak.ended -> hang up                  -> spoken
 *   webhook     call.hangup     -> record how it ended       -> completed / no_answer / busy
 *
 * NOTHING IS SAID AT DIAL TIME. Telnyx accepts the dial while the phone is still
 * ringing; speaking then plays the announcement to a ringing handset that nobody is
 * holding. The speech waits for call.answered, which is what the webhook is for.
 *
 * -- WHY client_state AND NOT THE CALL ID --
 * The first webhook regularly arrives BEFORE the dial's own HTTP response has been
 * written to the database, so the row cannot be found by call_control_id at the moment
 * it is first needed. A random token is minted before the dial, travels out with it and
 * comes back on every webhook, and is therefore always resolvable. It is also the
 * tenant check: the row it names must belong to the agency in the webhook URL.
 *
 * -- CONSENT --
 * Every gate SMS has, plus one. See callOne(). Voice is more intrusive than a text and
 * the exemption it leans on is narrow, so the emergency list is closed and short
 * (BroadcastAudience::EMERGENCY_CATEGORIES) and anything outside it needs the same
 * explicit yes a text needs.
 */
final class VoiceController extends Controller
{
    /** Long enough for a closure notice read twice; short enough that nobody hangs up. */
    private const MAX_SCRIPT = 800;

    // -- sending ------------------------------------------------------------

    /** POST /admin/voice/announce */
    public function announce(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $this->assertAgencyAccess($request, $agencyId);

        $data = $request->validate([
            'audience' => 'required|string|in:centre,room,agency,family,role',
            'centre_id' => 'nullable|integer',
            'room_id' => 'nullable|integer',
            'family_id' => 'nullable|integer',
            'role' => 'nullable|string',
            'body' => 'required|string|max:' . self::MAX_SCRIPT,
            'category' => 'nullable|string|max:40',
        ]);

        $category = strtolower(trim((string) ($data['category'] ?? 'emergency'))) ?: 'emergency';

        if ($missing = BroadcastAudience::missingSelector($data)) {
            return response()->json([
                'message' => 'Choose which one to call before sending.',
                'errors' => [$missing => ['Required for this audience.']],
            ], 422);
        }
        BroadcastAudience::assertOwned($agencyId, $data);

        $recipients = BroadcastAudience::resolve($agencyId, $data, 'voice', $category);

        $placed = 0;
        $skipped = 0;
        foreach ($recipients as $r) {
            $ok = $this->callOne(
                $agencyId,
                (int) $r->id,
                (string) ($r->phone ?? ''),
                $data['body'],
                $category,
                (int) $request->user()->id
            );
            $ok ? $placed++ : $skipped++;
        }

        /* Named individually rather than counted, because a count is not an answer to
           "who did we ring?" six weeks later when somebody asks. */
        try {
            \App\Support\Audit::write([
                'user_id' => $request->user()->id,
                'agency_id' => $agencyId,
                'action' => 'voice.announcement.sent',
                'entity_type' => 'agency',
                'entity_id' => $agencyId,
                'payload' => json_encode([
                    'summary' => 'Placed ' . $placed . ' announcement call(s) to the '
                        . $data['audience'] . ' audience',
                    'audience' => $data['audience'],
                    'centre_id' => $data['centre_id'] ?? null,
                    'room_id' => $data['room_id'] ?? null,
                    'family_id' => $data['family_id'] ?? null,
                    'role' => $data['role'] ?? null,
                    'category' => $category,
                    'script' => $data['body'],
                    'placed' => $placed,
                    'skipped' => $skipped,
                    'recipients' => $recipients->map(fn ($r) => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? ''))
                        . ' <' . $r->phone . '>')->values()->all(),
                ]),
            ]);
        } catch (\Throwable $e) {
            // Auditing must never be the reason a send fails.
        }

        return response()->json([
            'placed' => $placed,
            'skipped' => $skipped,
            'total' => $recipients->count(),
        ]);
    }

    /**
     * POST /admin/voice/test-call
     *
     * Rings the number on the signed-in admin's OWN profile and nobody else's. A test
     * that can name a recipient is a test that rings a parent at 9pm to prove a
     * credential; pressing the button is the consent, and it can only ever be consent
     * for yourself.
     */
    public function testCall(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $this->assertAgencyAccess($request, $agencyId);

        $u = $request->user();
        $phone = trim((string) ($u->phone ?? ''));
        if ($phone === '') {
            return response()->json(['ok' => false, 'message' => 'Add a mobile number to your own profile first.'], 422);
        }
        if (! Telnyx::voiceConfig($agencyId)) {
            return response()->json([
                'ok' => false,
                'message' => 'Telnyx voice is not configured yet — it needs an API key, a Call Control connection and a caller number.',
            ], 422);
        }

        /* The agency switch is deliberately NOT bypassed. If it is off, a test call
           would prove the credentials work and prove nothing about whether a real
           announcement would go out -- which is the thing being tested. */
        /* PRESSING THE BUTTON IS THE CONSENT.
           Without the last argument this refuses itself: 'test' is not an emergency, so
           callOne requires the recipient to have opted in to being contacted -- and an
           admin who has never opted in to TEXT messages could then never prove their own
           voice setup works, which is the one thing this button exists to do. Here it
           only ever applies to a call the recipient asked for, on their own number, in
           the same second. A standing "do not ring me" is still honoured. */
        $ok = $this->callOne(
            $agencyId,
            (int) $u->id,
            $phone,
            'This is a test call from Kiddie Trac. Your voice announcements are working. Goodbye.',
            'test',
            (int) $u->id,
            true
        );

        if (! $ok) {
            $why = (string) (DB::table('voice_calls')->where('agency_id', $agencyId)
                ->where('to_user_id', $u->id)->orderByDesc('id')->value('error') ?: 'the call could not be placed');

            return response()->json(['ok' => false, 'message' => 'Not placed — ' . $why], 422);
        }

        return response()->json(['ok' => true, 'message' => 'Calling ' . $phone . ' now.']);
    }

    /**
     * Place ONE call. The single choke point, so a new category inherits every gate by
     * default instead of having to remember them -- the same reasoning as
     * SmsController::sendOne, and the gates are deliberately in the same order.
     */
    public function callOne(
        int $agencyId,
        int $userId,
        string $phone,
        string $script,
        string $category,
        ?int $startedBy = null,
        bool $bypassOptIn = false
    ): bool {
        // 1. Do-not-contact: never ring a parent at a live agency while we are testing.
        if (\App\Support\Suppression::isUser($userId)) {
            \App\Support\Suppression::note('voice', $userId, $category);

            return false;
        }

        $write = function (string $status, ?string $error) use ($agencyId, $userId, $phone, $script, $category, $startedBy): bool {
            DB::table('voice_calls')->insert([
                'agency_id' => $agencyId,
                'to_user_id' => $userId,
                'to_phone' => $phone,
                'body' => $script,
                'category' => $category,
                'provider' => 'telnyx',
                'client_state' => 'kt_' . bin2hex(random_bytes(14)),
                'status' => $status,
                'error' => $error,
                'started_by_id' => $startedBy,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return false;
        };

        // 2. The per-agency master switch. Off by default, and turning it on is a
        //    deliberate act -- credentials arriving on a settings screen must not be
        //    enough on their own to start ringing parents.
        if (! DB::table('agencies')->where('id', $agencyId)->value('voice_enabled')) {
            return $write('skipped', 'voice calls disabled for this agency');
        }

        $user = DB::table('users')->where('id', $userId)->select('sms_opt_in', 'voice_opt_out')->first();

        // 3. A standing "do not ring me" wins over everything, emergency included.
        if ($user && (int) ($user->voice_opt_out ?? 0) === 1) {
            return $write('skipped', 'this person has opted out of phone calls');
        }

        /* 4. Anything that is not an emergency needs an actual yes. The emergency list
              is closed and short on purpose; see BroadcastAudience.

              $bypassOptIn has exactly TWO legitimate callers and both are deliberate
              administrative acts, not sends to an audience:
                - testCall(), where the recipient IS the person who pressed the button,
                  on the number from their own profile, a second ago;
                - SmsSettingsController::testSend(), where an admin typed one number to
                  prove the carrier works and has confirmed they control it.

              It does NOT bypass gate 3. A standing "do not ring me" stays absolute, and
              a number that belongs to somebody who has opted out is refused by the
              caller before it ever reaches here. */
        if (! $bypassOptIn && ! BroadcastAudience::isEmergency($category)
            && ! ($user && (int) ($user->sms_opt_in ?? 0) === 1)) {
            return $write('skipped', 'no consent for non-emergency calls');
        }

        $cfg = Telnyx::voiceConfig($agencyId);
        if (! $cfg || $phone === '') {
            return $write('skipped', ! $cfg ? 'telnyx voice not configured' : 'no phone');
        }

        /* THE NUMBER THAT IS DIALLED IS THE NUMBER THAT GOES IN THE LOG.
           Numbers are stored as typed -- "(416) 989-2621" -- and Telnyx only accepts
           E.164. Normalising here rather than only inside dial() means the row says what
           was actually rung, so a support question six weeks later is answerable, and an
           unusable number is refused with a reason instead of as a carrier error. */
        $dialTo = Telnyx::e164($phone);
        if ($dialTo === '') {
            return $write('skipped', 'unusable number: ' . $phone);
        }
        $phone = $dialTo;

        /* THE ROW IS WRITTEN BEFORE THE DIAL, and carries the token the webhooks will
           come back with. Telnyx's first webhook regularly beats this method's own
           HTTP response, so a row created afterwards would not exist when it is first
           needed. */
        $state = 'kt_' . bin2hex(random_bytes(14));
        $rowId = DB::table('voice_calls')->insertGetId([
            'agency_id' => $agencyId,
            'to_user_id' => $userId,
            'to_phone' => $phone,
            'body' => $script,
            'category' => $category,
            'provider' => 'telnyx',
            'client_state' => $state,
            'status' => 'queued',
            'started_by_id' => $startedBy,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $r = Telnyx::dial($cfg, $phone, $state);
        if (! $r['ok']) {
            DB::table('voice_calls')->where('id', $rowId)->update([
                'status' => 'failed', 'error' => $r['error'], 'updated_at' => now(),
            ]);

            return false;
        }

        DB::table('voice_calls')->where('id', $rowId)->update([
            'provider_ref' => $r['id'], 'status' => 'ringing', 'updated_at' => now(),
        ]);

        return true;
    }

    // -- reading ------------------------------------------------------------

    /** GET /admin/voice/calls */
    public function calls(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $this->assertAgencyAccess($request, $agencyId);

        $rows = DB::table('voice_calls as v')
            ->leftJoin('users as u', 'u.id', '=', 'v.to_user_id')
            ->where('v.agency_id', $agencyId)
            ->orderByDesc('v.created_at')
            ->limit(200)
            ->select(
                'v.id', 'v.to_phone', 'v.body', 'v.category', 'v.status', 'v.error',
                'v.answered_at', 'v.ended_at', 'v.duration_secs', 'v.created_at',
                DB::raw("TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) as to_name")
            )
            ->get();

        return response()->json(['data' => $rows]);
    }

    // -- the webhook --------------------------------------------------------

    /**
     * POST /voice/telnyx/webhook/{agency}
     *
     * Public -- Telnyx is not carrying a session -- so the request is signature-verified
     * instead, with the agency's own Ed25519 public key. The agency is in the PATH
     * because a webhook does not otherwise say whose account it came from, and the key
     * has to be chosen before anything in the body can be trusted.
     *
     * Always answers 200 once the signature is good. Telnyx retries a non-2xx, and a
     * retried call.answered speaks the announcement down the line a second time.
     */
    public function webhook(Request $request, int $agency): Response
    {
        $raw = $request->getContent();

        if (! Telnyx::verifyWebhook(
            Telnyx::publicKey($agency),
            (string) $request->header('telnyx-signature-ed25519', ''),
            (string) $request->header('telnyx-timestamp', ''),
            $raw
        )) {
            Log::warning('Telnyx voice webhook: bad signature', ['agency' => $agency]);

            return response('', 403);
        }

        $body = json_decode($raw, true) ?: [];
        $event = (string) ($body['data']['event_type'] ?? '');
        $p = (array) ($body['data']['payload'] ?? []);

        $state = base64_decode((string) ($p['client_state'] ?? ''), true) ?: '';
        if ($state === '') {
            // Events for calls we did not place (an inbound call to the number, say).
            // Acknowledged so Telnyx stops retrying, and otherwise ignored.
            return response('', 200);
        }

        /* THE TENANT CHECK. The token is unguessable, but the agency in the URL is not,
           and a row belonging to another agency must never be driven from this URL --
           that would speak one agency's announcement down another's call. */
        $call = DB::table('voice_calls')
            ->where('client_state', $state)
            ->where('agency_id', $agency)
            ->first();

        if (! $call) {
            Log::warning('Telnyx voice webhook: no matching call', ['agency' => $agency, 'event' => $event]);

            return response('', 200);
        }

        $callControlId = (string) ($p['call_control_id'] ?? $call->provider_ref ?? '');
        $cfg = Telnyx::voiceConfig($agency);

        try {
            switch ($event) {
                case 'call.answered':
                    DB::table('voice_calls')->where('id', $call->id)->update([
                        'status' => 'answered',
                        'answered_at' => now(),
                        'provider_ref' => $callControlId ?: $call->provider_ref,
                        'updated_at' => now(),
                    ]);
                    if ($cfg && $callControlId) {
                        $r = Telnyx::speak($cfg, $callControlId, (string) $call->body, $state);
                        if (! $r['ok']) {
                            DB::table('voice_calls')->where('id', $call->id)->update([
                                'status' => 'failed', 'error' => 'speak: ' . $r['error'], 'updated_at' => now(),
                            ]);
                        }
                    }
                    break;

                case 'call.speak.ended':
                    DB::table('voice_calls')->where('id', $call->id)
                        ->update(['status' => 'spoken', 'updated_at' => now()]);
                    /* Hang up ourselves rather than waiting out time_limit_secs. The
                       announcement is the whole call, and a line held open after it is
                       two minutes of silence somebody is being billed for. */
                    if ($cfg && $callControlId) {
                        Telnyx::hangup($cfg, $callControlId);
                    }
                    break;

                case 'call.hangup':
                    $cause = strtolower((string) ($p['hangup_cause'] ?? ''));
                    /* "spoken" already means the message was delivered, so a normal
                       hangup after it is a completed call. A hangup BEFORE it says how
                       the attempt ended, and those causes are worth keeping apart:
                       nobody answering is a different problem from a dead number. */
                    $status = $call->status === 'spoken' ? 'completed' : match ($cause) {
                        'busy' => 'busy',
                        'timeout', 'time_out', 'no_answer' => 'no_answer',
                        'call_rejected', 'rejected' => 'rejected',
                        'normal_clearing' => 'completed',
                        default => 'failed',
                    };

                    DB::table('voice_calls')->where('id', $call->id)->update([
                        'status' => $status,
                        'ended_at' => now(),
                        /* abs(), because diffInSeconds is SIGNED in Carbon 3 and the sign
                           depends on which way round the two moments are — a duration
                           that comes out negative is stored as a huge unsigned int. */
                        'duration_secs' => $call->answered_at
                            ? (int) abs(\Illuminate\Support\Carbon::parse($call->answered_at)->diffInSeconds(now()))
                            : 0,
                        'error' => $status === 'completed' ? $call->error : ($cause ?: null),
                        'updated_at' => now(),
                    ]);
                    break;

                default:
                    // call.initiated, call.bridged, and everything else we do not act on.
                    break;
            }
        } catch (\Throwable $e) {
            // A failure here must not become a non-2xx: Telnyx would retry, and a
            // retried call.answered speaks the announcement a second time.
            Log::error('Telnyx voice webhook handler failed', [
                'agency' => $agency, 'event' => $event, 'msg' => $e->getMessage(),
            ]);
        }

        return response('', 200);
    }

    // -- tenancy ------------------------------------------------------------
    // Copied verbatim from SmsController rather than reached for from a trait: this is
    // the pair that controller has always used, and a voice broadcast must resolve the
    // agency exactly the way the text broadcast beside it does.

    private function resolveAgencyId(Request $request): int
    {
        $activeId = (int) $request->header('X-Active-Agency-Id');
        if ($activeId && DB::table('role_assignments')->where('user_id', $request->user()->id)
            ->where('active', true)
            ->where(function ($w) use ($activeId) {
                $w->where('agency_id', $activeId)->orWhere('role', 'platform_admin');
            })->exists()) {
            return $activeId;
        }
        // A platform_admin with no valid SELECTED agency must NOT fall through to their
        // first role's agency -- require an explicit choice.
        if (DB::table('role_assignments')->where('user_id', $request->user()->id)
            ->where('role', 'platform_admin')->where('active', true)->exists()) {
            abort(400, 'Select an agency first.');
        }
        $first = DB::table('role_assignments')
            ->where('user_id', $request->user()->id)
            ->where('active', true)
            ->value('agency_id');
        abort_unless($first, 400);

        return (int) $first;
    }

    private function assertAgencyAccess(Request $request, int $agencyId): void
    {
        $u = $request->user();
        $isPlatform = DB::table('role_assignments')->where('user_id', $u->id)
            ->where('role', 'platform_admin')->where('active', true)->exists();
        if ($isPlatform) {
            return;
        }
        $hasRole = DB::table('role_assignments')->where('user_id', $u->id)
            ->where('agency_id', $agencyId)->whereIn('role', ['agency_admin', 'centre_director'])
            ->where('active', true)->exists();
        abort_unless($hasRole, 403);
    }
}
