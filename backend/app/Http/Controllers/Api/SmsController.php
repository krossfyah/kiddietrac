<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\BroadcastAudience;
use App\Support\SmsGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * v22p51 — Twilio SMS.
 * Two flows:
 *   1. broadcast: director picks an audience (centre / agency / family / role)
 *      and the system sends one SMS per recipient phone number.
 *   2. emergency: pre-rendered template (closure, evacuation, illness etc.)
 *      sent immediately to a target list.
 *
 * Each send goes through sendOne() so we get a sms_messages row for audit
 * and a single point of opt-in respect.
 *
 * WHICH CARRIER CARRIES IT is no longer this file's business. Twilio was the only
 * one until 2026-09-10; Telnyx is now a second, and App\Support\SmsGateway chooses
 * between them and falls back to the other when one refuses. Every gate below still
 * runs first and applies to both -- adding a carrier must never become a way around
 * consent.
 *
 * Credentials are per agency (SmsSettingsController for Twilio, App\Support\Telnyx
 * for Telnyx). The TWILIO_* env values survive only as a platform-wide fallback for
 * an agency that has configured nothing at all.
 */
final class SmsController extends Controller
{
    public function broadcast(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $this->assertAgencyAccess($request, $agencyId);
        $data = $request->validate([
            'audience' => 'required|string|in:centre,room,agency,family,role',
            'centre_id' => 'nullable|integer',
            'room_id'  => 'nullable|integer',
            'family_id' => 'nullable|integer',
            'role'     => 'nullable|string',
            'body'     => 'required|string|max:300',
            'category' => 'nullable|string|max:40',
        ]);
        /* A narrowing audience without the thing to narrow BY used to fall through to
           "everyone in the agency". On a paid channel that turns a message for one room
           into a message for every family the agency has. Both guards now live in
           BroadcastAudience so the voice channel beside this one cannot drift from them. */
        if ($missing = BroadcastAudience::missingSelector($data)) {
            return response()->json([
                'message' => 'Choose which one to send to before sending.',
                'errors' => [$missing => ['Required for this audience.']],
            ], 422);
        }
        BroadcastAudience::assertOwned($agencyId, $data);

        $recipients = $this->resolveRecipients($agencyId, $data);
        $sent = 0; $skipped = 0;
        foreach ($recipients as $r) {
            $ok = $this->sendOne($agencyId, (int) $r->id, (string) ($r->phone ?? ''), $data['body'], $data['category'] ?? 'broadcast');
            if ($ok) $sent++; else $skipped++;
        }
        return response()->json(['sent' => $sent, 'skipped' => $skipped, 'total' => $recipients->count()]);
    }

    public function listMessages(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $rows = DB::table('sms_messages')
            ->where('agency_id', $agencyId)
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();
        return response()->json(['data' => $rows]);
    }

    /**
     * Moved into App\Support\BroadcastAudience when voice announcements were added, so
     * that both channels ask the same question and get the same answer. The SMS rule is
     * unchanged -- same joins, same sms_opt_in filter, same fail-closed `?: [0]`.
     */
    private function resolveRecipients(int $agencyId, array $data)
    {
        return BroadcastAudience::resolve($agencyId, $data, 'sms');
    }

