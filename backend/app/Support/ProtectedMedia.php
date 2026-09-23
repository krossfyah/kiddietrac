<?php

namespace App\Support;

use Illuminate\Support\Facades\URL;

/**
 * Uploaded files stop being readable by anyone who happens to hold the URL.
 *
 * Everything under storage/app/public is symlinked into the web root and served
 * by Apache with no authentication, no tenant check and no audit trail. That is
 * correct for a centre logo. It is not correct for a signed agreement, a child's
 * document, a chat attachment or a photograph of somebody's child — those were
 * readable by anyone who had ever seen the link, for ever, including after the
 * family had left.
 *
 * The fix keeps the stored path exactly as it is — `/storage/agreements/…` — and
 * changes what a client is HANDED. Responses now carry a signed, expiring URL to
 * a route that checks the signature before streaming the file, and Apache is told
 * to refuse the raw path. Old links stop working; nothing needs migrating; and no
 * controller that writes `'/storage/' . $path` has to change.
 *
 * A signature rather than a bearer token because these URLs go in `<img src>` and
 * `window.open`, neither of which can carry a header. Twelve hours because a
 * working day is the honest session length here, and a link that leaks after that
 * is dead.
 *
 * Deliberately NOT protected: branding, centre-logos, room-logos and marketing.
 * Those are public by intent — they appear in emails and on the marketing site,
 * where a signature would expire and leave a broken image.
 */
class ProtectedMedia
{
    /** Folders under the public disk that must never be served without a signature. */
    public const FOLDERS = [
        'child-documents',
        /* A CHILD'S PHOTOGRAPH, which was served unsigned and publicly readable by
           anyone holding the URL — while `child-documents` beside it was protected.
           Nothing emits one into an email or a PDF (checked: only the two upload
           endpoints write this folder), so the 12-hour expiry costs nothing here.

           It also routes them through MediaFileController for the first time, which is
           where they finally get thumbnailed: they were being served as untouched camera
           originals, 2.18 MB on average and one at 8000x6000, to fill a 30px circle. */
        'child-photos',
        'agreements',
        'managed-forms',
        'chat-attachments',
        'feedback-attachments',
        'messages',
        'photos',
        'videos',
        'avatars',
    ];

    /** Long enough for a working day, short enough that a leaked link dies. */
    public const TTL_MINUTES = 720;

    /**
     * The path relative to the public disk, when this is a protected file.
     *
     * Accepts what the codebase actually stores: '/storage/photos/x.png', a bare
     * 'photos/x.png', and the absolute form some rows carry.
     */
    public static function relative(?string $url): ?string
    {
        $u = trim((string) $url);
        if ($u === '') {
            return null;
        }

        // Absolute → path only. Anything on another host is not ours to sign.
        if (preg_match('#^https?://#i', $u)) {
            $host = parse_url($u, PHP_URL_HOST);
            $ours = array_filter([
                parse_url((string) config('app.url'), PHP_URL_HOST),
                'api.kiddietrac.com',
                'app.kiddietrac.com',
            ]);
            if ($host && ! in_array($host, $ours, true)) {
                return null;
            }
            $u = (string) parse_url($u, PHP_URL_PATH);
        }

        $u = preg_replace('#^/storage/#', '', $u);
        $u = ltrim((string) $u, '/');
        if ($u === '') {
            return null;
        }

        // No traversal, ever — this string ends up under a filesystem root.
        if (str_contains($u, '..')) {
            return null;
        }

        $folder = explode('/', $u)[0];

        return in_array($folder, self::FOLDERS, true) ? $u : null;
    }

    /** Is this URL one we must sign before handing it out? */
    public static function isProtected(?string $url): bool
    {
        return self::relative($url) !== null;
    }

    /**
     * A signed, expiring URL for a protected file. Returns the input unchanged
     * when it is not ours to sign, so callers can pass anything through.
     */
    public static function sign(?string $url): ?string
    {
        $rel = self::relative($url);
        if ($rel === null) {
            return $url;
        }

        return URL::temporarySignedRoute(
            'media.file',
            self::stableExpiry(),
            // base64url: the path has slashes and dots, and a query value keeps
            // Laravel's signature covering it without route-pattern gymnastics.
            ['p' => rtrim(strtr(base64_encode($rel), '+/', '-_'), '=')]
        );
    }

