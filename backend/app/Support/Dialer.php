<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The outbound dialer (2026-10-23). Anthony: "build outbound dialer" — configurable, the
 * platform team by default, and "if each agency has their own number they can configure
 * who they want to use the dialer"; calls show the 1-855 line; startable from a dial pad,
 * from sales leads and website chats, and from any phone number in the portal; recorded
 * after a spoken notice.
 *
 * How a call is placed (Telnyx TeXML, the account that owns the caller-ID number):
 *   1. The dialler's own browser phone calls the dialer app's SIP address,
 *      sip:kt{call id}x{secret}@{subdomain}.sip.telnyx.com (only our own connections may).
 *      THE BROWSER PLACES ITS OWN LEG (2026-10-23). The first version had the server ring
 *      the dialler's phone instead, and Telnyx gives such a call to whichever window
 *      signed in last as that person: with the portal open twice (a second tab, the
 *      phone app) the call went to the wrong one and nobody answered. A browser that
 *      dials out itself cannot be beaten to its own call.
 *   2. The app's hook finds the call by that address and returns
 *      <Dial callerId=line record=…><Number url=notice> to the person being called; the
 *      dialler hears it ring.
 *   3. They answer → the notice hook plays "this call may be recorded" to them, then the
 *      two are joined. The recording comes to our recorded hook and is kept privately.
 *
 * Who may dial:
 *   platform  PlatformSettings voice.dialer = {enabled, roles[platform_admin|sales_rep], record}
 *             (default: on, platform admins, recorded) — from the toll-free line.
 *   agency    agencies.settings.dialer = {enabled, roles[agency_admin|centre_director|educator],
 *             record, caller_id} — off until the agency's admin turns it on, and only for an
 *             agency whose own Telnyx account has a voice number.
 * The platform team uses the platform line wherever they are working.
 */
final class Dialer
{
    private const API = 'https://api.telnyx.com/v2';
    public const PLATFORM_ROLES = ['platform_admin', 'sales_rep'];
    public const AGENCY_ROLES = ['agency_admin', 'centre_director', 'educator'];
    public const LINE = '+18554009996';

    /* ── settings ─────────────────────────────────────────────────────── */

