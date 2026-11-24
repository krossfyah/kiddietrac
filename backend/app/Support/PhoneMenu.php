<?php

namespace App\Support;

use App\Http\Controllers\Api\PhoneLineController;
use Illuminate\Support\Carbon;

/**
 * The toll-free line's call menu (2026-10-22).
 *
 * Anthony: "can the kiddietrac ph number have a prompt system if someone calls in they can
 * select options to get to certain departments and it rings and goes to voicemail".
 * His choices: ring staff phones and/or the portal (each switchable per department, then
 * voicemail after a set number of rings); everyone in a department rings at once; after
 * hours the menu still plays and goes to that department's voicemail; a department's
 * voicemail is emailed to it, listed in the portal and alerts its people.
 *
 * How a call flows:
 *   1. Telnyx answers with the script hosted in its media library (PhoneLineController::
 *      script()) — so the call is answered even if our server is slow. With the menu on,
 *      that script is a <Gather> for one key; no key → today's general voicemail.
 *   2. The key is POSTed to /voice/ivr/{token}/choose, which answers with what to do:
 *      ring the department's phones at once (<Dial> with several <Number>s) for
 *      rings × 6 seconds, or go straight to the department's voicemail (after hours,
 *      nobody to ring, or ringing switched off).
 *   3. Each staff phone that answers first hears "KiddieTrac call for Sales — press 1 to
 *      accept" (/screen). A personal voicemail never presses 1, so it cannot swallow the
 *      call; that leg hangs up and the caller keeps ringing the others.
 *   4. /dialed gets the outcome: answered → done; anything else → department voicemail,
 *      which records to /voice/voicemail/{token}?dept=N (WebsiteSupportController).
 * Portal ringing (ring_portal) is stored now and wired in stage 2 (a Telnyx WebRTC
 * credential connection); until then it has no effect.
 */
final class PhoneMenu
{
    public const KEY = 'voice.menu';
    public const SECONDS_PER_RING = 6;

    public const DEFAULTS = [
        'enabled' => false,
        'intro' => 'Thank you for calling KiddieTrac.',
        'invalid' => 'Sorry, that is not one of the options.',
        'connecting' => 'Please hold while we connect you to {dept}.',
        'hours' => ['tz' => 'America/Toronto', 'days' => [1, 2, 3, 4, 5], 'open' => '09:00', 'close' => '17:00'],
        'departments' => [
            ['key' => '1', 'name' => 'Sales', 'enabled' => true, 'ring_phones' => true, 'ring_portal' => false, 'numbers' => [], 'rings' => 4,
                'greeting' => 'You have reached KiddieTrac sales. Please leave your name, phone number and a short message after the beep, and we will call you back.',
                'emails' => ['info@kiddietrac.com'], 'alert_user_ids' => [], 'text_alert' => false],
            ['key' => '2', 'name' => 'Support', 'enabled' => true, 'ring_phones' => true, 'ring_portal' => false, 'numbers' => [], 'rings' => 4,
                'greeting' => 'You have reached KiddieTrac support. Please leave your name, your centre, a phone number and what you need help with after the beep.',
                'emails' => ['info@kiddietrac.com'], 'alert_user_ids' => [], 'text_alert' => false],
            ['key' => '3', 'name' => 'Billing', 'enabled' => true, 'ring_phones' => true, 'ring_portal' => false, 'numbers' => [], 'rings' => 4,
                'greeting' => 'You have reached KiddieTrac billing. Please leave your name, phone number and invoice number if you have one, after the beep.',
                'emails' => ['info@kiddietrac.com'], 'alert_user_ids' => [], 'text_alert' => false],
        ],
    ];

    public static function config(): array
    {
        $raw = PlatformSettings::get(self::KEY);
        $c = is_string($raw) ? (json_decode($raw, true) ?: []) : (is_array($raw) ? $raw : []);
        $c = array_merge(self::DEFAULTS, array_intersect_key($c, self::DEFAULTS));
        $c['enabled'] = (bool) $c['enabled'];
        $c['hours'] = array_merge(self::DEFAULTS['hours'], is_array($c['hours']) ? $c['hours'] : []);
        $c['departments'] = array_values(array_map([self::class, 'dept'], is_array($c['departments']) ? $c['departments'] : []));

        return $c;
    }

