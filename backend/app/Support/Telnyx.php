<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use phpseclib3\Crypt\EC;

/**
 * TELNYX -- the second carrier (2026-09-10).
 *
 * KiddieTrac has sent text messages through Twilio since v22p51. Telnyx is added
 * beside it, not instead of it: an agency picks which one sends, and if both are
 * configured the other one catches a failure. A closure notice that does not arrive
 * because one carrier is having a bad morning is the case this exists for.
 *
 * It also brings something Twilio was never wired up for here -- VOICE. An
 * evacuation is the one message you cannot assume somebody read, and a phone that
 * rings is answered by people who never open the app.
 *
 * -- WHY THIS IS RAW HTTP AND NOT AN SDK --
 * telnyx/telnyx-php would have to be composer-installed on a shared GoDaddy host
 * where `composer install` is not part of the deploy, and its webhook verifier needs
 * ext-sodium -- which this PHP does not have (see verifyWebhook below). Four
 * endpoints against a documented JSON API is less code than the wrapper.
 *
 * -- CREDENTIALS --
 * Per agency, in `agencies.settings.telnyx`, secrets Crypt::encryptString'd exactly
 * like the Twilio ones next door (SmsSettingsController). There is deliberately NO
 * .env fallback: a half-configured agency borrowing the platform's number bills the
 * wrong account and sends replies where nobody is reading, which is the trap
 * SmsController::twilioConfig() already documents at length.
 *
 * One API key covers messaging AND voice, so it is stored once here rather than
 * twice on two settings screens.
 */
final class Telnyx
{
    public const BASE = 'https://api.telnyx.com/v2';

    /** How long a signed webhook stays acceptable. Telnyx's own SDKs use 5 minutes. */
    private const WEBHOOK_TOLERANCE = 300;

    /** API key: "KEY" + a long body. Shape-checked so a pasted public key is caught here. */
    private const KEY_RE = '/^KEY[0-9A-Za-z_-]{20,}$/';

    /** A sender number, in full international form. */
    private const FROM_RE = '/^\+[1-9]\d{7,14}$/';

    /** UUID, for messaging profile ids. */
    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    // -- stored settings ----------------------------------------------------

    /** The raw block as stored -- secrets still encrypted. */
    public static function readConfig(int $agencyId): array
    {
        $row = DB::table('agencies')->where('id', $agencyId)->select('settings')->first();
        $settings = ($row && $row->settings) ? (json_decode((string) $row->settings, true) ?: []) : [];

        return (isset($settings['telnyx']) && is_array($settings['telnyx'])) ? $settings['telnyx'] : [];
    }

    public static function writeConfig(int $agencyId, array $cfg): void
    {
        $row = DB::table('agencies')->where('id', $agencyId)->select('settings')->first();
        $settings = ($row && $row->settings) ? (json_decode((string) $row->settings, true) ?: []) : [];
        $settings['telnyx'] = $cfg;
        DB::table('agencies')->where('id', $agencyId)
            ->update(['settings' => json_encode($settings), 'updated_at' => now()]);
    }

