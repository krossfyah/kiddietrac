<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use lbuchs\WebAuthn\WebAuthn;

/**
 * One place that knows how this platform does WebAuthn.
 *
 * RP ID IS THE APEX, NOT THE PORTAL HOST. The portal is app.kiddietrac.com and the API is
 * api.kiddietrac.com; registering against `kiddietrac.com` means one passkey works from
 * either and survives a subdomain nobody has thought of yet. The RP ID must be a
 * registrable suffix of the page's origin, which the apex is — confirmed on a real device
 * on 2026-09-22, where a wrong apex would have thrown SecurityError instead of returning
 * a credential.
 *
 * ES256 AND RS256 ONLY. EdDSA needs the sodium extension and this host does not load it,
 * so offering it would mean accepting a credential we could never verify. Those two cover
 * every platform authenticator in practice.
 *
 * THE CHALLENGE LIVES SERVER-SIDE. It is minted here, held in the cache for two minutes
 * against a random handle, and consumed exactly once — a challenge the client could
 * choose, or replay, is not a challenge.
 */
final class Passkeys
{
    public const RP_NAME = 'KiddieTrac';

    /** Two minutes: long enough to find a finger, short enough not to be worth stealing. */
    private const CHALLENGE_TTL = 120;

    public static function rpId(): string
    {
        /* Config-driven so a staging host can differ, but defaulted so nothing breaks if
           the key is missing — a null RP ID fails every ceremony with an opaque error. */
        return (string) (config('passkeys.rp_id') ?: 'kiddietrac.com');
    }

    public static function lib(): WebAuthn
    {
        /* 'none' attestation: we do not care WHICH make of authenticator it is, only that
           the key is real and the user was verified. Asking for attestation means handling
           certificate chains and metadata for no gain here, and it is a privacy cost the
           reader did not agree to. */
        return new WebAuthn(self::RP_NAME, self::rpId(), ['none']);
    }

    /**
     * Stash a challenge and hand back the opaque handle the client must return with it.
     *
     * The handle is not a secret and carries no meaning — it only says WHICH pending
     * ceremony this is, so two tabs cannot stamp on each other.
     */
    public static function stash(string $challenge, array $meta = []): string
    {
        $handle = bin2hex(random_bytes(16));
        Cache::put(self::key($handle), ['c' => base64_encode($challenge)] + $meta, self::CHALLENGE_TTL);

        return $handle;
    }

    /**
     * Take a challenge back, ONCE. Returns null when it is unknown, expired or already
     * spent — all three of which mean the same thing to a caller: do not proceed.
     */
    public static function claim(?string $handle): ?array
    {
        $handle = (string) $handle;
        if ($handle === '' || ! preg_match('/^[a-f0-9]{32}$/', $handle)) {
            return null;
        }
        $row = Cache::pull(self::key($handle));   // pull = read and delete: single use
        if (! is_array($row) || empty($row['c'])) {
            return null;
        }
        $row['challenge'] = base64_decode((string) $row['c'], true) ?: '';

        return $row['challenge'] === '' ? null : $row;
    }

    private static function key(string $handle): string
    {
        return 'passkey.chal.' . $handle;
    }

    /** base64url, the encoding the browser speaks, without the padding it omits. */
    public static function b64u(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function unb64u(?string $s): string
    {
        $s = (string) $s;
        $pad = strlen($s) % 4;

        return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', $pad ? 4 - $pad : 0), true);
    }

    /**
     * A readable name for a new passkey, from the device that made it.
     *
     * "Passkey 1" is useless the moment somebody has two, and people revoke by
     * recognising the device rather than by remembering the order they enrolled in.
     */
    public static function labelFor(string $ua): string
    {
        $ua = trim($ua);
        $os = preg_match('/iPhone|iPad/i', $ua) ? 'iPhone'
            : (preg_match('/Android/i', $ua) ? 'Android'
            : (preg_match('/Macintosh|Mac OS X/i', $ua) ? 'Mac'
            : (preg_match('/Windows/i', $ua) ? 'Windows' : '')));
        $br = preg_match('/Edg\//i', $ua) ? 'Edge'
            : (preg_match('/CriOS|Chrome\//i', $ua) ? 'Chrome'
            : (preg_match('/Firefox\//i', $ua) ? 'Firefox'
            : (preg_match('/Safari\//i', $ua) ? 'Safari' : '')));

        $name = trim($os . ($os && $br ? ' · ' : '') . $br);

        return $name !== '' ? mb_substr($name, 0, 80) : 'This device';
    }
}