    public static function dept(array $d): array
    {
        $nums = [];
        foreach ((array) ($d['numbers'] ?? []) as $n) {
            $e = Telnyx::e164((string) $n);
            if ($e !== '' && ! in_array($e, $nums, true)) {
                $nums[] = $e;
            }
        }

        return [
            'key' => preg_match('/^[0-9]$/', (string) ($d['key'] ?? '')) ? (string) $d['key'] : '9',
            'name' => mb_substr(trim((string) ($d['name'] ?? 'Department')), 0, 40) ?: 'Department',
            'enabled' => (bool) ($d['enabled'] ?? true),
            'ring_phones' => (bool) ($d['ring_phones'] ?? true),
            'ring_portal' => (bool) ($d['ring_portal'] ?? false),
            'numbers' => array_slice($nums, 0, 10),
            'rings' => max(1, min(10, (int) ($d['rings'] ?? 4))),
            'greeting' => mb_substr(trim((string) ($d['greeting'] ?? '')), 0, 600),
            'emails' => array_values(array_slice(array_filter(array_map('trim', (array) ($d['emails'] ?? [])), fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)), 0, 5)),
            'alert_user_ids' => array_values(array_slice(array_map('intval', (array) ($d['alert_user_ids'] ?? [])), 0, 20)),
            'text_alert' => (bool) ($d['text_alert'] ?? false),
        ];
    }

    public static function save(array $c): array
    {
        $clean = [
            'enabled' => (bool) ($c['enabled'] ?? false),
            'intro' => mb_substr(trim((string) ($c['intro'] ?? self::DEFAULTS['intro'])), 0, 400),
            'invalid' => mb_substr(trim((string) ($c['invalid'] ?? self::DEFAULTS['invalid'])), 0, 200),
            'connecting' => mb_substr(trim((string) ($c['connecting'] ?? self::DEFAULTS['connecting'])), 0, 200),
            'hours' => [
                'tz' => in_array($c['hours']['tz'] ?? '', timezone_identifiers_list(), true) ? $c['hours']['tz'] : 'America/Toronto',
                'days' => array_values(array_unique(array_filter(array_map('intval', (array) ($c['hours']['days'] ?? [1, 2, 3, 4, 5])), fn ($d) => $d >= 1 && $d <= 7))),
                'open' => preg_match('/^\d{2}:\d{2}$/', (string) ($c['hours']['open'] ?? '')) ? $c['hours']['open'] : '09:00',
                'close' => preg_match('/^\d{2}:\d{2}$/', (string) ($c['hours']['close'] ?? '')) ? $c['hours']['close'] : '17:00',
            ],
            'departments' => array_values(array_map([self::class, 'dept'], (array) ($c['departments'] ?? []))),
        ];
        PlatformSettings::set(self::KEY, json_encode($clean));

        return self::config();
    }

    public static function find(array $c, string $key): ?array
    {
        foreach ($c['departments'] as $d) {
            if ($d['enabled'] && $d['key'] === $key) {
                return $d;
            }
        }

        return null;
    }

    public static function open(array $c, ?Carbon $at = null): bool
    {
        $h = $c['hours'];
        $now = ($at ?? Carbon::now())->copy()->timezone($h['tz']);
        if (! in_array((int) $now->isoWeekday(), $h['days'], true)) {
            return false;
        }
        $t = $now->format('H:i');

        return $t >= $h['open'] && $t < $h['close'];
    }

    /** "For Sales, press 1. For Support, press 2." */
    public static function prompt(array $c): string
    {
        $parts = [];
        foreach ($c['departments'] as $d) {
            if ($d['enabled']) {
                $parts[] = 'For ' . $d['name'] . ', press ' . $d['key'] . '.';
            }
        }

        return implode(' ', $parts) . ' To leave a general message, stay on the line.';
    }

    public static function url(string $path, array $q = []): string
    {
        $u = 'https://api.kiddietrac.com/api/v1/voice/ivr/' . PlatformSettings::get('voice.voicemail_token') . '/' . $path;

        return htmlspecialchars($u . ($q ? '?' . http_build_query($q) : ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** The <Gather> block: used in the hosted script and when a caller presses a wrong key. */
    public static function gather(array $c, string $voice, bool $withIntro): string
    {
        return '<Gather action="' . self::url('choose') . '" method="POST" numDigits="1" timeout="6">'
            . ($withIntro && $c['intro'] !== '' ? PhoneLineController::say($c['intro'], $voice) : '')
            . PhoneLineController::say(self::prompt($c), $voice)
            . '</Gather>';
    }

    /** A department's voicemail: its greeting, then a recording to the voicemail callback. */
    public static function voicemail(array $d, array $line): string
    {
        $cb = htmlspecialchars('https://api.kiddietrac.com/api/v1/voice/voicemail/' . PlatformSettings::get('voice.voicemail_token') . '?dept=' . rawurlencode($d['key']), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $greet = $d['greeting'] !== '' ? $d['greeting'] : 'Please leave your name, phone number and a short message after the beep.';

        return PhoneLineController::say($greet, $line['voice'])
            . '<Record action="' . $cb . '" method="POST" recordingStatusCallback="' . $cb . '" recordingStatusCallbackMethod="POST" maxLength="' . (int) $line['max_length'] . '" timeout="15" playBeep="true" finishOnKey="*9" />'
            . PhoneLineController::say($line['no_message'], $line['voice']);
    }

    public static function xml(string $inner): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n<Response>" . $inner . "</Response>\n";
    }
}
