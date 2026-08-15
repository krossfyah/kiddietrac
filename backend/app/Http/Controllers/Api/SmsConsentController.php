<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Consent to be texted, and the keyword replies a carrier requires (2026-08-14).
 *
 * The public policy at kiddietrac.com/privacy#sms now promises three things: that consent
 * is asked for explicitly, that a confirmation follows it, and that STOP and HELP work.
 * This is the half that makes those true. The wording below is quoted verbatim in that
 * policy — if it changes here it has to change there, which is why the strings are
 * constants rather than scattered through the methods.
 *
 * Two ways in, and they are not equal:
 *   • the app  — the consent screen, an explicit yes or no;
 *   • the handset — STOP / START / HELP replied to a message.
 * A reply from the handset always wins. Someone texting STOP has revoked consent whatever
 * the app thinks, and the app must not be able to quietly re-enrol them.
 */
final class SmsConsentController extends Controller
{
    /**
     * The exact wording shown on the consent screen. Stored against the user when they
     * agree, so we can say what they were shown and not merely that they tapped yes.
     * Bump the version whenever the wording changes.
     */
    public const CONSENT_VERSION = 'sms-consent-v1-20260814';

    public const CONSENT_TEXT =
        'KiddieTrac can text you when your child is signed in or out, plus urgent notices '
        . 'from your agency. Message frequency varies. Message and data rates may apply. '
        . 'Reply STOP to opt out or HELP for help. See our Terms of Use and Privacy Policy '
        . 'at kiddietrac.com/privacy.';

    /** Sent once, immediately, when someone opts in. */
    public const MSG_CONFIRM =
        "KiddieTrac: You're signed up for text alerts from %s. Msg frequency varies. "
        . 'Msg & data rates may apply. Reply HELP for help, STOP to cancel. kiddietrac.com/privacy';

    public const MSG_HELP =
        'KiddieTrac alerts from %s. Help: info@kiddietrac.com. Msg & data rates may apply. '
        . 'Reply STOP to cancel. kiddietrac.com/privacy';

    public const MSG_STOP =
        'KiddieTrac: You have been unsubscribed and will get no more texts. Reply START to resume.';

    /** Keywords a carrier expects to work. Matched on the whole message, case-insensitive. */
    private const STOP_WORDS = ['stop', 'stopall', 'unsubscribe', 'cancel', 'end', 'quit', 'optout', 'opt-out'];
    private const START_WORDS = ['start', 'unstop', 'yes', 'optin', 'opt-in'];
    private const HELP_WORDS = ['help', 'info'];

    /** What the app needs to draw the consent screen. */
    public function show(Request $request): JsonResponse
    {
        $u = $request->user();

        return response()->json([
            'opted_in' => (bool) $u->sms_opt_in,
            'opted_in_at' => $u->sms_opt_in_at,
            'opted_out_at' => $u->sms_opt_out_at,
            'source' => $u->sms_consent_source,
            'phone' => $u->phone,
            // The app renders this rather than carrying its own copy, so the wording
            // someone agrees to is the wording that gets stored.
            'consent_text' => self::CONSENT_TEXT,
            'consent_version' => self::CONSENT_VERSION,
            'privacy_url' => 'https://www.kiddietrac.com/privacy#sms',
            'terms_url' => 'https://www.kiddietrac.com/privacy#terms',
        ]);
    }

