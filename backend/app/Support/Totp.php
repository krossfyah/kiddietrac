<?php

declare(strict_types=1);

namespace App\Support;

/**
 * RFC 6238 TOTP (Time-based One-Time Password) — minimal pure-PHP implementation.
 *
 * Compatible with Google Authenticator, Authy, 1Password, Bitwarden, etc.
 *
 * No external dependency. Avoids a composer install that the shared host might
 * struggle with.
 */
final class Totp
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const PERIOD = 30;
    private const DIGITS = 6;
    private const ALGORITHM = 'sha1';

    public static function generateSecret(int $bytes = 20): string
    {
        $raw = random_bytes($bytes);
        return self::base32Encode($raw);
    }

    public static function otpauthUri(string $secret, string $accountName, string $issuer = 'Kiddietrac'): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($issuer),
            rawurlencode($accountName),
            $secret,
            rawurlencode($issuer),
            self::DIGITS,
            self::PERIOD,
        );
    }

    /**
     * Verify the provided 6-digit code against the secret.
     * Accepts ±1 step of clock skew (window=1).
     */
    public static function verify(string $secret, string $code, int $window = 1, ?int $now = null): bool
    {
        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $now = $now ?? time();
        $counter = intdiv($now, self::PERIOD);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::generateCode($secret, $counter + $i), $code)) {
                return true;
            }
        }
        return false;
    }

    public static function currentCode(string $secret, ?int $now = null): string
    {
        $now = $now ?? time();
        return self::generateCode($secret, intdiv($now, self::PERIOD));
    }

    public static function generateRecoveryCodes(int $count = 10): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            // 10-character grouped: XXXX-XXXX-XX → 10 digits/letters separated
            $raw = strtoupper(bin2hex(random_bytes(5))); // 10 hex chars
            $codes[] = substr($raw, 0, 4).'-'.substr($raw, 4, 4).'-'.substr($raw, 8, 2);
        }
        return $codes;
    }

    // ───────────────────────────────────────────────────────────────────────

    private static function generateCode(string $secret, int $counter): string
    {
        $bin = self::base32Decode($secret);
        // 8-byte big-endian counter
        $counterBytes = pack('N*', 0, $counter);
        $hash = hash_hmac(self::ALGORITHM, $counterBytes, $bin, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0f;
        $code = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);
        return str_pad((string) ($code % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $bytes): string
    {
        $out = '';
        $buffer = 0;
        $bitsLeft = 0;
        foreach (str_split($bytes) as $b) {
            $buffer = ($buffer << 8) | ord($b);
            $bitsLeft += 8;
            while ($bitsLeft >= 5) {
                $bitsLeft -= 5;
                $out .= self::BASE32_ALPHABET[($buffer >> $bitsLeft) & 0x1f];
            }
        }
        if ($bitsLeft > 0) {
            $out .= self::BASE32_ALPHABET[($buffer << (5 - $bitsLeft)) & 0x1f];
        }
        return $out;
    }

    private static function base32Decode(string $s): string
    {
        $s = strtoupper(rtrim($s, '='));
        $out = '';
        $buffer = 0;
        $bitsLeft = 0;
        foreach (str_split($s) as $char) {
            $val = strpos(self::BASE32_ALPHABET, $char);
            if ($val === false) continue;
            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $out .= chr(($buffer >> $bitsLeft) & 0xff);
            }
        }
        return $out;
    }
}
