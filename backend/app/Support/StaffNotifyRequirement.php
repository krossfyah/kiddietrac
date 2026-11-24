<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * REQUIRED APP NOTIFICATIONS FOR STAFF (2026-10-31, Anthony: "turn on app notifications for all educators as part of
 * their duties with iLearn").
 *
 * Nobody can switch a phone's notification permission on remotely, so an agency can make it part of the job instead:
 * agencies.settings.staff_notifications = {required: bool, roles: [educator, centre_director, home_visitor]}.
 * When it is on, staff in those roles see a required step in the app until their phone can receive notifications
 * (kt-notify-gate.js), the duty notification categories are shown as locked in their settings, and admins get a
 * readiness list (who has a working phone registration, who only has a browser, who has none).
 *
 * Evidence used: device_tokens (android/ios rows = the app registered for push; web rows = browser push) and the
 * push audit (push.no_device = we tried and had nowhere to send it). Default OFF for every agency.
 */
final class StaffNotifyRequirement
{
    public const ROLES = ['educator' => 'Educators', 'centre_director' => 'Directors', 'home_visitor' => 'Home visitors'];
    /** The NotificationSounds categories staff may not do without while it is required. */
    public const LOCKED = ['headcount', 'safety', 'staff', 'chat', 'announcement', 'emergency'];
    /** A phone registration older than this is treated as gone (the app re-posts its token on every start/resume). */
    public const STALE_DAYS = 30;

    public static function settings(int $agencyId): array
    {
        $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $all = is_string($raw) ? (json_decode($raw, true) ?: []) : (array) $raw;
        $s = (array) ($all['staff_notifications'] ?? []);
        $roles = array_values(array_intersect(array_keys(self::ROLES), (array) ($s['roles'] ?? array_keys(self::ROLES))));
        return ['required' => (bool) ($s['required'] ?? false), 'roles' => $roles];
    }

    public static function save(int $agencyId, bool $required, array $roles): array
    {
        $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $all = is_string($raw) ? (json_decode($raw, true) ?: []) : (array) $raw;
        $all['staff_notifications'] = ['required' => $required, 'roles' => array_values(array_intersect(array_keys(self::ROLES), $roles))];
        DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($all), 'updated_at' => now()]);
        return self::settings($agencyId);
    }

    /** The person's active roles in this agency (directly or through one of its centres). */
    public static function rolesIn(int $agencyId, int $userId): array
    {
        $centres = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all();
        return DB::table('role_assignments')->where('user_id', $userId)->where('active', 1)
            ->where(function ($q) use ($agencyId, $centres) { $q->where('agency_id', $agencyId)->orWhereIn('centre_id', $centres ?: [0]); })
            ->pluck('role')->unique()->values()->all();
    }

    /** Does this agency require notifications from this person? Platform admins and website-demo people never. */
    public static function requiredFor(int $agencyId, int $userId): bool
    {
        $s = self::settings($agencyId);
        if (! $s['required'] || ! $s['roles']) return false;
        if (PublicDemo::isUser($userId)) return false;
        if (ChatAudience::isServiceEmail((string) DB::table('users')->where('id', $userId)->value('email'))) return false;
        if (DB::table('role_assignments')->where('user_id', $userId)->where('active', 1)->where('role', 'platform_admin')->exists()) return false;
        return (bool) array_intersect($s['roles'], self::rolesIn($agencyId, $userId));
    }

    /** What the server knows about this person's devices. */
    public static function devices(int $userId): array
    {
        $rows = DB::table('device_tokens')->where('user_id', $userId)->orderByDesc('last_active_at')
            ->get(['platform', 'app_build', 'last_active_at', 'device_name', 'perms', 'perms_checked_at']);
        $cut = now()->subDays(self::STALE_DAYS);
        $phone = $rows->first(fn ($r) => in_array($r->platform, ['android', 'ios'], true) && $r->last_active_at && $r->last_active_at >= $cut);
        $web = $rows->first(fn ($r) => $r->platform === 'web' && $r->last_active_at && $r->last_active_at >= $cut);
        /* App version, phone and OS in words, plus what the device lets KiddieTrac do (2026-10-31, Anthony). The newest
           device first; one per distinct phone/browser (the same browser re-subscribing makes several rows). */
        $latest = (int) PlatformSettings::get('app.play.latest_build', 63);
        $label = function ($r) use ($latest) {
            $perms = $r->perms ? (json_decode((string) $r->perms, true) ?: null) : null;
            $build = $r->app_build ? (int) $r->app_build : null;
            return [
                'platform' => $r->platform,
                'app_build' => $build,
                'app_version' => DeviceLabel::appVersion($r->platform, $build) ?? ($r->platform === 'web' ? 'Browser' : null),
                'update_available' => $r->platform === 'android' && $build && $latest && $build < $latest,
                'model' => DeviceLabel::model((string) $r->device_name),
                'os' => DeviceLabel::os((string) $r->device_name, $r->platform),
                'last_active_at' => (string) $r->last_active_at,
                'perms' => $perms,
                'perms_checked_at' => $r->perms_checked_at ? (string) $r->perms_checked_at : null,
            ];
        };
        /* THE APP WITHOUT A PUSH TOKEN (2026-11-02, Sukhman's iPhone): every install that reported itself, from
           device_presence, so a phone that has not allowed notifications yet still shows with its version, phone, OS and
           what it is missing. Display only; the push address is still the token row above. */
        $installs = DevicePresence::forUser($userId, self::STALE_DAYS);
        $appLabel = function ($r) use ($latest) {
            $perms = $r->perms ? (json_decode((string) $r->perms, true) ?: []) : [];
            $build = $r->app_build ? (int) $r->app_build : null;
            return [
                'platform' => $r->platform,
                'app_build' => $build,
                'app_version' => DeviceLabel::appVersion($r->platform, $build),
                'update_available' => $r->platform === 'android' && $build && $latest && $build < $latest,
                'model' => DeviceLabel::model((string) $r->device_name),
                'os' => DeviceLabel::os((string) $r->device_name, $r->platform),
                'last_active_at' => (string) $r->last_seen_at,
                'perms' => $perms ?: null,
                'perms_checked_at' => (string) $r->last_seen_at,
                'has_push' => (bool) $r->has_push,
                'source' => 'app',
            ];
        };
        $app = $installs->first();
        $phoneOut = $phone ? $label($phone) : null;
        if ($phoneOut && $app && $app->platform === $phone->platform && (! $phoneOut['perms'] || $app->last_seen_at > ($phone->perms_checked_at ?? ''))) {
            $phoneOut['perms'] = $app->perms ? (json_decode((string) $app->perms, true) ?: $phoneOut['perms']) : $phoneOut['perms'];   // the freshest report from this phone
            $phoneOut['perms_checked_at'] = (string) $app->last_seen_at;
            if (! $phoneOut['app_build'] && $app->app_build) { $phoneOut['app_build'] = (int) $app->app_build; $phoneOut['app_version'] = DeviceLabel::appVersion($app->platform, (int) $app->app_build); }
        }
        $all = [];
        $seen = [];
        foreach ($rows as $r) {
            $k = $r->platform . '|' . (string) $r->device_name;
            if (isset($seen[$k])) continue;
            $seen[$k] = 1;
            $all[] = $label($r);
            if (count($all) >= 8) break;
        }
        foreach ($installs as $r) {
            $k = $r->platform . '|' . (string) $r->device_name;
            if (isset($seen[$k]) || count($all) >= 8) continue;
            $seen[$k] = 1;
            $all[] = $appLabel($r);
        }
        usort($all, fn ($a, $b) => strcmp($b['last_active_at'], $a['last_active_at']));
        return [
            'phone' => $phoneOut,
            'app' => $app ? $appLabel($app) : null,
            'web' => (bool) $web,
            'ever_phone' => $rows->contains(fn ($r) => in_array($r->platform, ['android', 'ios'], true)) || $installs->isNotEmpty(),
            'latest_build' => $latest,
            'all' => $all,
        ];
    }

    /** "SM-S938W" / "iPhone" from a user agent, for the readiness list. */
    private static function model(string $ua): ?string
    {
        if (preg_match('/Android [\d.]+; ([^;)]+?)(?: Build|\))/', $ua, $m)) return trim($m[1]);
        if (stripos($ua, 'iPhone') !== false) return 'iPhone';
        if (stripos($ua, 'iPad') !== false) return 'iPad';
        return null;
    }

    /** ready = the app on a phone is registered for notifications; app_off = the app is on a phone but notifications are
     *  not allowed (not asked yet, or refused); web_only = only a browser; none = nothing. */
    public static function status(array $devices): string
    {
        $denied = fn ($d) => $d && is_array($d['perms'] ?? null) && in_array($d['perms']['notifications'] ?? '', ['denied', 'prompt'], true);
        if ($devices['phone'] && ! $denied($devices['phone'])) return 'ready';
        if ($devices['phone'] || ($devices['app'] ?? null)) return 'app_off';
        if ($devices['web']) return 'web_only';
        return 'none';
    }

    /**
     * The readiness list for an agency (optionally narrowed to a director's centres).
     * Staff in the required roles, plus agency admins for information.
     */
    public static function readiness(int $agencyId, ?array $centreIds = null): array
    {
        $centres = DB::table('centres')->where('agency_id', $agencyId)->whereNull('deleted_at')->pluck('name', 'id');
        $rows = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.active', 1)->whereNull('u.deleted_at')
            ->whereIn('ra.role', ['educator', 'centre_director', 'home_visitor', 'agency_admin'])
            ->where(function ($q) use ($agencyId, $centres) { $q->where('ra.agency_id', $agencyId)->orWhereIn('ra.centre_id', $centres->keys()->all() ?: [0]); })
            ->get(['u.id', 'u.first_name', 'u.last_name', 'u.email', 'u.photo_url', 'u.last_login_at', 'u.last_seen_at', 'ra.role', 'ra.centre_id']);
        $s = self::settings($agencyId);
        $people = [];
        foreach ($rows as $r) {
            if (PublicDemo::isUser((int) $r->id) || ChatAudience::isServiceEmail($r->email)) continue;   // demo people and service logins (iLearn integration)
            if ($centreIds !== null && $r->centre_id && ! in_array((int) $r->centre_id, $centreIds, true)) continue;
            $p = $people[$r->id] ?? ['user_id' => (int) $r->id, 'first_name' => (string) $r->first_name, 'name' => trim($r->first_name . ' ' . $r->last_name),
                'email' => (string) $r->email, 'photo_url' => $r->photo_url, 'roles' => [], 'centres' => [],
                'last_seen' => (string) ($r->last_seen_at ?: $r->last_login_at ?: '')];
            $p['roles'][] = $r->role;
            if ($r->centre_id && isset($centres[$r->centre_id])) $p['centres'][] = $centres[$r->centre_id];
            $people[$r->id] = $p;
        }
        if ($centreIds !== null) {
            // A director sees the people who work at their centres only (agency-level roles without a centre are left out).
            $people = array_filter($people, fn ($p) => (bool) $p['centres']);
        }
        $ids = array_keys($people);
        $lastPush = DB::table('audit_logs')->whereIn('action', ['push.sent', 'push.no_device', 'push.failed'])
            ->where('created_at', '>=', now()->subDays(30))->where('payload', 'like', '%"channel":"fcm"%')
            ->orderByDesc('id')->limit(3000)->get(['action', 'payload', 'created_at']);
        $last = [];
        foreach ($lastPush as $a) {
            $pl = json_decode((string) $a->payload, true) ?: [];
            $u = (int) ($pl['to_user'] ?? 0);
            if ($u && in_array($u, $ids, true) && ! isset($last[$u])) {
                $last[$u] = ['outcome' => substr((string) $a->action, 5), 'at' => (string) $a->created_at, 'title' => (string) ($pl['title'] ?? '')];
            }
        }
        $out = [];
        foreach ($people as $uid => $p) {
            $p['roles'] = array_values(array_unique($p['roles']));
            $p['centres'] = array_values(array_unique($p['centres']));
            $p['required'] = $s['required'] && (bool) array_intersect($s['roles'], $p['roles']);
            $p['devices'] = self::devices((int) $uid);
            $p['status'] = self::status($p['devices']);
            $p['last_push'] = $last[$uid] ?? null;
            $out[] = $p;
        }
        usort($out, fn ($a, $b) => [$a['status'] === 'ready', $a['name']] <=> [$b['status'] === 'ready', $b['name']]);
        $count = fn ($st) => count(array_filter($out, fn ($p) => $p['status'] === $st));
        return ['settings' => $s, 'people' => $out,
            'counts' => ['total' => count($out), 'ready' => $count('ready'), 'app_off' => $count('app_off'), 'web_only' => $count('web_only'), 'none' => $count('none'),
                'required_missing' => count(array_filter($out, fn ($p) => $p['required'] && $p['status'] !== 'ready'))]];
    }

    /** The setup-instructions email (Android + iPhone steps). Prepared for admins; only sent when they confirm. */
    public static function instructionsEmail(int $agencyId, array $p): array
    {
        $agency = (string) DB::table('agencies')->where('id', $agencyId)->value('name');
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $first = trim((string) $p['first_name']) ?: 'there';
        $body = '<p style="margin:0 0 12px;font-size:15px;color:#334155;line-height:1.6">Hi ' . $e($first) . ',</p>'
            . '<p style="margin:0 0 12px;font-size:15px;color:#334155;line-height:1.6">' . $e($agency) . ' now requires KiddieTrac notifications on your phone as part of your role, '
            . 'so you never miss a headcount reminder, a Safe Arrival alert, an urgent message or an announcement.</p>'
            . '<div style="background:#F1F5F9;border-radius:10px;padding:14px 16px;margin:0 0 12px">'
            . '<div style="font-size:12px;font-weight:800;color:#475569;letter-spacing:.06em;text-transform:uppercase;margin-bottom:6px">Android</div>'
            . '<div style="font-size:14.5px;color:#0F172A;line-height:1.6">1. Install or update <b>KiddieTrac</b> from Google Play and sign in.<br>2. When asked, tap <b>Allow</b> for notifications.<br>'
            . '3. Already said no? Open <b>Settings → Apps → KiddieTrac → Notifications</b> and switch them on, including <b>Staff reminders</b> and <b>Headcount reminders</b>.</div></div>'
            . '<div style="background:#F1F5F9;border-radius:10px;padding:14px 16px;margin:0 0 12px">'
            . '<div style="font-size:12px;font-weight:800;color:#475569;letter-spacing:.06em;text-transform:uppercase;margin-bottom:6px">iPhone</div>'
            . '<div style="font-size:14.5px;color:#0F172A;line-height:1.6">1. Install or update <b>KiddieTrac</b> from the App Store and sign in.<br>2. When asked, tap <b>Allow</b>.<br>'
            . '3. Already said no? Open the iPhone <b>Settings → KiddieTrac → Notifications</b> and turn on <b>Allow Notifications</b>.</div></div>'
            . '<p style="margin:0;font-size:14px;color:#334155;line-height:1.6">The app will also walk you through this the next time you open it.</p>';
        $subject = 'Action needed: turn on KiddieTrac notifications on your phone';
        $html = \App\Services\EmailTemplate::wrap($agencyId, $body, ['eyebrow' => 'FOR YOUR ROLE', 'title' => 'Turn on app notifications',
            'subtitle' => $agency, 'preheader' => 'Headcounts, Safe Arrival alerts and messages reach you on your phone.']);
        return [$subject, $html];
    }
}