    /**
     * Record an explicit yes or no from the app.
     *
     * `agree` is required and has no default: the policy says a prompt you ignore is a no,
     * and a missing field is not an answer either way.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['agree' => 'required|boolean']);
        $u = $request->user();

        if (! $data['agree']) {
            $this->optOut((int) $u->id, 'app');

            return response()->json(['opted_in' => false]);
        }

        // Someone who replied STOP from their handset stays opted out. Re-enrolling them
        // from a screen would be exactly the thing STOP exists to prevent; they have to
        // reply START from the number that revoked it.
        if ($u->sms_opt_out_at && $u->sms_consent_source === 'sms') {
            return response()->json([
                'opted_in' => false,
                'blocked' => true,
                'message' => 'This number replied STOP to a text message. Reply START from that '
                    . 'phone to start receiving messages again.',
            ], 409);
        }

        if (! trim((string) $u->phone)) {
            return response()->json([
                'opted_in' => false,
                'message' => 'Add a mobile number to your profile first.',
            ], 422);
        }

        $this->optIn((int) $u->id, 'app');

        // The confirmation the policy promises. Sent through the ordinary sender so it is
        // logged in sms_messages like everything else.
        $agency = $this->agencyNameFor((int) $u->id);
        app(SmsController::class)->sendOne(
            (int) ($u->agency_id ?? 0),
            (int) $u->id,
            (string) $u->phone,
            sprintf(self::MSG_CONFIRM, $agency),
            'consent_confirm'
        );

        return response()->json(['opted_in' => true]);
    }

    /**
     * Twilio's inbound webhook. Public — Twilio is not carrying a session — so the request
     * is signature-verified instead.
     *
     * Twilio itself blocks a number that has texted STOP, so a missed webhook does not mean
     * messages keep arriving. It does mean we would carry on QUEUEING them, showing centres
     * a delivery record for messages nobody received, and leave the app claiming they are
     * opted in. So the state is recorded here regardless of what the carrier does.
     */
    public function inbound(Request $request): Response
    {
        if (! $this->signatureValid($request)) {
            Log::warning('SMS inbound: bad Twilio signature', ['from' => $request->input('From')]);

            return response('', 403);
        }

        $from = trim((string) $request->input('From', ''));
        $body = strtolower(trim((string) $request->input('Body', '')));
        // Punctuation and stray whitespace are common in a real reply ("STOP." / " stop ").
        $word = trim(preg_replace('/[^a-z\-]/', '', $body) ?? '');

        $user = $this->userByPhone($from);
        $reply = null;

        if (in_array($word, self::STOP_WORDS, true)) {
            if ($user) {
                $this->optOut((int) $user->id, 'sms');
            }
            $reply = self::MSG_STOP;
        } elseif (in_array($word, self::START_WORDS, true)) {
            if ($user) {
                $this->optIn((int) $user->id, 'sms');
            }
            $reply = sprintf(self::MSG_CONFIRM, $user ? $this->agencyNameFor((int) $user->id) : 'your agency');
        } elseif (in_array($word, self::HELP_WORDS, true)) {
            $reply = sprintf(self::MSG_HELP, $user ? $this->agencyNameFor((int) $user->id) : 'your agency');
        }

        Log::info('SMS inbound', ['from' => $from, 'word' => $word, 'matched' => $reply !== null, 'user' => $user->id ?? null]);

        // Empty TwiML for anything we do not recognise — silence is the right answer to a
        // parent replying "thanks" to an automated number.
        if ($reply === null) {
            return response('<?xml version="1.0" encoding="UTF-8"?><Response></Response>', 200)
                ->header('Content-Type', 'text/xml');
        }

        // Twilio's own Advanced Opt-Out, if switched on for the messaging service, already
        // answers these keywords. Set SMS_KEYWORD_REPLIES=false then, or the sender gets
        // two replies to one STOP.
        if (! filter_var(env('SMS_KEYWORD_REPLIES', true), FILTER_VALIDATE_BOOLEAN)) {
            return response('<?xml version="1.0" encoding="UTF-8"?><Response></Response>', 200)
                ->header('Content-Type', 'text/xml');
        }

        return response(
            '<?xml version="1.0" encoding="UTF-8"?><Response><Message>'
            . htmlspecialchars($reply, ENT_XML1) . '</Message></Response>',
            200
        )->header('Content-Type', 'text/xml');
    }

    // ── internals ───────────────────────────────────────────────────────────

    private function optIn(int $userId, string $source): void
    {
        DB::table('users')->where('id', $userId)->update([
            'sms_opt_in' => 1,
            'sms_opt_in_at' => now(),
            'sms_opt_out_at' => null,
            'sms_consent_source' => $source,
            'sms_consent_text' => self::CONSENT_VERSION . ' :: ' . self::CONSENT_TEXT,
            'updated_at' => now(),
        ]);
    }

    private function optOut(int $userId, string $source): void
    {
        DB::table('users')->where('id', $userId)->update([
            'sms_opt_in' => 0,
            'sms_opt_out_at' => now(),
            'sms_consent_source' => $source,
            'updated_at' => now(),
        ]);

        // Silence every SMS category at once. A per-event preference left switched on
        // would be a switch that quietly does nothing, and would text them again the
        // moment the opt-in check was loosened.
        DB::table('notification_prefs')->where('user_id', $userId)->update([
            'sms' => 0,
            'updated_at' => now(),
        ]);
    }

    /**
     * Match an inbound number back to a person.
     *
     * Compared on the last 10 digits: we store numbers as typed — "(416) 570-2747" —
     * and Twilio sends E.164, so a string comparison never matches.
     */
    private function userByPhone(string $from)
    {
        $digits = preg_replace('/\D/', '', $from) ?? '';
        if (strlen($digits) < 10) {
            return null;
        }
        $last10 = substr($digits, -10);

        return DB::table('users')
            ->whereRaw("RIGHT(REGEXP_REPLACE(COALESCE(phone,''), '[^0-9]', ''), 10) = ?", [$last10])
            ->orderByDesc('id')
            ->first();
    }

    private function agencyNameFor(int $userId): string
    {
        $name = DB::table('role_assignments as ra')
            ->join('agencies as a', 'a.id', '=', 'ra.agency_id')
            ->where('ra.user_id', $userId)
            ->where('ra.active', true)
            ->value('a.name');

        return (string) ($name ?: 'your agency');
    }

    private function signatureValid(Request $request): bool
    {
        $token = (string) env('TWILIO_TOKEN', '');
        if ($token === '') {
            // Unconfigured means nothing can be verified, so nothing is accepted. An open
            // endpoint here would let anyone opt a number in or out by posting to it.
            return false;
        }
        if (! class_exists(\Twilio\Security\RequestValidator::class)) {
            return false;
        }

        $validator = new \Twilio\Security\RequestValidator($token);
        $signature = (string) $request->header('X-Twilio-Signature', '');
        // The URL Twilio signed is the one configured on the number, which is https even
        // where the app sees http behind the proxy.
        $url = preg_replace('~^http://~', 'https://', $request->fullUrl()) ?? $request->fullUrl();

        return $validator->validate($signature, $url, $request->post());
    }
}
