<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * TEST AGENCIES SEND NOTHING AND SPEND NO AI (2026-11-24).
 *
 * Anthony: "for test agencies do not use AI and safe arrivals should be turnt off and in demo mode only and not send
 * anything out to anyone - full sweep".
 *
 * A test agency is any agency with settings.demo === true: Test Agency (#6) and the demo agencies (Ohio #18,
 * Brightwood #25, Starlight #26). This is wider than PublicDemo (demo AND demo_public), which also drives the nightly
 * web-demo reset, so it is a sibling rather than a widening of that class.
 *
 * Every outbound gate asks this, BEFORE any bypass (X-KT-Bypass-Suppression included):
 *   - email: SuppressAgencyMail (sender agency header, test session, test-only recipient);
 *   - texts: SmsGateway; calls: Telnyx; push: FcmService / WebPushService (via Suppression::isUser + session);
 *   - AI: AiConfig::offFor (agency + session), MessageTranslator (no translation for test-only people);
 *   - Safe Arrival: always a dry run (demo mode), nothing called, texted, pushed or emailed.
 * Agency #6 has REAL people on it (see memory): a test-only person is someone whose every agency is a test agency
 * and who is not a platform admin, so a person who also belongs to a real agency is still reached for THAT agency.
 */
final class TestAgency
{
    /** Platform-level work acts for KiddieTrac, not for the agency the admin happens to have switched into. */
    private const PLATFORM_PREFIXES = ['platform', 'sales', 'marketing-site', 'reseller', 'public', 'integration', 'email'];

    /** Anthony's own preview inbox: test-agency mail addressed ONLY here still goes out (Anthony, 2026-11-24). */
    public const PREVIEW_INBOXES = ['mr.anthonyhosein@gmail.com'];

    /** @var array<int,bool> */
    private static array $users = [];

    /**
     * @param string[] $to every recipient is a preview inbox
     *
     * AND the message says it is a preview (2026-11-25): a "[TEST] …" subject or an X-KT-Preview header. Anthony is
     * also a guardian at Test Agency #6, so his Gmail is a real recipient of #6's automatic mail; "the only recipient
     * is his Gmail" let #6's "🕘 Has Liam arrived?" reminders through every weekday morning. Previews mark
     * themselves (every test send in the codebase prefixes "[TEST] "); a real notification never does.
     */
    public static function previewOnly(array $to, string $subject = '', bool $marked = false): bool
    {
        if (! $to) {
            return false;
        }
        if (! $marked && ! str_starts_with(ltrim($subject), '[TEST]')) {
            return false;
        }
        foreach ($to as $e) {
            if (! in_array(mb_strtolower(trim((string) $e)), self::PREVIEW_INBOXES, true)) {
                return false;
            }
        }

        return true;
    }

    /** The agency the current web request is working in (null in the console or on platform-level screens). */
    public static function sessionAgency(): ?int
    {
        try {
            if (app()->runningInConsole() || ! app()->bound('request')) {
                return null;
            }
            $req = request();
            if ($req->attributes->has('kt_session_agency')) {
                return $req->attributes->get('kt_session_agency');
            }
            $a = null;
            $seg = (string) ($req->segment(3) ?? '');
            if (! in_array($seg, self::PLATFORM_PREFIXES, true) && ($u = $req->user('sanctum'))) {
                $r = AuditScope::resolve((int) $u->id, $req);
                $a = $r ? (int) $r : null;
            }
            $req->attributes->set('kt_session_agency', $a);

            return $a;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @return int[] agencies with settings.demo === true */
    public static function ids(): array
    {
        return Cache::remember('kt.test_agencies', 60, function () {
            $out = [];
            try {
                foreach (DB::table('agencies')->get(['id', 'settings']) as $a) {
                    $s = json_decode((string) $a->settings, true) ?: [];
                    if (($s['demo'] ?? false) === true) {
                        $out[] = (int) $a->id;
                    }
                }
            } catch (\Throwable $e) {
            }

            return $out;
        });
    }

    public static function isAgency(?int $agencyId): bool
    {
        return $agencyId !== null && $agencyId > 0 && in_array((int) $agencyId, self::ids(), true);
    }

    /** Belongs ONLY to test agencies (staff role or guardian), and is not a platform admin. */
    public static function isUser(?int $userId): bool
    {
        if (! $userId || ! self::ids()) {
            return false;
        }
        if (array_key_exists($userId, self::$users)) {
            return self::$users[$userId];
        }

        return self::$users[$userId] = Cache::remember('kt.test_only_user:'.$userId, 60, function () use ($userId) {
            $test = self::ids();
            $ra = DB::table('role_assignments as ra')->leftJoin('centres as c', 'c.id', '=', 'ra.centre_id')
                ->where('ra.user_id', $userId)->where('ra.active', 1)->get(['ra.role', 'ra.agency_id', 'c.agency_id as cag']);
            if ($ra->contains(fn ($r) => $r->role === 'platform_admin')) {
                return false;
            }
            $ids = $ra->map(fn ($r) => (int) ($r->agency_id ?: $r->cag))->filter()
                ->merge(DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')
                    ->join('centres as c', 'c.id', '=', 'f.centre_id')->where('g.user_id', $userId)
                    ->pluck('c.agency_id')->map(fn ($i) => (int) $i))
                ->unique()->values();

            return $ids->isNotEmpty() && $ids->every(fn ($i) => in_array($i, $test, true));
        });
    }

    /** An address whose every account is test-only (a shared address with one real account is real). */
    public static function isEmail(string $email): bool
    {
        $e = mb_strtolower(trim($email));
        if ($e === '' || ! self::ids()) {
            return false;
        }
        $ids = DB::table('users')->whereRaw('LOWER(email) = ?', [$e])->whereNull('deleted_at')->pluck('id');

        return $ids->isNotEmpty() && $ids->every(fn ($id) => self::isUser((int) $id));
    }

    /** The current web request is working in a test agency (not on a platform-level screen). */
    public static function isSession(): bool
    {
        try {
            if (app()->runningInConsole() || ! app()->bound('request')) {
                return false;
            }
            $req = request();
            if ($req->attributes->has('kt_test_agency')) {
                return (bool) $req->attributes->get('kt_test_agency');
            }
            $yes = false;
            $seg = (string) ($req->segment(3) ?? '');   // api / v1 / <prefix>
            if (! in_array($seg, self::PLATFORM_PREFIXES, true)) {
                $u = $req->user('sanctum');
                if ($u) {
                    $yes = self::isUser((int) $u->id) || self::isAgency(AuditScope::resolve((int) $u->id, $req));
                }
            }
            $req->attributes->set('kt_test_agency', $yes);

            return $yes;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * THE QUESTION A JOB ASKS PER SEND (2026-11-25, Anthony: "i am still receiving test agency safe arrival
     * notifications - i thought we turned this off?"). A console or queued job has no session, and someone in a test
     * AND a real agency (Anthony: guardian at #6, admin at #2) is not a test-only person, so the push services let #6's
     * "Has Liam arrived?" through to his phone. A sender that knows the agency it is sending for asks this; true = a
     * test agency: send nothing (noted in the log). Mail and texts also judge the agency themselves (X-KT-Agency-Id in
     * SuppressAgencyMail, SmsGateway); push has no agency, so the push senders MUST ask.
     */
    public static function skips(?int $agencyId, string $what, array $ctx = []): bool
    {
        if (! self::isAgency($agencyId)) {
            return false;
        }
        self::note($what, ['agency' => $agencyId] + $ctx);

        return true;
    }

    /** @var array<int,?int> */
    private static array $centreAgency = [];

    public static function agencyOfCentre(?int $centreId): ?int
    {
        if (! $centreId) {
            return null;
        }
        if (! array_key_exists($centreId, self::$centreAgency)) {
            $a = DB::table('centres')->where('id', $centreId)->value('agency_id');
            self::$centreAgency[$centreId] = $a ? (int) $a : null;
        }

        return self::$centreAgency[$centreId];
    }

    public static function agencyOfChild(?int $childId): ?int
    {
        if (! $childId) {
            return null;
        }
        $c = DB::table('children as ch')->join('families as f', 'f.id', '=', 'ch.family_id')
            ->where('ch.id', $childId)->value('f.centre_id');

        return self::agencyOfCentre($c ? (int) $c : null);
    }

    /** The one question every sender asks: is this send for a test agency (by agency, session or person)? */
    public static function blocks(?int $agencyId = null, ?int $userId = null): bool
    {
        return self::isAgency($agencyId) || self::isSession() || ($userId ? self::isUser($userId) : false);
    }

    /** Leave a trail: silence with no record is its own hazard. */
    public static function note(string $channel, array $ctx = []): void
    {
        try {
            Log::info('TEST AGENCY: '.$channel.' not sent', $ctx);
        } catch (\Throwable $e) {
        }
    }
}
