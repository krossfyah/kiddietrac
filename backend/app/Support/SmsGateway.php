<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Twilio\Rest\Client;

/**
 * WHICH CARRIER SENDS THIS TEXT (2026-09-10).
 *
 * Two carriers are now wired up -- Twilio, which has been here since v22p51, and
 * Telnyx. This is the one place that decides between them, so every existing gate in
 * SmsController::sendOne (suppression, the agency switch, consent) still runs first
 * and applies to both. Adding a carrier must never become a way around consent.
 *
 * -- PRIMARY AND FALLBACK --
 * The agency names a primary in its SMS settings. If that carrier REFUSES or cannot be
 * reached and the other one is fully configured, the message goes out on the other one.
 * The case this is for is a closure or an evacuation notice: the whole value of the
 * message is that it arrives in the next two minutes, and one carrier having a bad
 * morning should not be the reason it doesn't.
 *
 * Failover is per agency and defaults to ON, but only ever engages when the other
 * carrier is CONFIGURED -- so an agency with one carrier behaves exactly as before.
 *
 * -- WHAT FAILOVER MUST NOT DO --
 * It retries a REFUSAL, not a delivery. Telnyx and Twilio both accept a message before
 * the carrier has seen it, so "sent" here means "accepted by the carrier", the same as
 * it always has. A message accepted and then undelivered is not retried on the other
 * carrier, because we would have no way to know whether the handset got one copy or
 * two -- and two copies of an evacuation notice is its own kind of harm.
 *
 * It also does not fail over on a 4xx that names the RECIPIENT: a number that is not a
 * mobile, or one that has replied STOP, is refused by the second carrier for the same
 * reason and at the same price. See retryable().
 */
final class SmsGateway
{
    /** The carriers, in the order they are offered on the settings screen. */
    public const PROVIDERS = ['twilio', 'telnyx'];

    /**
     * Which carrier this agency sends on, and whether the other one catches a failure.
     *
     * Stored beside the Twilio credentials rather than in the Telnyx block, because it
     * is a decision ABOUT the pair and belongs with neither one of them.
     */
    public static function preference(int $agencyId): array
    {
        $cfg = \App\Http\Controllers\Api\SmsSettingsController::readConfig($agencyId);
        $primary = strtolower(trim((string) ($cfg['provider'] ?? 'twilio')));

        return [
            'primary' => in_array($primary, self::PROVIDERS, true) ? $primary : 'twilio',
            // Absent means on. An agency that has never seen this screen and has only
            // one carrier configured is unaffected either way.
            'failover' => ($cfg['failover'] ?? true) !== false,
        ];
    }

    /** Is this carrier ready to send for this agency? Used by the settings screen too. */
    public static function ready(string $provider, int $agencyId): bool
    {
        return $provider === 'telnyx'
            ? Telnyx::smsConfig($agencyId) !== null
            : \App\Http\Controllers\Api\SmsController::twilioConfig($agencyId) !== null;
    }

    /**
     * The carriers to try, in order.
     *
     * The primary comes first even if it is not configured, so that "nothing is set up"
     * reports the carrier the agency actually chose rather than silently sending on the
     * other one. Callers filter with ready().
     */
    public static function order(int $agencyId): array
    {
        $pref = self::preference($agencyId);
        $order = [$pref['primary']];

        if ($pref['failover']) {
            foreach (self::PROVIDERS as $p) {
                if ($p !== $pref['primary'] && self::ready($p, $agencyId)) {
                    $order[] = $p;
                }
            }
        }

        return $order;
    }

    /**
     * Hand one message to a carrier.
     *
     * Returns ['ok', 'provider', 'ref', 'error', 'attempts'] -- 'attempts' is a short
     * human line naming every carrier that refused, which is what ends up in
     * sms_messages.error when the whole thing fails. Somebody reading that column later
     * needs to know it was tried twice.
     *
     * Never throws. It is called inside a loop over recipients.
     */
    public static function deliver(int $agencyId, string $to, string $body, ?string $mediaUrl = null): array
    {
        $tried = [];
        $order = self::order($agencyId);

        foreach ($order as $provider) {
            if (! self::ready($provider, $agencyId)) {
                $tried[] = $provider . ': not configured';
                continue;
            }

            $r = $provider === 'telnyx'
                ? self::viaTelnyx($agencyId, $to, $body, $mediaUrl)
                : self::viaTwilio($agencyId, $to, $body, $mediaUrl);

            if ($r['ok']) {
                return [
                    'ok' => true,
                    'provider' => $provider,
                    'ref' => $r['id'],
                    'error' => null,
                    // Kept even on success: "Twilio refused, Telnyx sent it" is the
                    // single most useful thing this can record.
                    'attempts' => $tried ? implode(' | ', $tried) . ' | ' . $provider . ': sent' : null,
                ];
            }

            $tried[] = $provider . ': ' . $r['error'];
            Log::warning('SMS carrier refused', [
                'agency' => $agencyId, 'provider' => $provider, 'msg' => $r['error'],
            ]);

            if (! self::retryable((int) ($r['status'] ?? 0))) {
                break;
            }
        }

        return [
            'ok' => false,
            'provider' => $order[0] ?? null,
            'ref' => null,
            'error' => $tried ? implode(' | ', $tried) : 'no sms carrier configured',
            'attempts' => $tried ? implode(' | ', $tried) : null,
        ];
    }