    public static function platformConfig(): array
    {
        $raw = PlatformSettings::get('voice.dialer', null);
        $c = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);
        return [
            'enabled' => array_key_exists('enabled', $c) ? (bool) $c['enabled'] : true,
            'roles' => array_values(array_intersect(self::PLATFORM_ROLES, (array) ($c['roles'] ?? ['platform_admin']))),
            'record' => array_key_exists('record', $c) ? (bool) $c['record'] : true,
        ];
    }

    public static function savePlatformConfig(array $c): void
    {
        PlatformSettings::set('voice.dialer', json_encode([
            'enabled' => (bool) ($c['enabled'] ?? true),
            'roles' => array_values(array_intersect(self::PLATFORM_ROLES, (array) ($c['roles'] ?? []))),
            'record' => (bool) ($c['record'] ?? true),
        ]));
    }

    private static function agencySettings(int $agencyId): array
    {
        $row = DB::table('agencies')->where('id', $agencyId)->value('settings');
        return $row ? (json_decode((string) $row, true) ?: []) : [];
    }

    /** The agency's own numbers it may show as caller ID (its Telnyx voice and SMS numbers). */
    public static function agencyNumbers(int $agencyId): array
    {
        $t = Telnyx::readConfig($agencyId);
        return array_values(array_unique(array_filter([
            Telnyx::e164((string) ($t['voice_from'] ?? '')),
            Telnyx::e164((string) ($t['sms_from'] ?? '')),
        ], fn ($n) => preg_match('/^\+1\d{10}$/', (string) $n))));
    }

    public static function agencyConfig(int $agencyId): array
    {
        $c = (array) (self::agencySettings($agencyId)['dialer'] ?? []);
        $numbers = self::agencyNumbers($agencyId);
        $caller = Telnyx::e164((string) ($c['caller_id'] ?? ''));
        return [
            'available' => Telnyx::apiKey($agencyId) !== '' && (bool) $numbers,
            'numbers' => $numbers,
            'enabled' => (bool) ($c['enabled'] ?? false),
            'roles' => array_values(array_intersect(self::AGENCY_ROLES, (array) ($c['roles'] ?? ['agency_admin']))),
            'record' => array_key_exists('record', $c) ? (bool) $c['record'] : true,
            'caller_id' => in_array($caller, $numbers, true) ? $caller : ($numbers[0] ?? null),
        ];
    }

    public static function saveAgencyConfig(int $agencyId, array $c): void
    {
        $s = self::agencySettings($agencyId);
        $numbers = self::agencyNumbers($agencyId);
        $caller = Telnyx::e164((string) ($c['caller_id'] ?? ''));
        $s['dialer'] = [
            'enabled' => (bool) ($c['enabled'] ?? false),
            'roles' => array_values(array_intersect(self::AGENCY_ROLES, (array) ($c['roles'] ?? []))),
            'record' => (bool) ($c['record'] ?? true),
            'caller_id' => in_array($caller, $numbers, true) ? $caller : null,
        ];
        DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($s), 'updated_at' => now()]);
    }

    /* ── who may dial, from which line ───────────────────────────────── */

    private static function roles(int $userId): \Illuminate\Support\Collection
    {
        return DB::table('role_assignments')->where('user_id', $userId)->where('active', true)->get(['role', 'agency_id']);
    }

    /**
     * The line this person dials from here, or null. ['scope', 'agency_id', 'account',
     * 'from', 'record', 'label'].
     */
    public static function lineFor(int $userId, ?int $activeAgencyId): ?array
    {
        $roles = self::roles($userId);
        $p = self::platformConfig();
        if ($p['enabled'] && $roles->contains(fn ($r) => in_array($r->role, $p['roles'], true))) {
            return ['scope' => 'platform', 'agency_id' => null, 'account' => PortalPhone::LINE_ACCOUNT,
                'from' => self::LINE, 'record' => $p['record'], 'label' => 'KiddieTrac 1-855-400-9996'];
        }
        if ($activeAgencyId) {
            $a = self::agencyConfig($activeAgencyId);
            $mine = $roles->contains(fn ($r) => (int) $r->agency_id === $activeAgencyId && in_array($r->role, $a['roles'], true));
            if ($a['available'] && $a['enabled'] && $a['caller_id'] && $mine) {
                $name = (string) DB::table('agencies')->where('id', $activeAgencyId)->value('name');
                return ['scope' => 'agency', 'agency_id' => $activeAgencyId, 'account' => $activeAgencyId,
                    'from' => $a['caller_id'], 'record' => $a['record'], 'label' => trim($name . ' ' . self::pretty($a['caller_id']))];
            }
        }
        return null;
    }

    public static function pretty(string $e164): string
    {
        $d = preg_replace('/\D/', '', $e164);
        if (strlen($d) === 11 && $d[0] === '1') {
            $d = substr($d, 1);
        }
        return strlen($d) === 10 ? '(' . substr($d, 0, 3) . ') ' . substr($d, 3, 3) . '-' . substr($d, 6) : $e164;
    }

    /**
     * A number we will dial: North American, ten digits, a real area code, never an
     * emergency, premium or toll-free-to-ourselves number. +1XXXXXXXXXX, or null.
     */
    public static function normalise(string $raw): ?string
    {
        $d = preg_replace('/\D/', '', $raw);
        if (strlen($d) === 11 && $d[0] === '1') {
            $d = substr($d, 1);
        }
        if (strlen($d) !== 10) {
            return null;
        }
        if (! preg_match('/^[2-9]\d{2}[2-9]\d{6}$/', $d)) {
            return null;                                   // not a valid NANP number
        }
        if (in_array(substr($d, 0, 3), ['900', '976'], true) || preg_match('/^[2-9]11$/', substr($d, 0, 3)) || preg_match('/^[2-9]11$/', substr($d, 3, 3))) {
            return null;                                   // premium, or an N11 service code
        }
        return '+1' . $d;
    }

    /* ── Telnyx setup, per account ───────────────────────────────────── */

    /** The TeXML application calls are placed through (created with an outbound profile). */
    public static function app(int $account): array
    {
        $id = (string) PlatformSettings::get('voice.dialer.app.' . $account, '');
        if ($id !== '') {
            return [$id, null];
        }
        $key = Telnyx::apiKey($account);
        if ($key === '') {
            return [null, 'The Telnyx account for this line is not connected.'];
        }
        try {
            $ovp = null;
            foreach ((array) Http::withToken($key)->timeout(15)->get(self::API . '/outbound_voice_profiles')->json('data') as $p) {
                $dest = (array) ($p['whitelisted_destinations'] ?? []);
                if (! empty($p['enabled']) && in_array('US', $dest, true) && in_array('CA', $dest, true)) {
                    $ovp = (string) $p['id'];
                    break;
                }
            }
            if (! $ovp) {
                $r = Http::withToken($key)->timeout(15)->post(self::API . '/outbound_voice_profiles', [
                    'name' => 'KiddieTrac dialer', 'enabled' => true, 'whitelisted_destinations' => ['US', 'CA'],
                ]);
                $ovp = (string) ($r->json('data.id') ?? '');
            }
            if (! $ovp) {
                return [null, 'Telnyx would not set up outbound calling for this account.'];
            }
            $r = Http::withToken($key)->timeout(20)->post(self::API . '/texml_applications', [
                'friendly_name' => 'KiddieTrac dialer',
                'voice_url' => 'https://api.kiddietrac.com/api/v1/voice/dialer/hook/fallback',
                'voice_method' => 'post',
                'outbound' => ['outbound_voice_profile_id' => $ovp],
                'inbound' => ['sip_subdomain' => self::subdomain($account), 'sip_subdomain_receive_settings' => 'from_anyone'],
            ]);
            $id = (string) ($r->json('data.id') ?? '');
            if (! $r->successful() || $id === '') {
                Log::warning('Dialer: TeXML app not created', ['account' => $account, 'status' => $r->status(), 'body' => mb_substr($r->body(), 0, 300)]);
                return [null, 'Telnyx did not set up the dialer (HTTP ' . $r->status() . ').'];
            }
            PlatformSettings::set('voice.dialer.app.' . $account, $id);
            return [$id, null];
        } catch (\Throwable $e) {
            return [null, 'Telnyx could not be reached to set up the dialer.'];
        }
    }

    /** The dialer app's SIP subdomain on this Telnyx account (set on the line's account by hand). */
    public static function subdomain(int $account): string
    {
        return $account === PortalPhone::LINE_ACCOUNT ? 'kiddietrac-dialer' : 'kiddietrac-dialer-a' . $account;
    }

    /** The address the dialler's browser calls for this call. */
    public static function sipFor(object $call): string
    {
        return 'sip:kt' . (int) $call->id . 'x' . substr((string) $call->hook_token, 0, 24) . '@' . self::subdomain((int) $call->account_agency_id) . '.sip.telnyx.com';
    }

    /* ── placing a call ───────────────────────────────────────────────── */

    public static function hook(object $call, string $step): string
    {
        return 'https://api.kiddietrac.com/api/v1/voice/dialer/hook/' . (int) $call->id . '/' . $call->hook_token . '/' . $step;
    }

    /**
     * Start a call: ring the dialler's browser phone first. Returns [row, null] or
     * [null, reason]. The row is the call log entry.
     */
    public static function place(int $userId, array $line, string $to, array $meta = []): array
    {
        [$app, $err] = self::app((int) $line['account']);
        if (! $app) {
            return [null, $err];
        }

        $id = DB::table('outbound_calls')->insertGetId([
            'scope' => $line['scope'], 'agency_id' => $line['agency_id'], 'account_agency_id' => $line['account'],
            'user_id' => $userId, 'from_number' => $line['from'], 'to_number' => $to,
            'to_name' => isset($meta['name']) ? mb_substr((string) $meta['name'], 0, 160) : null,
            'lead_id' => $meta['lead_id'] ?? null, 'chat_id' => $meta['chat_id'] ?? null,
            'context' => isset($meta['context']) ? mb_substr((string) $meta['context'], 0, 80) : null,
            'hook_token' => Str::random(40), 'status' => 'dialing', 'recorded' => (bool) $line['record'],
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // The browser places its own leg to sipFor($call); nothing is sent to Telnyx here.
        return [DB::table('outbound_calls')->find($id), null];
    }

    /** End a call from the portal (the dialler pressed Hang up before it connected). */
    public static function cancel(object $call): void
    {
        if (! $call->call_sid) {
            return;
        }
        try {
            Http::withToken(Telnyx::apiKey((int) $call->account_agency_id))->timeout(10)
                ->post(self::API . '/texml/calls/' . rawurlencode($call->call_sid) . '/update', ['Status' => 'completed']);
        } catch (\Throwable $e) {
        }
    }
}