    /**
     * AN EXPIRY THAT DOES NOT MOVE EVERY SECOND — this is what makes media cacheable.
     *
     * `now()->addMinutes(TTL)` looks harmless and quietly defeated the browser cache
     * entirely. The expiry is part of the signature, so a timestamp computed per request
     * produced a DIFFERENT URL for the same file on every response: two /auth/me calls two
     * seconds apart returned `expires=1789007901` and `expires=1789007904`. A new URL is a
     * cache miss by definition, so every avatar and every photo was downloaded again on
     * every render, every refresh and every poll-driven repaint — with a perfectly good
     * copy sitting unused in the cache under the previous second's URL. The endpoint was
     * already sending `Cache-Control: max-age=600`; nothing could ever hit it. That is the
     * "avatars and display pics are not smooth loading" report.
     *
     * Rounding the expiry UP to a fixed boundary makes every request inside the same window
     * produce a byte-identical URL, so the second one is served from cache with no network
     * at all. The link still expires — it is valid for between TTL and TTL + one window —
     * and the window is deliberately much longer than the response's max-age, so a cached
     * copy can never outlive the signature that fetched it. (Anthony, 2026-09-09)
     */
    public const STABLE_WINDOW_SECONDS = 21600;   // 6 hours

    /** An email is read tomorrow, or next month. The link has to outlive that. */
    public const EMAIL_TTL_DAYS = 30;

    private static function stableExpiry(): \DateTimeInterface
    {
        $earliest = time() + (self::TTL_MINUTES * 60);
        $w = self::STABLE_WINDOW_SECONDS;

        return \Illuminate\Support\Carbon::createFromTimestamp((int) (ceil($earliest / $w) * $w));
    }

    /**
     * A signature that outlives the email carrying it.
     *
     * An email is read tomorrow, or next week, and a twelve-hour signature would
     * simply be a broken image by then. The email is already a capability given to
     * that recipient, so a long-lived link inside it grants nothing they did not
     * already have — unlike a link that leaks out of a browser session.
     */
    public static function signForEmail(?string $url): ?string
    {
        $rel = self::relative($url);
        if ($rel === null) {
            return $url;
        }

        /* NO QUERY STRING. This used to be a temporarySignedRoute, whose ?expires=…&p=…
           &signature=… is written into the HTML by Blade as &amp;. That is correct
           HTML — but a mail client that fetches the src without decoding the entities
           first asks for `amp;p` and `amp;signature`, the signature does not match,
           and the recipient sees a broken image. Verified against production mail: the
           decoded URL returns 200 and the entity-literal one returns 403, which is why
           the agency logo (no query string) rendered while the provider's photo did
           not. One path segment has nothing an HTML escaper can touch. */
        return rtrim(config('app.url', 'https://api.kiddietrac.com'), '/')
            . '/api/v1/media/e/' . self::emailToken($rel);
    }

    /** payload.signature — both base64url, so the whole thing is URL- and HTML-safe. */
    public static function emailToken(string $rel, ?int $days = null): string
    {
        $days = $days ?: self::EMAIL_TTL_DAYS;
        $payload = rtrim(strtr(base64_encode(json_encode([
            'p' => $rel,
            'e' => time() + ($days * 86400),
        ])), '+/', '-_'), '=');

        return $payload . '.' . self::emailSignature($payload);
    }

    /**
     * The relative path a token stands for, or null when it is forged, tampered with
     * or expired. Never returns a path this class would not otherwise serve.
     */
    public static function verifyEmailToken(?string $token): ?string
    {
        $token = (string) $token;
        if (! str_contains($token, '.')) {
            return null;
        }
        [$payload, $sig] = explode('.', $token, 2);
        if ($payload === '' || $sig === '') {
            return null;
        }
        // hash_equals: a timing-safe comparison, because this is a signature check.
        if (! hash_equals(self::emailSignature($payload), $sig)) {
            return null;
        }

        $json = base64_decode(strtr($payload, '-_', '+/'), true);
        if ($json === false) {
            return null;
        }
        $data = json_decode($json, true);
        if (! is_array($data) || empty($data['p']) || empty($data['e'])) {
            return null;
        }
        if (time() > (int) $data['e']) {
            return null;
        }

        // Re-validated rather than trusted: a good signature proves we issued it, not
        // that the path is still one we are willing to serve.
        return self::relative((string) $data['p']);
    }

    private static function emailSignature(string $payload): string
    {
        return rtrim(strtr(base64_encode(
            hash_hmac('sha256', 'kt-email-media|' . $payload, (string) config('app.key'), true)
        ), '+/', '-_'), '=');
    }

    /** The reverse of the encoding in sign(). Null when it decodes to nothing usable. */
    public static function decode(?string $p): ?string
    {
        $p = (string) $p;
        if ($p === '') {
            return null;
        }
        $bin = base64_decode(strtr($p, '-_', '+/'), true);
        if ($bin === false) {
            return null;
        }

        // Re-validated rather than trusted: this came off the wire, and a valid
        // signature proves we issued it, not that it is still in bounds.
        return self::relative($bin);
    }
}
