<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-wide key/value settings (superadmin-managed), e.g. outbound-mail
 * routing (sendmail vs Microsoft Graph). Secrets are encrypted at rest.
 */
class PlatformSettings
{
    private const CACHE_KEY = 'platform_settings.all';

    public static function all(): array
    {
        return Cache::remember(self::CACHE_KEY, 30, function () {
            if (! Schema::hasTable('platform_settings')) {
                return [];
            }

            return DB::table('platform_settings')->pluck('value', 'key')->toArray();
        });
    }

    public static function get(string $key, $default = null)
    {
        $all = self::all();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public static function set(string $key, $value): void
    {
        DB::table('platform_settings')->updateOrInsert(
            ['key' => $key],
            ['value' => $value, 'updated_at' => now(), 'created_at' => now()]
        );
        Cache::forget(self::CACHE_KEY);
    }

    /** Store an encrypted secret. */
    public static function setSecret(string $key, string $plain): void
    {
        self::set($key, Crypt::encryptString($plain));
    }

    /** Read + decrypt a stored secret (null if unset/undecryptable). */
    public static function getSecret(string $key): ?string
    {
        $v = self::get($key);
        if ($v === null || $v === '') {
            return null;
        }
        try {
            return Crypt::decryptString($v);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Apply mail settings to the runtime config. Called from AppServiceProvider
     * so a superadmin can switch KiddieTrac between sendmail and Microsoft Graph
     * from the portal without a redeploy. RESILIENT BY DESIGN: when Graph is the
     * chosen mailer we route through a FAILOVER transport (graph → sendmail), so
     * an expired secret / Graph outage silently falls back to sendmail and email
     * never stops.
     */
    public static function applyMail(): void
    {
        $s = self::all();
        if (! $s) {
            return;
        }

        $secret = self::getSecret('mail.graph.client_secret');
        $graph = [
            'transport'     => 'graph',
            'tenant'        => $s['mail.graph.tenant'] ?? null,
            'client_id'     => $s['mail.graph.client_id'] ?? null,
            'client_secret' => $secret,
            'from'          => $s['mail.graph.from'] ?? ($s['mail.from'] ?? null),
        ];
        config(['mail.mailers.graph' => $graph]);
        // Graph first, local sendmail as the automatic safety net.
        config(['mail.mailers.failover' => ['transport' => 'failover', 'mailers' => ['graph', 'sendmail']]]);
        // The per-agency (white-label) router sits ABOVE the platform default:
        // it sends white-label agencies from their own M365/Google and everyone
        // else through the platform failover. Registered in AppServiceProvider.
        config(['mail.mailers.agency_router' => ['transport' => 'agency_router']]);

        $graphReady = $graph['tenant'] && $graph['client_id'] && $graph['client_secret'] && $graph['from'];
        $chosen = $s['mail.mailer'] ?? null;   // 'sendmail' | 'graph' | 'failover'
        if (($chosen === 'graph' || $chosen === 'failover') && $graphReady) {
            // Resilient AND white-label aware: router → (agency own | graph → sendmail).
            config(['mail.default' => 'agency_router']);
        } elseif ($chosen === 'sendmail') {
            config(['mail.default' => 'sendmail']);
        }
        // otherwise leave whatever .env configured

        if (! empty($s['mail.from'])) {
            config(['mail.from.address' => $s['mail.from']]);
        }
        if (! empty($s['mail.from_name'])) {
            config(['mail.from.name' => $s['mail.from_name']]);
        }
    }
}