    public function sendOne(int $agencyId, int $userId, string $phone, string $body, string $category, ?string $mediaUrl = null): bool
    {
        // Do-not-contact: never text a parent at a live agency while we are testing.
        if (\App\Support\Suppression::isUser($userId)) {
            \App\Support\Suppression::note('sms', $userId, $category);
            return false;
        }

        /* The per-agency switch. agencies.sms_enabled existed on the table and in the
           agency settings API, and NOTHING on the send path ever read it — so it was a
           toggle that changed nothing, and putting Twilio credentials in .env would have
           turned SMS on for every agency at once rather than for the one being tested.
           It belongs here beside suppression and consent, so a new category inherits all
           three by default instead of having to remember them. Logged as a skipped row,
           not dropped, so "why did that not send?" has an answer. (Anthony, 2026-09-08) */
        if (! DB::table('agencies')->where('id', $agencyId)->value('sms_enabled')) {
            DB::table('sms_messages')->insert([
                'agency_id' => $agencyId,
                'to_user_id' => $userId,
                'to_phone' => $phone,
                'body' => $body,
                'category' => $category,
                'status' => 'skipped',
                'error' => 'sms disabled for this agency',
                'created_at' => now(),
            ]);

            return false;
        }

        // Consent. The docblock above has always described this method as "a single point
        // of opt-in respect" and nothing here ever checked: resolveRecipients filtered on
        // a users.sms_opt_in column that did not exist, and the check-in path came
        // straight here with only its per-event preference. One text to someone who never
        // agreed is a complaint to the carrier and the number gets taken away, so the gate
        // belongs at the choke point where a new category inherits it by default.
        //
        // Logged as a skipped row rather than dropped silently, so "why did that not
        // send?" has an answer.
        if (! DB::table('users')->where('id', $userId)->value('sms_opt_in')) {
            DB::table('sms_messages')->insert([
                'agency_id' => $agencyId,
                'to_user_id' => $userId,
                'to_phone' => $phone,
                'body' => $body,
                'category' => $category,
                'status' => 'skipped',
                'error' => 'no sms consent',
                'created_at' => now(),
            ]);

            return false;
        }

        $rowId = DB::table('sms_messages')->insertGetId([
            'agency_id'  => $agencyId,
            'to_user_id' => $userId,
            'to_phone'   => $phone,
            'body'       => $body,
            'category'   => $category,
            'status'     => 'queued',
            'created_at' => now(),
        ]);
        if (! $phone) {
            DB::table('sms_messages')->where('id', $rowId)->update(['status' => 'skipped', 'error' => 'no phone']);

            return false;
        }

        /* WHICH CARRIER SENDS THIS is SmsGateway's decision, not this method's. It tries
           the agency's chosen carrier, and where the other one is configured it catches a
           refusal -- because the whole value of a closure notice is that it arrives in the
           next two minutes.

           The gates above are unchanged and still run first, so a second carrier is a
           second way to send a message that was already allowed, and never a way around
           suppression, the agency switch or consent. */
        $r = SmsGateway::deliver($agencyId, $phone, $body, $mediaUrl);

        if (! $r['ok']) {
            Log::warning('SMS send failed', ['user' => $userId, 'msg' => $r['error']]);
            DB::table('sms_messages')->where('id', $rowId)->update([
                'status' => 'failed',
                'provider' => $r['provider'],
                'error' => $r['error'],
            ]);

            return false;
        }

        DB::table('sms_messages')->where('id', $rowId)->update([
            'provider' => $r['provider'],
            'provider_ref' => $r['ref'],
            // Still written for a Twilio send. Nothing reads it today, but it is the id
            // support would quote back to Twilio, and it is what every row before
            // 2026-09-10 has.
            'twilio_sid' => $r['provider'] === 'twilio' ? $r['ref'] : null,
            'status' => 'sent',
            'sent_at' => now(),
            // Only when a carrier had to be stepped over: "Twilio refused, Telnyx sent
            // it" is the single most useful thing this column can hold.
            'error' => ($r['attempts'] && str_contains((string) $r['attempts'], '|')) ? $r['attempts'] : null,
        ]);

        return true;
    }

