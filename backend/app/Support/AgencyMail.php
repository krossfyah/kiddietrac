<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Per-agency (white-label) outbound email. A white-label agency can send from
 * their OWN Microsoft 365 (Graph) or Google/Gmail (SMTP) instead of KiddieTrac's
 * default sender. Config lives in agencies.settings.mail_config (secrets
 * encrypted). AgencyRouterTransport uses this to pick the transport per message.
 */
class AgencyMail
{
    private static function enc(?string $v): ?string
    {
        return ($v === null || $v === '') ? null : Crypt::encryptString($v);
    }

    private static function dec(?string $v): ?string
    {
        if (! $v) {
            return null;
        }
        try {
            return Crypt::decryptString($v);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function settings(int $agencyId): array
    {
        $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $d = $raw ? (json_decode((string) $raw, true) ?: []) : [];

        return is_array($d) ? $d : [];
    }

    /**
     * Ready-to-use config for an agency, or NULL to use the platform default.
     * Only returns a config when the chosen provider is fully + validly set.
     */
    public static function configFor(int $agencyId): ?array
    {
        $mc = self::settings($agencyId)['mail_config'] ?? null;
        if (! is_array($mc)) {
            return null;
        }
        $provider = $mc['provider'] ?? 'default';
        $from = trim((string) ($mc['from'] ?? ''));
        $fromName = $mc['from_name'] ?? null;

        if ($provider === 'graph') {
            $g = $mc['graph'] ?? [];
            $secret = self::dec($g['client_secret'] ?? null);
            if (empty($g['tenant']) || empty($g['client_id']) || ! $secret || $from === '') {
                return null;
            }

            return ['provider' => 'graph', 'from' => $from, 'from_name' => $fromName,
                'graph' => ['tenant' => $g['tenant'], 'client_id' => $g['client_id'], 'client_secret' => $secret, 'from' => $from]];
        }

        if ($provider === 'google') {
            $s = $mc['google'] ?? [];
            $pass = self::dec($s['password'] ?? null);
            if (empty($s['username']) || ! $pass || $from === '') {
                return null;
            }

            return ['provider' => 'google', 'from' => $from, 'from_name' => $fromName,
                'google' => ['host' => $s['host'] ?: 'smtp.gmail.com', 'port' => (int) ($s['port'] ?: 587), 'username' => $s['username'], 'password' => $pass]];
        }

        return null;   // 'default' or unset → platform default
    }

    /** Which agency a recipient address belongs to (staff role OR guardian family). */
    /**
     * Which agency this message belongs to.
     *
     * agencyOfEmail() infers the agency from the RECIPIENT, which is a guess and is wrong
     * for anyone who belongs to more than one: it takes their first active role assignment.
     * iLearn's weekly attendance report was filed under the demo agency for exactly that
     * reason — its recipient held roles at both, and the wrong one came back first — so it
     * never appeared in the log of the agency that actually sent it.
     *
     * A sender that KNOWS the agency can now say so with X-KT-Agency-Id, and what it says
     * wins. The inference remains for the many senders that genuinely do not know.
     */
    public static function agencyForMessage($message, ?string $fallbackEmail = null): ?int
    {
        try {
            $h = method_exists($message, 'getHeaders') ? $message->getHeaders() : null;
            if ($h && $h->has('X-KT-Agency-Id')) {
                $v = (int) trim((string) $h->get('X-KT-Agency-Id')->getBodyAsString());
                if ($v > 0) {
                    return $v;
                }
            }
        } catch (\Throwable $e) {
        }

        return $fallbackEmail ? self::agencyOfEmail($fallbackEmail) : null;
    }

    public static function agencyOfEmail(string $email): ?int
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') {
            return null;
        }

        return Cache::remember('agencymail.aoe.' . md5($email), 60, function () use ($email) {
            $uid = DB::table('users')->whereRaw('LOWER(TRIM(email)) = ?', [$email])->value('id');
            if (! $uid) {
                return null;
            }
            // Staff / admin: agency on the role assignment, or via its centre.
            $ra = DB::table('role_assignments')->where('user_id', $uid)->where('active', 1)
                ->orderByRaw('agency_id IS NULL')->first(['agency_id', 'centre_id']);
            if ($ra) {
                if ($ra->agency_id) {
                    return (int) $ra->agency_id;
                }
                if ($ra->centre_id) {
                    $aid = DB::table('centres')->where('id', $ra->centre_id)->value('agency_id');
                    if ($aid) {
                        return (int) $aid;
                    }
                }
            }
            // Guardian: through their family's centre.
            $aid = DB::table('guardians as g')
                ->join('families as f', 'f.id', '=', 'g.family_id')
                ->join('centres as c', 'c.id', '=', 'f.centre_id')
                ->where('g.user_id', $uid)->value('c.agency_id');

            return $aid ? (int) $aid : null;
        });
    }

    /** Non-secret view for the settings UI (never returns the secret itself). */
    public static function publicConfig(int $agencyId): array
    {
        $mc = self::settings($agencyId)['mail_config'] ?? [];
        $g = $mc['graph'] ?? [];
        $s = $mc['google'] ?? [];

        return [
            'provider'  => $mc['provider'] ?? 'default',
            'from'      => $mc['from'] ?? null,
            'from_name' => $mc['from_name'] ?? null,
            'graph'     => ['tenant' => $g['tenant'] ?? null, 'client_id' => $g['client_id'] ?? null, 'secret_set' => ! empty($g['client_secret'])],
            'google'    => ['username' => $s['username'] ?? null, 'host' => $s['host'] ?? 'smtp.gmail.com', 'port' => $s['port'] ?? 587, 'password_set' => ! empty($s['password'])],
        ];
    }

    /** Persist config from the UI. Secrets are only overwritten when supplied. */
    public static function save(int $agencyId, array $in): void
    {
        $settings = self::settings($agencyId);
        $mc = $settings['mail_config'] ?? [];

        $mc['provider']  = in_array($in['provider'] ?? 'default', ['default', 'graph', 'google'], true) ? $in['provider'] : 'default';
        $mc['from']      = trim((string) ($in['from'] ?? '')) ?: null;
        $mc['from_name'] = trim((string) ($in['from_name'] ?? '')) ?: null;

        $g = $mc['graph'] ?? [];
        $g['tenant']    = trim((string) ($in['graph_tenant'] ?? '')) ?: null;
        $g['client_id'] = trim((string) ($in['graph_client_id'] ?? '')) ?: null;
        if (! empty($in['graph_client_secret'])) {
            $g['client_secret'] = self::enc($in['graph_client_secret']);
        }
        $mc['graph'] = $g;

        $s = $mc['google'] ?? [];
        $s['username'] = trim((string) ($in['google_username'] ?? '')) ?: null;
        $s['host']     = trim((string) ($in['google_host'] ?? '')) ?: 'smtp.gmail.com';
        $s['port']     = (int) ($in['google_port'] ?? 587) ?: 587;
        if (! empty($in['google_password'])) {
            $s['password'] = self::enc($in['google_password']);
        }
        $mc['google'] = $s;

        $settings['mail_config'] = $mc;
        DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($settings), 'updated_at' => now()]);
        Cache::flush();
    }
}