    /**
     * Is it worth asking the other carrier?
     *
     * A network failure, a timeout (status 0) or a carrier-side 5xx says nothing about
     * the message, so the other carrier gets a turn. A 401 or 403 means these
     * credentials are wrong, which is also worth trying elsewhere.
     *
     * Everything else in the 4xx range is about the MESSAGE or the RECIPIENT -- an
     * unusable number, a body over the limit, a number that has opted out. The second
     * carrier refuses it for the same reason, so trying is a wasted second and a
     * second entry in a log that then reads as though two carriers are broken.
     */
    private static function retryable(int $status): bool
    {
        return $status === 0 || $status >= 500 || $status === 401 || $status === 403 || $status === 429;
    }

    private static function viaTelnyx(int $agencyId, string $to, string $body, ?string $mediaUrl): array
    {
        $cfg = Telnyx::smsConfig($agencyId);
        if (! $cfg) {
            return ['ok' => false, 'id' => null, 'error' => 'not configured', 'status' => 0];
        }

        $r = Telnyx::sendSms($cfg, $to, $body, $mediaUrl);

        /* Same reasoning as the Twilio path below: plenty of carriers and most
           toll-free setups refuse MMS, and there is no way to know beforehand. The
           picture is worth less than the message, so the text goes out with the image
           as a link rather than the recipient getting nothing. */
        if (! $r['ok'] && $mediaUrl) {
            Log::info('MMS refused by Telnyx, retrying as SMS with a link', ['error' => $r['error']]);
            $r = Telnyx::sendSms($cfg, $to, rtrim($body) . "\n" . $mediaUrl, null);
        }

        return $r;
    }

    /**
     * The Twilio path, moved here unchanged from SmsController::sendOne so that both
     * carriers are chosen and reported in one place. The MMS fallback and the
     * (key sid, secret, account sid) argument order come with it.
     */
    private static function viaTwilio(int $agencyId, string $to, string $body, ?string $mediaUrl): array
    {
        $cfg = \App\Http\Controllers\Api\SmsController::twilioConfig($agencyId);
        if (! $cfg) {
            return ['ok' => false, 'id' => null, 'error' => 'not configured', 'status' => 0];
        }

        try {
            // (username, password, accountSid) -- for an API key the first two are the
            // key's SID and secret, and the account it acts on is passed separately.
            $client = new Client($cfg['user'], $cfg['pass'], $cfg['account']);
            $args = ['from' => $cfg['from'], 'body' => $body];
            if ($mediaUrl) {
                $args['mediaUrl'] = [$mediaUrl];
            }

            try {
                $msg = $client->messages->create($to, $args);
            } catch (\Throwable $mmsError) {
                if (! $mediaUrl) {
                    throw $mmsError;
                }
                Log::info('MMS refused, retrying as SMS with a link', ['error' => $mmsError->getMessage()]);
                $msg = $client->messages->create($to, [
                    'from' => $cfg['from'],
                    'body' => rtrim($body) . "\n" . $mediaUrl,
                ]);
            }

            return ['ok' => true, 'id' => (string) $msg->sid, 'error' => null, 'status' => 201];
        } catch (\Throwable $e) {
            // Twilio's exception carries the HTTP status, which is what decides whether
            // the other carrier gets a turn. Not every throwable does, hence the guard.
            $status = ($e instanceof \Twilio\Exceptions\RestException) ? (int) $e->getStatusCode() : 0;

            return ['ok' => false, 'id' => null, 'error' => $e->getMessage(), 'status' => $status];
        }
    }

    /**
     * The last few sends per carrier, for the settings screen.
     *
     * Answers "is the carrier I just switched to actually working", which is the first
     * question anybody has after changing this and the one the message list on the
     * broadcast screen cannot answer because it does not say who carried what.
     */
    public static function recentByProvider(int $agencyId, int $days = 30): array
    {
        $rows = DB::table('sms_messages')
            ->where('agency_id', $agencyId)
            ->where('created_at', '>=', now()->subDays($days))
            ->selectRaw('COALESCE(provider, ?) as provider, status, COUNT(*) as n', ['twilio'])
            ->groupBy('provider', 'status')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->provider][$r->status] = (int) $r->n;
        }

        return $out;
    }
}