    /**
     * Twilio credentials, or null if they are not REALLY set.
     *
     * `env('TWILIO_SID')` as a truthiness test was the same trap the `sk_live_` Stripe
     * placeholder set: TWILIO_FROM shipped as a 12-character placeholder ending "xxxx",
     * which is perfectly truthy, and nothing checked it at all. Every send would have
     * been accepted locally, marked queued, then rejected by Twilio for an invalid
     * sender — a remote failure standing in for a local misconfiguration.
     *
     * Shapes: Account SID is AC + 32 hex. A sender is either E.164 (+15551234567) or a
     * Messaging Service SID (MG + 32 hex).
     */
    public static function twilioConfig(?int $agencyId = null): ?array
    {
        /* THE AGENCY'S OWN CREDENTIALS COME FIRST.
           These used to be read only from .env, which meant one Twilio account for the
           whole platform and an SSH session to change a number. An agency brings its own
           account and its own number, so it sets them on its own settings screen and they
           are stored beside the agency, encrypted (SmsSettingsController).
           The .env values are kept as a fallback so nothing that worked before stops. */
        if ($agencyId) {
            $cfg = \App\Http\Controllers\Api\SmsSettingsController::readConfig($agencyId);
            $sid = trim((string) ($cfg['account_sid'] ?? ''));
            $from = trim((string) ($cfg['from'] ?? ''));
            $keySid = trim((string) ($cfg['api_key_sid'] ?? ''));

            $dec = function (?string $v): string {
                if (empty($v)) { return ''; }
                try {
                    return \Illuminate\Support\Facades\Crypt::decryptString($v);
                } catch (\Throwable $e) {
                    // Something that cannot be decrypted is not a credential. Treated as
                    // absent rather than thrown, so it can never blow up inside a send.
                    return '';
                }
            };
            $token = $dec($cfg['auth_token'] ?? null);
            $keySecret = $dec($cfg['api_key_secret'] ?? null);

            /* AN API KEY WINS OVER THE ACCOUNT'S MASTER TOKEN.
               Twilio authenticates a key as (SK sid, secret) with the account sid passed
               separately, and recommends keys over the auth token because one can be
               revoked on its own. If an agency has bothered to create one, that is the
               credential they mean to use. */
            if ($keySid !== '' && $keySecret !== '' && preg_match('/^SK[0-9a-f]{32}$/i', $keySid)
                && self::accountOk($sid) && self::senderOk($from)) {
                return ['user' => $keySid, 'pass' => $keySecret, 'account' => $sid, 'from' => $from];
            }

            if (self::shapeOk($sid, $token, $from)) {
                return ['user' => $sid, 'pass' => $token, 'account' => $sid, 'from' => $from];
            }
            /* A HALF-CONFIGURED AGENCY MUST NOT BORROW THE PLATFORM'S NUMBER.
               Falling back here would send an agency's texts from somebody else's sender,
               which is worse than not sending: it bills the wrong account and replies go
               somewhere nobody is reading. Only an agency that has configured NOTHING
               falls through to the platform default. */
            if ($sid !== '' || $token !== '' || $from !== '' || $keySid !== '' || $keySecret !== '') {
                return null;
            }
        }

        $sid = trim((string) env('TWILIO_SID'));
        $token = trim((string) env('TWILIO_TOKEN'));
        $from = trim((string) env('TWILIO_FROM'));

        return self::shapeOk($sid, $token, $from)
            ? ['user' => $sid, 'pass' => $token, 'account' => $sid, 'from' => $from]
            : null;
    }

    /** Account SID: AC + 32 hex. */
    private static function accountOk(string $sid): bool
    {
        return (bool) preg_match('/^AC[0-9a-f]{32}$/i', $sid);
    }

    /** A sender is either E.164 or a Messaging Service SID. */
    private static function senderOk(string $from): bool
    {
        return (bool) preg_match('/^(\+[1-9]\d{7,14}|MG[0-9a-f]{32})$/i', $from);
    }

    private static function shapeOk(string $sid, string $token, string $from): bool
    {
        return self::accountOk($sid) && strlen($token) >= 24 && self::senderOk($from);
    }

    private function resolveAgencyId(Request $request): int
    {
        $activeId = (int) $request->header('X-Active-Agency-Id');
        // SECURITY (v22p94): only honour the header if the user is platform_admin
        // or holds an active role for that exact agency (else fall back below).
        if ($activeId && DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)->where(function ($w) use ($activeId) { $w->where('agency_id', $activeId)->orWhere('role', 'platform_admin'); })->exists()) return $activeId;
        // SECURITY (v22p98): a platform_admin with no valid SELECTED agency must NOT
        // fall through to their first role's agency (iLearn) — require an explicit
        // choice, else agency-scoped data leaked to a super-admin on a header-less call.
        if (DB::table('role_assignments')->where('user_id', $request->user()->id)->where('role', 'platform_admin')->where('active', true)->exists()) abort(400, 'Select an agency first.');
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
        if ($isPlatform) return;
        $hasRole = DB::table('role_assignments')->where('user_id', $u->id)
            ->where('agency_id', $agencyId)->whereIn('role', ['agency_admin', 'centre_director'])
            ->where('active', true)->exists();
        abort_unless($hasRole, 403);
    }
}
