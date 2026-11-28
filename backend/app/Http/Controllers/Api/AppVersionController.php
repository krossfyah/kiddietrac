<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\PlatformSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * GOOGLE PLAY UPDATE PROMPTS (2026-10-30, Anthony: "if there is a new google play release can the app notify the user
 * or force the user to download the new release?" → "Both, staged").
 *
 * Two numbers, set by a platform admin under Platform → Maintenance → Mobile app updates:
 *   app.play.latest_build  the versionCode live on Google Play. An installed app below it shows "Update available"
 *                          (a banner, or Google's in-app download when the app has it, 3.12.9+).
 *   app.play.min_build     the oldest build still allowed. Below it the app shows a blocking "Update required" screen
 *                          (sign-out and help stay reachable). 0 = nobody is blocked.
 * plus an optional message. Read by kt-appupdate.js through the public GET /app/versions (no sign-in: the check runs
 * before and after sign-in alike). Saving also keeps /version.json "play" (shown in About) in step.
 *
 * IPHONE (2026-11-02, Anthony: the iPhone app should prompt "same as Android"). The "ios" block:
 *   store_version / store_url / track_id / released_at  what the App Store really lists (Apple's public lookup,
 *                          asked from here and cached 15 minutes). Not listed → store_version null → the app shows nothing.
 *   app.ios.min_version    the oldest version still allowed ("" = nobody is blocked). Only enforced once the store
 *                          lists a version at least that new.
 *   app.ios.latest_version optional: prompt only apps below it (and only once the store lists it).
 *   app.ios.message        optional wording for the prompt.
 * ?bundle= lets a white-label iPhone app ask about its own App Store entry, limited to IOS_BUNDLES (never an open proxy).
 */
class AppVersionController extends Controller
{
    private const DEFAULT_LATEST = 63;   // 3.12.6, live on Google Play when this was written

    private const IOS_BUNDLE = 'com.kiddietrac.app';
    /** iPhone apps whose App Store entry may be looked up. Add a white-label bundle id here when one ships. */
    private const IOS_BUNDLES = ['com.kiddietrac.app'];
    private const IOS_COUNTRY = 'ca';
    private const VERSION_RE = '/^\d+(\.\d+){0,3}$/';

    private static function data(): array
    {
        $latest = (int) PlatformSettings::get('app.play.latest_build', self::DEFAULT_LATEST);
        $min = (int) PlatformSettings::get('app.play.min_build', 0);
        return [
            'latest_build' => $latest,
            'min_build' => min($min, $latest),
            'version_name' => (string) PlatformSettings::get('app.play.version_name', ''),
            'message' => (string) PlatformSettings::get('app.play.message', ''),
            'package' => 'com.kiddietrac.app',   // the app sends its own id (white-label brands differ); this is the fallback
        ];
    }

    /** What the App Store lists for a bundle id: cached 15 minutes (2 minutes after a failed lookup). */
    private static function iosStore(string $bundle, bool $fresh = false): array
    {
        $key = 'app-versions:ios-store:' . $bundle;
        if (! $fresh) {
            $hit = Cache::get($key);
            if (is_array($hit)) return $hit;
        }
        $out = ['store_version' => null, 'store_url' => null, 'track_id' => null, 'released_at' => null, 'checked_at' => now()->toIso8601String(), 'lookup_ok' => false];
        $ttl = 120;
        try {
            $res = Http::timeout(6)->acceptJson()->get('https://itunes.apple.com/lookup', [
                'bundleId' => $bundle, 'country' => self::IOS_COUNTRY, 't' => (int) floor(time() / 60),
            ]);
            if ($res->ok()) {
                $j = json_decode((string) $res->body(), true);   // Apple answers text/javascript
                if (is_array($j)) {
                    $out['lookup_ok'] = true;
                    $ttl = 900;
                    foreach ((array) ($j['results'] ?? []) as $row) {
                        if (! is_array($row) || ($row['bundleId'] ?? null) !== $bundle) continue;
                        $v = (string) ($row['version'] ?? '');
                        if (! preg_match(self::VERSION_RE, $v)) continue;
                        $url = (string) ($row['trackViewUrl'] ?? '');
                        $out['store_version'] = $v;
                        $out['store_url'] = preg_match('#^https://apps\.apple\.com/#', $url) ? $url : null;
                        $out['track_id'] = isset($row['trackId']) && is_numeric($row['trackId']) ? (int) $row['trackId'] : null;
                        $out['released_at'] = isset($row['currentVersionReleaseDate']) ? (string) $row['currentVersionReleaseDate'] : null;
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::info('App Store lookup failed', ['bundle' => $bundle, 'error' => $e->getMessage()]);
        }
        Cache::put($key, $out, $ttl);
        return $out;
    }

    private static function iosData(?string $bundle = null, bool $fresh = false): array
    {
        $bundle = $bundle ?: self::IOS_BUNDLE;
        $known = in_array($bundle, self::IOS_BUNDLES, true);
        $store = $known ? self::iosStore($bundle, $fresh)
            : ['store_version' => null, 'store_url' => null, 'track_id' => null, 'released_at' => null, 'checked_at' => null, 'lookup_ok' => false];
        return array_merge([
            'bundle' => $known ? $bundle : null,
            'min_version' => (string) PlatformSettings::get('app.ios.min_version', ''),
            'latest_version' => (string) PlatformSettings::get('app.ios.latest_version', ''),
            'message' => (string) PlatformSettings::get('app.ios.message', ''),
        ], $store);
    }

    /* WHAT GOOGLE PLAY REALLY LISTS (2026-11-03, Anthony: "the about screen under user profile shows a old google
       play version - why is this not being updated"). About read version.json's "play", which was only rewritten when
       somebody saved Platform -> Mobile app updates after a release - nobody did, so it said 3.12.5 (62) while Play
       handed out 3.12.17 (73). Play has no public lookup like Apple's, but its listing page carries the current
       version as [[["3.12.17"]]; read it here, cached 30 minutes (2 after a failed read). The build NUMBER is not on
       the listing, so latest_build stays the admin's (the Platform card says when it is behind the listing). */
    public static function playStore(bool $fresh = false): array
    {
        $key = 'app-versions:play-store';
        if (! $fresh) {
            $hit = Cache::get($key);
            if (is_array($hit)) return $hit;
        }
        $out = ['store_version' => null, 'checked_at' => now()->toIso8601String(), 'lookup_ok' => false];
        $ttl = 120;
        try {
            $res = Http::timeout(8)->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0 Safari/537.36', 'Accept-Language' => 'en-CA,en;q=0.8'])
                ->get('https://play.google.com/store/apps/details', ['id' => 'com.kiddietrac.app', 'hl' => 'en', 'gl' => 'CA']);
            if ($res->ok() && preg_match('/\[\[\["(\d+(?:\.\d+){1,3})"\]\]/', (string) $res->body(), $m)) {
                $out['store_version'] = $m[1];
                $out['lookup_ok'] = true;
                $ttl = 1800;
            }
        } catch (\Throwable $e) {
            Log::info('Google Play lookup failed', ['error' => $e->getMessage()]);
        }
        Cache::put($key, $out, $ttl);
        return $out;
    }

    /** -1 / 0 / 1, dotted versions compared number by number (3.12.10 > 3.12.9). */
    private static function vcmp(string $a, string $b): int
    {
        return version_compare(self::norm($a), self::norm($b));
    }

    private static function norm(string $v): string
    {
        $p = array_map('intval', explode('.', $v));
        while (count($p) < 4) $p[] = 0;
        return implode('.', $p);
    }

    /** GET /app/versions (public). ?bundle= (iPhone white-label apps) must be a known bundle id. */
    public function show(Request $r): JsonResponse
    {
        $bundle = (string) $r->query('bundle', '');
        if (! preg_match('/^[A-Za-z0-9.\-]{3,100}$/', $bundle)) $bundle = '';
        $ios = self::iosData($bundle ?: null);
        unset($ios['lookup_ok'], $ios['checked_at']);
        $play = self::data() + ['store_version' => self::playStore()['store_version']];
        return response()->json(['play' => $play, 'ios' => $ios])->header('Cache-Control', 'no-store, max-age=0');
    }

    /* FORCED UPDATE, NATIVE GATE (2026-11-27, Anthony: "the kiddietrac app is not detecting the new updated app in the
       google playstore and forcing the user to download this, also if it does the app should not show anything in the
       background and force user to update"). From Android 3.13.3 (79) the app itself asks this before it loads the
       portal: installed build below min_version_code → a full-screen "Update required" (Google Play's own update when
       Play offers it, else the store page). It is asked on every launch and every return to the app, so it reads only
       the stored settings: no Google Play / App Store lookups that could make a launch wait (GET /app/versions does
       those). Public, no sign-in, no agency. The app lets people in when this does not answer (offline, server down).
       min_version_code never exceeds latest_version_code (save() refuses that), so nobody is blocked waiting for a
       release that is not out. */
    /** GET /app/min-version?platform=android|ios (public, cached 60 s). */
    public function minVersion(Request $r): JsonResponse
    {
        $platform = strtolower((string) $r->query('platform', 'android')) === 'ios' ? 'ios' : 'android';
        if ($platform === 'ios') {
            $out = [
                'platform' => 'ios',
                'min_version' => (string) PlatformSettings::get('app.ios.min_version', ''),
                'latest_version' => (string) PlatformSettings::get('app.ios.latest_version', ''),
                'message' => (string) PlatformSettings::get('app.ios.message', ''),
            ];
        } else {
            $d = self::data();
            $out = [
                'platform' => 'android',
                'package' => $d['package'],
                'min_version_code' => (int) $d['min_build'],
                'latest_version_code' => (int) $d['latest_build'],
                'latest_version_name' => $d['version_name'],
                'message' => $d['message'],
                'store_url' => 'https://play.google.com/store/apps/details?id=' . $d['package'],
            ];
        }
        return response()->json($out)->header('Cache-Control', 'public, max-age=60');
    }

    /** GET /platform/app-versions (asks the App Store afresh, so the admin sees a release as soon as Apple lists it). */
    public function settings(): JsonResponse
    {
        return response()->json(['play' => self::data() + ['store_version' => self::playStore(true)['store_version']], 'ios' => self::iosData(null, true)]);
    }

    /** PUT /platform/app-versions {latest_build, min_build, version_name?, message?}  or  {ios: {min_version?, latest_version?, message?}} */
    public function save(Request $r): JsonResponse
    {
        if ($r->has('ios')) return $this->saveIos($r);
        $d = $r->validate([
            'latest_build' => 'required|integer|min:1|max:100000',
            'min_build' => 'required|integer|min:0|max:100000',
            'version_name' => ['nullable', 'string', 'max:20', 'regex:/^\d+(\.\d+){1,3}$/'],
            'message' => 'nullable|string|max:300',
        ]);
        abort_if($d['min_build'] > $d['latest_build'], 422, 'The minimum build cannot be newer than the latest build.');
        $before = self::data();
        PlatformSettings::set('app.play.latest_build', (string) $d['latest_build']);
        PlatformSettings::set('app.play.min_build', (string) $d['min_build']);
        PlatformSettings::set('app.play.version_name', (string) ($d['version_name'] ?? ''));
        PlatformSettings::set('app.play.message', trim((string) ($d['message'] ?? '')));
        $this->syncVersionJson($d['latest_build'], $d['version_name'] ?? null);
        try {
            \App\Support\Audit::write(['user_id' => $r->user()?->id, 'action' => 'platform.app_versions', 'entity_type' => 'platform', 'entity_id' => null,
                'payload' => json_encode(['summary' => 'Mobile app updates: latest build ' . $d['latest_build'] . ', minimum ' . $d['min_build'],
                    'before' => ['latest' => $before['latest_build'], 'min' => $before['min_build']]]), 'created_at' => now()]);
        } catch (\Throwable $e) {}
        return response()->json(['play' => self::data(), 'ios' => self::iosData()]);
    }

    private function saveIos(Request $r): JsonResponse
    {
        $d = $r->validate([
            'ios' => 'required|array',
            'ios.min_version' => ['nullable', 'string', 'max:20', 'regex:' . self::VERSION_RE],
            'ios.latest_version' => ['nullable', 'string', 'max:20', 'regex:' . self::VERSION_RE],
            'ios.message' => 'nullable|string|max:300',
        ], [
            'ios.min_version.regex' => 'The minimum version must look like 3.12.10.',
            'ios.latest_version.regex' => 'The latest version must look like 3.12.12.',
            'ios.min_version.max' => 'The minimum version must look like 3.12.10.',
            'ios.latest_version.max' => 'The latest version must look like 3.12.12.',
            'ios.message.max' => 'The message can be at most 300 characters.',
        ])['ios'];
        $min = trim((string) ($d['min_version'] ?? ''));
        $latest = trim((string) ($d['latest_version'] ?? ''));
        abort_if($min !== '' && $latest !== '' && self::vcmp($min, $latest) > 0, 422, 'The minimum version cannot be newer than the latest version.');
        $before = self::iosData();
        PlatformSettings::set('app.ios.min_version', $min);
        PlatformSettings::set('app.ios.latest_version', $latest);
        PlatformSettings::set('app.ios.message', trim((string) ($d['message'] ?? '')));
        try {
            \App\Support\Audit::write(['user_id' => $r->user()?->id, 'action' => 'platform.app_versions', 'entity_type' => 'platform', 'entity_id' => null,
                'payload' => json_encode(['summary' => 'Mobile app updates (iPhone): latest version ' . ($latest ?: 'not set') . ', minimum ' . ($min ?: 'none'),
                    'before' => ['latest' => $before['latest_version'], 'min' => $before['min_version'], 'message' => $before['message']]]), 'created_at' => now()]);
        } catch (\Throwable $e) {}
        return response()->json(['play' => self::data(), 'ios' => self::iosData()]);
    }

    /** /version.json "play" is what About shows as "Mobile app on Google Play": keep it the same as the setting. */
    private function syncVersionJson(int $build, ?string $name): void
    {
        try {
            $f = base_path('../parent-portal/version.json');
            if (! is_file($f) || ! is_writable($f)) return;
            $j = json_decode((string) file_get_contents($f), true);
            if (! is_array($j)) return;
            $j['play'] = array_merge(is_array($j['play'] ?? null) ? $j['play'] : [], array_filter([
                'versionCode' => $build,
                'versionName' => $name ?: ($j['play']['versionName'] ?? null),
            ], fn ($v) => $v !== null));
            $tmp = $f . '.uploading';
            file_put_contents($tmp, json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            rename($tmp, $f);
        } catch (\Throwable $e) {
            Log::warning('version.json sync failed', ['error' => $e->getMessage()]);
        }
    }
}
