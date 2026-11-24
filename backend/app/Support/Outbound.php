<?php

namespace App\Support;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * NOTHING LEAVES A STAGING COPY (2026-10-20).
 *
 * The Cloudways copy of KiddieTrac (staging.kiddietrac.com) runs the live code with a
 * copy of the live database and live keys. Anything it sends reaches real parents and
 * staff, and a charge is a real charge. With KT_OUTBOUND_OFF=1 in a copy's .env:
 *
 *   - every Laravel mail is cancelled before any transport sees it (Graph, Gmail API,
 *     sendmail and the sendmail safety net alike);
 *   - every Laravel HTTP call is refused unless its host is on READ_ONLY_HOSTS (maps,
 *     avatars, Microsoft/Google sign-in for reading). That covers SMS and calls (Telnyx,
 *     Twilio), Stripe, Helcim, Zum Rails, Xero, QuickBooks, Sage, iLearn, Gmail and Graph
 *     sending;
 *   - the senders that use raw curl or their own HTTP client check off() themselves:
 *     FcmService (phone push), WebPushService (browser push), SmsGateway (texts).
 *
 * Each refusal is logged as "KT_OUTBOUND_OFF" so a tester can see what WOULD have gone.
 * The live site never sets the flag. Absent or 0 = everything behaves as before.
 */
class Outbound
{
    /* Hosts a staging copy may still call: look-ups that contact nobody. Sign-in hosts
       are here so Sign in with Microsoft / Google works; sending through Graph or Gmail
       goes via Mail (cancelled) or through paths below that are refused by URL. */
    private const READ_ONLY_HOSTS = [
        'nominatim.openstreetmap.org', 'tile.openstreetmap.org', 'photon.komoot.io',
        'api.dicebear.com', 'placehold.co', 'maps.apple.com', 'api.anthropic.com',
        'login.microsoftonline.com', 'oauth2.googleapis.com', 'accounts.google.com',
    ];

    /* Graph and Gmail are allowed only for READING a mailbox; their sending endpoints
       are refused even though the host is otherwise reachable. */
    private const READ_ONLY_PREFIXES = [
        'https://graph.microsoft.com/v1.0/me',
        'https://graph.microsoft.com/v1.0/users/',
    ];

    public static function off(): bool
    {
        return filter_var(env('KT_OUTBOUND_OFF', false), FILTER_VALIDATE_BOOLEAN);
    }

    public static function note(string $what, array $ctx = []): void
    {
        Log::warning('KT_OUTBOUND_OFF: blocked ' . $what, $ctx);
    }

    /** May this URL be called on a staging copy? */
    public static function allows(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (in_array($host, self::READ_ONLY_HOSTS, true)) return true;
        foreach (self::READ_ONLY_PREFIXES as $p) {
            if (strncasecmp($url, $p, strlen($p)) === 0) {
                return ! preg_match('#/(sendMail|send|reply|replyAll|forward|createReply)(\?|$)#i', (string) parse_url($url, PHP_URL_PATH));
            }
        }
        return false;
    }

    /** Called once from AppServiceProvider::boot(). */
    public static function register(): void
    {
        if (! self::off()) return;

        Event::listen(\Illuminate\Mail\Events\MessageSending::class, function ($event) {
            $to = [];
            try { foreach ($event->message->getTo() as $a) { $to[] = $a->getAddress(); } } catch (\Throwable $e) {}
            self::note('email', ['to' => $to, 'subject' => (string) $event->message->getSubject()]);
            return false;
        });

        Http::globalMiddleware(function (callable $handler) {
            return function ($request, array $options) use ($handler) {
                $url = (string) $request->getUri();
                if (self::allows($url)) return $handler($request, $options);
                self::note('http', ['method' => $request->getMethod(), 'url' => preg_replace('/\?.*$/', '', $url)]);
                return \GuzzleHttp\Promise\Create::rejectionFor(
                    new \GuzzleHttp\Exception\ConnectException('Blocked on this staging copy (KT_OUTBOUND_OFF): nothing is sent from here.', $request)
                );
            };
        });
    }
}