    /**
     * The agency's API key in the clear, or '' if there isn't a usable one.
     *
     * Never throws. Something that will not decrypt is not a credential -- treating it
     * as absent means a rotated APP_KEY degrades to "not configured" rather than to a
     * fatal inside a send loop.
     */
    public static function apiKey(int $agencyId): string
    {
        $enc = (string) (self::readConfig($agencyId)['api_key'] ?? '');
        if ($enc === '') {
            return '';
        }
        try {
            return trim(Crypt::decryptString($enc));
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Everything needed to send a text, or null.
     *
     * Shape-checked rather than truthiness-checked, for the reason the Twilio config
     * spells out: TWILIO_FROM shipped as a truthy placeholder ending "xxxx" and every
     * send was accepted locally and rejected remotely. A local misconfiguration
     * should fail locally.
     */
    public static function smsConfig(int $agencyId): ?array
    {
        $c = self::readConfig($agencyId);
        $key = self::apiKey($agencyId);
        $from = trim((string) ($c['sms_from'] ?? ''));
        $profile = trim((string) ($c['messaging_profile_id'] ?? ''));

        if (! preg_match(self::KEY_RE, $key)) {
            return null;
        }

        // A number OR a messaging profile is enough -- a profile picks the sender from
        // its own pool, which is how a number pool or an alphanumeric id is used.
        $hasFrom = (bool) preg_match(self::FROM_RE, $from);
        $hasProfile = (bool) preg_match(self::UUID_RE, $profile);
        if (! $hasFrom && ! $hasProfile) {
            return null;
        }

        return [
            'api_key' => $key,
            'from' => $hasFrom ? $from : '',
            'messaging_profile_id' => $hasProfile ? $profile : '',
        ];
    }

    /** Everything needed to place a call, or null. */
    public static function voiceConfig(int $agencyId): ?array
    {
        $c = self::readConfig($agencyId);
        $key = self::apiKey($agencyId);
        $conn = trim((string) ($c['voice_connection_id'] ?? ''));
        $from = trim((string) ($c['voice_from'] ?? ''));

        if (! preg_match(self::KEY_RE, $key) || ! preg_match(self::FROM_RE, $from)) {
            return null;
        }

        // A Call Control Application id. Telnyx shows it as a long numeric id on older
        // accounts and a UUID on newer ones, so this is a loose check on purpose.
        if (! preg_match('/^[0-9a-zA-Z-]{6,64}$/', $conn)) {
            return null;
        }

        return [
            'api_key' => $key,
            'connection_id' => $conn,
            'from' => $from,
            'caller_name' => trim((string) ($c['voice_caller_name'] ?? '')),
            'voice' => trim((string) ($c['voice_voice'] ?? '')) ?: 'female',
            'language' => trim((string) ($c['voice_language'] ?? '')) ?: 'en-US',
        ];
    }

    /** The Ed25519 public key this agency's webhooks are signed with, base64 as pasted. */
    public static function publicKey(int $agencyId): string
    {
        return trim((string) (self::readConfig($agencyId)['public_key'] ?? ''));
    }

    // -- phone numbers ------------------------------------------------------

    /**
     * A number Telnyx will accept, or '' if this one cannot be made into one.
     *
     * KiddieTrac STORES NUMBERS AS TYPED -- "(416) 989-2621", "416-989-2621",
     * "4169892621" -- because that is what a parent writes on a form and what a director
     * expects to see back. Twilio has been forgiving about it. Telnyx is not: `to` must
     * be E.164 or the request is refused, and the refusal names the field rather than the
     * problem, so it reads as a credential fault.
     *
     * Deliberately conservative. Ten digits are assumed North American, which is true of
     * every number on this platform; eleven starting with 1 are the same number written
     * out. Anything else is only accepted if it is already written as +<digits>, because
     * GUESSING a country code sends a message or rings a phone in the wrong country, and
     * a skipped row saying "unusable number" is a far better outcome than that.
     */
    public static function e164(string $phone): string
    {
        $phone = trim($phone);
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        // Already written internationally -- trust it, having checked the shape.
        if (str_starts_with($phone, '+')) {
            return preg_match(self::FROM_RE, '+' . $digits) ? '+' . $digits : '';
        }

        if (strlen($digits) === 10) {
            return '+1' . $digits;
        }
        if (strlen($digits) === 11 && $digits[0] === '1') {
            return '+' . $digits;
        }

        // No country code and not a length we can reason about. Refused rather than
        // guessed at.
        return '';
    }

    // -- the API ------------------------------------------------------------

    /**
     * Send one text.
     *
     * Returns ['ok' => bool, 'id' => ?string, 'error' => ?string]. It does not throw:
     * the caller is a send loop that has to record a result per recipient and carry on
     * to the next one.
     */
    public static function sendSms(array $cfg, string $to, string $body, ?string $mediaUrl = null): array
    {
        $e164 = self::e164($to);
        if ($e164 === '') {
            return ['ok' => false, 'body' => [], 'id' => null, 'status' => 400,
                'error' => 'unusable number: "' . $to . '" is not in a form Telnyx accepts'];
        }

        $payload = ['to' => $e164, 'text' => $body];
        if ($cfg['from'] !== '') {
            $payload['from'] = $cfg['from'];
        }
        if ($cfg['messaging_profile_id'] !== '') {
            $payload['messaging_profile_id'] = $cfg['messaging_profile_id'];
        }
        if ($mediaUrl) {
            // Telnyx switches to MMS on its own when media is attached; naming the type
            // makes the intent explicit and the failure legible when a carrier refuses.
            $payload['media_urls'] = [$mediaUrl];
            $payload['type'] = 'MMS';
        }

        $res = self::call($cfg['api_key'], 'POST', '/messages', $payload);
        if (! $res['ok']) {
            return $res;
        }

        return ['ok' => true, 'id' => (string) ($res['body']['data']['id'] ?? ''), 'error' => null];
    }

    /**
     * Ring a number. Nothing is SAID yet -- Telnyx answers the dial request immediately
     * and the speech has to wait for the call.answered webhook, because a recording
     * that starts while the phone is still ringing is a recording nobody hears.
     *
     * $clientState travels out with the dial and comes back on every webhook for the
     * call, base-64 encoded. That is what carries our row id: the webhook can and does
     * arrive before this HTTP response has been written to the database, so looking the
     * call up by the id in the response is a race we would lose.
     */
    public static function dial(array $cfg, string $to, string $clientState, array $extra = []): array
    {
        $e164 = self::e164($to);
        if ($e164 === '') {
            return ['ok' => false, 'body' => [], 'id' => null, 'status' => 400,
                'error' => 'unusable number: "' . $to . '" is not in a form Telnyx accepts'];
        }

        $payload = array_merge([
            'connection_id' => $cfg['connection_id'],
            'to' => $e164,
            'from' => $cfg['from'],
            'client_state' => base64_encode($clientState),
            'timeout_secs' => 30,
            // A voice announcement is seconds long. Without a cap, a call answered by a
            // machine that never hangs up bills for four hours.
            'time_limit_secs' => 120,
        ], $extra);

        if (($cfg['caller_name'] ?? '') !== '') {
            $payload['from_display_name'] = mb_substr($cfg['caller_name'], 0, 128);
        }

        $res = self::call($cfg['api_key'], 'POST', '/calls', $payload);
        if (! $res['ok']) {
            return $res;
        }

        return [
            'ok' => true,
            'id' => (string) ($res['body']['data']['call_control_id'] ?? ''),
            'error' => null,
        ];
    }

    /** Say something on a call that has been answered. */
    public static function speak(array $cfg, string $callControlId, string $text, string $clientState = ''): array
    {
        /* "female" and "male" are only accepted at the basic service level; anything
           else is a Provider.Model.VoiceId name and needs premium. Choosing the level
           from the shape of the name means an admin who types a Polly voice gets it,
           and one who leaves the default alone is not billed for premium. */
        $voice = ($cfg['voice'] ?? '') !== '' ? $cfg['voice'] : 'female';
        $basic = in_array(strtolower($voice), ['female', 'male'], true);

        $payload = [
            'payload' => mb_substr($text, 0, 3000),
            'payload_type' => 'text',
            'voice' => $basic ? strtolower($voice) : $voice,
            'service_level' => $basic ? 'basic' : 'premium',
            'language' => $cfg['language'],
        ];
        if ($clientState !== '') {
            $payload['client_state'] = base64_encode($clientState);
        }

        return self::call($cfg['api_key'], 'POST', '/calls/' . rawurlencode($callControlId) . '/actions/speak', $payload);
    }

    public static function hangup(array $cfg, string $callControlId): array
    {
        return self::call($cfg['api_key'], 'POST', '/calls/' . rawurlencode($callControlId) . '/actions/hangup', []);
    }

    /**
     * Prove the credentials without spending anything.
     *
     * Reads the account balance, for the same reason the Twilio check fetches the
     * account rather than texting somebody: a test that sends needs a consenting
     * recipient and costs money to discover a typo.
     */
    public static function whoami(string $apiKey): array
    {
        return self::call($apiKey, 'GET', '/balance', null);
    }

    // -- webhooks -----------------------------------------------------------

    /**
     * Is this really from Telnyx?
     *
     * Telnyx signs `<timestamp>|<raw body>` with Ed25519 and sends the signature
     * base-64 in `telnyx-signature-ed25519`, with the unix timestamp in
     * `telnyx-timestamp`. The public key is per ACCOUNT, from Mission Control ->
     * Keys & Credentials -> Public Key, which is why it is stored per agency.
     *
     * -- ext-sodium IS NOT INSTALLED ON THIS HOST --
     * `sodium_crypto_sign_verify_detached` does not exist here and OpenSSL on this box
     * has no Ed25519 either, so the usual one-liner and telnyx/telnyx-php's own
     * verifier are both unavailable. phpseclib3 is already in vendor/ and implements
     * Ed25519 in pure PHP; verified on this host against RFC 8032 section 7.1 test 2
     * before this was written, including that a tampered message is rejected.
     *
     * The RAW body must be passed. Decoding and re-encoding the JSON changes the bytes
     * (key order, escaping, whitespace) and every signature then fails.
     *
     * Fails CLOSED: no key configured means nothing is accepted. An open webhook here
     * would let anyone opt a number in or out, or drive somebody else's call.
     */
    public static function verifyWebhook(string $publicKeyB64, string $signatureB64, string $timestamp, string $rawBody): bool
    {
        $publicKeyB64 = trim($publicKeyB64);
        $signatureB64 = trim($signatureB64);
        $timestamp = trim($timestamp);

        if ($publicKeyB64 === '' || $signatureB64 === '' || $timestamp === '' || ! ctype_digit($timestamp)) {
            return false;
        }

        // Replay window. A signature stays valid forever without this, so a captured
        // "STOP" webhook could be replayed at any point in the future.
        if (abs(time() - (int) $timestamp) > self::WEBHOOK_TOLERANCE) {
            return false;
        }

        $pub = base64_decode($publicKeyB64, true);
        $sig = base64_decode($signatureB64, true);
        if ($pub === false || $sig === false || strlen($pub) !== 32 || strlen($sig) !== 64) {
            return false;
        }

        try {
            return EC::loadFormat('libsodium', $pub)->verify($timestamp . '|' . $rawBody, $sig);
        } catch (\Throwable $e) {
            // A malformed key is a configuration error, not an authentic request.
            Log::warning('Telnyx webhook verify failed', ['msg' => $e->getMessage()]);

            return false;
        }
    }

    // -- internals ----------------------------------------------------------

    /**
     * One request, one shape of answer: ['ok', 'body', 'id', 'error', 'status'].
     *
     * Telnyx reports problems as a list of {code, title, detail}. Flattened to one line
     * here because the caller writes it into an error column somebody reads later, and
     * "detail" is the part that says which field was wrong.
     */
    private static function call(string $apiKey, string $method, string $path, ?array $payload): array
    {
        try {
            $req = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout(15)
                ->connectTimeout(8);

            $res = $method === 'GET'
                ? $req->get(self::BASE . $path)
                : $req->send($method, self::BASE . $path, ['json' => $payload ?? []]);

            $body = $res->json() ?: [];

            if ($res->successful()) {
                return ['ok' => true, 'body' => $body, 'id' => null, 'error' => null, 'status' => $res->status()];
            }

            $parts = [];
            foreach ((array) ($body['errors'] ?? []) as $e) {
                $line = trim((string) ($e['title'] ?? ''));
                if (! empty($e['detail'])) {
                    $line = trim($line . ': ' . $e['detail']);
                }
                if ($line !== '') {
                    $parts[] = $line;
                }
            }

            return [
                'ok' => false,
                'body' => $body,
                'id' => null,
                'error' => ($parts ? implode(' | ', $parts) : 'Telnyx returned HTTP ' . $res->status()),
                'status' => $res->status(),
            ];
        } catch (\Throwable $e) {
            // A timeout or a DNS failure is a failed send, not a 500 for the operator
            // who pressed the button. The caller records it and moves to the next
            // recipient -- or, for SMS, to the other carrier.
            return ['ok' => false, 'body' => [], 'id' => null, 'error' => $e->getMessage(), 'status' => 0];
        }
    }
}
