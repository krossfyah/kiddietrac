<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * THE APP ON A PHONE, WITH OR WITHOUT A PUSH TOKEN (2026-11-02).
 *
 * Anthony: "sukhman installed the testflight app but under staff notifications doesnt show that info". Proven from the
 * logs: she signed in on the iPhone app at 15:44:16 UTC and left it about six seconds after the home screen appeared.
 * The app asks for notification permission only at a calm moment and reports its permissions 8 s after load, and the
 * server stored a report only on a device_tokens row (a push address), so a phone that had not allowed notifications
 * yet left no trace and the readiness list said "no app registered".
 *
 * Now the app reports itself on every start and when it goes to the background (kt-device-perms.js → POST
 * /me/device-permissions with install_id), and this table keeps one row per install for whoever is signed in on it.
 * It is display-only evidence: senders read device_tokens, never this.
 *
 * Also the switch for the mandatory app permissions gate (Anthony, 2026-11-02: "this should be mandatory for the app to
 * work properly including GPS"): PlatformSettings app.require_permissions ('1' on, '0' off, default on).
 */
final class DevicePresence
{
    public const SETTING = 'app.require_permissions';

    /** Is the native-app permissions gate switched on platform-wide? */
    public static function gateOn(): bool
    {
        return (string) PlatformSettings::get(self::SETTING, '1') !== '0';
    }

    /** Must this person allow notifications + location in the native app? Exempt: platform admins, website-demo people, service logins. */
    public static function gateFor(int $userId): bool
    {
        if (! self::gateOn()) return false;
        if (PublicDemo::isUser($userId)) return false;
        if (ChatAudience::isServiceEmail((string) DB::table('users')->where('id', $userId)->value('email'))) return false;
        if (DB::table('role_assignments')->where('user_id', $userId)->where('active', 1)->where('role', 'platform_admin')->exists()) return false;
        /* A staged switch: 'agencies:6,25' = on only for people of those agencies (staff by role or centre, parents by
           family), for trying it on Test Agency before everyone. */
        $v = (string) PlatformSettings::get(self::SETTING, '1');
        if (str_starts_with($v, 'agencies:')) {
            $ids = array_values(array_filter(array_map('intval', explode(',', substr($v, 9)))));
            if (! $ids) return false;
            $centres = DB::table('centres')->whereIn('agency_id', $ids)->pluck('id')->all() ?: [0];
            $staff = DB::table('role_assignments')->where('user_id', $userId)->where('active', 1)
                ->where(fn ($q) => $q->whereIn('agency_id', $ids)->orWhereIn('centre_id', $centres))->exists();
            if ($staff) return true;
            return DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')
                ->where('g.user_id', $userId)->whereIn('f.centre_id', $centres)->exists();
        }
        return true;
    }

    /** Record (or move to this person) the app install that is reporting. */
    public static function record(int $userId, string $installId, string $platform, ?int $build, string $ua, array $perms, bool $hasPush): void
    {
        if (! in_array($platform, ['ios', 'android'], true)) return;
        $installId = substr(preg_replace('/[^A-Za-z0-9_-]/', '', $installId), 0, 64);
        if (strlen($installId) < 8) return;
        $row = [
            'user_id' => $userId,
            'platform' => $platform,
            'device_name' => mb_substr($ua, 0, 120) ?: null,
            'notifications' => $perms['notifications'] ?? null,
            'location' => $perms['location'] ?? null,
            'perms' => json_encode($perms),
            'has_push' => $hasPush,
            'last_seen_at' => now(),
            'updated_at' => now(),
        ];
        if ($build) $row['app_build'] = $build;
        if (DB::table('device_presence')->where('install_id', $installId)->update($row)) return;
        try {
            DB::table('device_presence')->insert($row + ['install_id' => $installId, 'created_at' => now()]);
        } catch (\Throwable $e) {
            // Two reports at once (start-up and a quick background): the other one inserted it.
            DB::table('device_presence')->where('install_id', $installId)->update($row);
        }
    }

    /** This person's app installs seen in the last $days, newest first. */
    public static function forUser(int $userId, int $days = 30)
    {
        return DB::table('device_presence')->where('user_id', $userId)->where('last_seen_at', '>=', now()->subDays($days))
            ->orderByDesc('last_seen_at')->get(['install_id', 'platform', 'app_build', 'device_name', 'notifications', 'location', 'perms', 'has_push', 'last_seen_at', 'updated_at']);
    }
}
