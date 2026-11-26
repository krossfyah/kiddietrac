<?php

namespace App\Http\Middleware;

use App\Support\TestAgencies;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * NO MEETINGS, LEADS OR CHATS FROM TEST VISITORS (2026-10-30, Anthony: "check the test agencies to see why they are
 * booking meetings - turn this off").
 *
 * Cause: Google Play's pre-launch report robots run the app on Google's test phones, sign in with the test-agency
 * account (Jessica, agency 6), tap "Contact us" on the sign-in page and fill in the website's booking wizard and chat
 * inside the app's web view. Bookings 8-10, lead 62 and three "Website chat waiting" emails to sales@ came from them.
 *
 * Website write endpoints (booking, lead, contact, chat) now quietly do nothing - they answer like a success, so a
 * robot just moves on - when the visitor is:
 *   - an Android web view on a Google-owned address (the Play / Firebase Test Lab devices; real families and staff
 *     reach us from their own carriers), or
 *   - using an email of someone who belongs only to a test agency, or a test domain (demo.testagency.com, *.invalid).
 */
class RejectTestVisitors
{
    private const GOOGLE = ['66.249.64.0/19', '64.233.160.0/19', '74.125.0.0/16', '108.177.0.0/17', '72.14.192.0/18',
        '209.85.128.0/17', '216.239.32.0/19', '173.194.0.0/16', '142.250.0.0/15', '172.217.0.0/16', '34.68.194.64/29'];
    // Public: App\Support\NoShow sends nothing to these either (2026-11-28).
    public const TEST_DOMAINS = ['/@(.+\.)?testagency\.com$/', '/\.invalid$/', '/@(.+\.)?example\.(com|org|net)$/', '/@brightwood\.kiddietrac\.com$/', '/@starlight\.kiddietrac\.com$/', '/\.example$/'];

    public function handle(Request $request, Closure $next)
    {
        if ($why = self::why($request)) {
            Log::info('website write ignored: ' . $why, ['path' => $request->path(), 'ip' => $request->ip()]);
            return response()->json(['ok' => true]);
        }
        return $next($request);
    }

    public static function why(Request $request): ?string
    {
        $ua = (string) $request->userAgent();
        if (str_contains($ua, '; wv)') && IpUtils::checkIp((string) $request->ip(), self::GOOGLE)) return 'Google Play test device';
        $email = strtolower(trim((string) $request->input('email', '')));
        if ($email === '') return null;
        foreach (self::TEST_DOMAINS as $re) if (preg_match($re, $email)) return 'test email domain';
        $ids = TestAgencies::testOnlyUserIds();
        if ($ids && DB::table('users')->whereIn('id', $ids)->whereRaw('LOWER(email) = ?', [$email])->exists()) return 'test agency account';
        return null;
    }
}
