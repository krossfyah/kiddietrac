<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CALENDAR SYNC (2026-11-03, Anthony: "calendar sync for parents" — Brightwheel's "personal
 * calendar sync"). Every guardian gets a private subscription link (webcal:// + https .ics) that
 * Google, Apple and Outlook poll, so the centre's closures, events, trips and the child's own
 * absences sit in the family's own calendar and stay up to date. Educators get the same for their
 * centre's closures and events plus their own shifts and time off.
 *
 * THE LINK IS THE KEY. Calendar apps cannot sign in, so whoever has the URL can read the feed.
 * The token is 256 bits, derived as HMAC(APP_KEY, random salt); the database keeps only the salt
 * and sha256(token). A database copy alone therefore cannot produce a working link, and the app can
 * still show the person their own link again. "Reset link" draws a new salt: the old URL stops at
 * once (404), everywhere it was pasted.
 *
 * SCOPE IS DECIDED HERE AND NOWHERE ELSE (P0, [[kiddietrac-data-leakage-rule]],
 * [[kiddietrac-person-vs-tenant-boundary]]). A parent's feed is built from THEIR guardian rows →
 * families that have not left → ENROLLED children → those children's open enrolments and family
 * centre, open centres only (App\Support\Live). Every event is read by those ids; nothing is ever
 * read "for the agency". Absences, holds and conferences are the person's own children only.
 * Each agency's events are written in that agency's own zone, and an agency that turned calendar
 * sync off (settings.calendar.sync_enabled) or whose licence lapsed contributes nothing.
 *
 * TEXT. Feeds end up in shared family calendars, so nothing we write names a member of staff by
 * surname (home child care centres are NAMED after the provider: "Bruni Meeser" → "Bruni") or any
 * other family's child. Staff feeds name no child at all.
 */
final class CalendarFeed
{
    public const KINDS = ['parent', 'staff'];

    /** What each kind of feed can carry, and the default for a new link. Schedule is opt-in. */
    public const OPTIONS = [
        'parent' => ['closures' => true, 'events' => true, 'absences' => true, 'schedule' => false],
        'staff' => ['closures' => true, 'events' => true, 'shifts' => true, 'timeoff' => true],
    ];

    public const BACK_DAYS = 30;
    public const AHEAD_MONTHS = 12;
    public const FEED_BASE = 'https://api.kiddietrac.com/api/v1/cal/';
    public const APP = 'https://app.kiddietrac.com/dashboard.html';

    private const STAFF_ROLES = ['educator', 'centre_director', 'home_visitor', 'agency_admin'];

    /* ── The link ───────────────────────────────────────────────────────────────── */

    public static function tokenFor(string $salt): string
    {
        $raw = hash_hmac('sha256', 'kt-cal-feed|' . $salt, (string) config('app.key'), true);

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');   // 43 characters
    }

    public static function hashOf(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function urls(string $token): array
    {
        $https = self::FEED_BASE . $token . '.ics';
        $webcal = 'webcal://' . substr($https, strlen('https://'));

        return [
            'https' => $https,
            'webcal' => $webcal,
            // Google: "Add by URL" needs the https form; the cid link opens the add dialog.
            'google' => 'https://calendar.google.com/calendar/r?cid=' . rawurlencode($webcal),
            'outlook' => 'https://outlook.live.com/calendar/0/addfromweb?url=' . rawurlencode($https) . '&name=' . rawurlencode('KiddieTrac'),
        ];
    }

    /** The person's feed of this kind, made on first use. */
    public static function ensure(int $userId, string $kind): object
    {
        $row = DB::table('calendar_feeds')->where('user_id', $userId)->where('kind', $kind)->first();
        if ($row) {
            return $row;
        }
        $salt = bin2hex(random_bytes(32));
        try {
            DB::table('calendar_feeds')->insert([
                'user_id' => $userId, 'kind' => $kind, 'salt' => $salt,
                'token_hash' => self::hashOf(self::tokenFor($salt)),
                'options' => json_encode(self::OPTIONS[$kind]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Two tabs opening Settings at once: the unique (user_id, kind) key lets one win.
        }

        return DB::table('calendar_feeds')->where('user_id', $userId)->where('kind', $kind)->first();
    }

    /** New salt = new link; the old one is dead the moment this commits. */
    public static function reset(object $feed): object
    {
        $salt = bin2hex(random_bytes(32));
        DB::table('calendar_feeds')->where('id', $feed->id)->update([
            'salt' => $salt, 'token_hash' => self::hashOf(self::tokenFor($salt)),
            'reset_at' => now(), 'updated_at' => now(), 'last_polled_at' => null, 'poll_count' => 0,
        ]);

        return DB::table('calendar_feeds')->where('id', $feed->id)->first();
    }

    public static function options(object $feed): array
    {
        $o = json_decode((string) $feed->options, true);
        $o = is_array($o) ? $o : [];
        $out = [];
        foreach (self::OPTIONS[$feed->kind] ?? [] as $k => $def) {
            $out[$k] = array_key_exists($k, $o) ? (bool) $o[$k] : $def;
        }

        return $out;
    }

    /* ── Who may have which feed ────────────────────────────────────────────────── */

    /** Agency switch, default ON (Settings → Calendar). */
    public static function agencyEnabled(int $agencyId): bool
    {
        $a = DB::table('agencies')->where('id', $agencyId)->first(['id', 'deleted_at', 'settings']);
        if (! $a || $a->deleted_at) {
            return false;
        }
        $s = json_decode((string) $a->settings, true);
        if (is_array($s) && isset($s['calendar']['sync_enabled']) && ! $s['calendar']['sync_enabled']) {
            return false;
        }
        try {
            if (Licences::lapsed($agencyId)) {
                return false;
            }
        } catch (\Throwable $e) {
        }

        return true;
    }

    /**
     * A parent's scope: their own live families, enrolled children, those children's open centres
     * and rooms, and the agencies (sync on) those belong to. Empty arrays mean NOTHING.
     */
    public static function parentScope(int $userId): array
    {
        $none = ['families' => [], 'children' => [], 'centres' => [], 'rooms' => [], 'agencies' => [], 'centre_agency' => []];
        if (PickupLogin::isPickupOnly($userId)) {
            return $none;   // a pick-up login sees who may collect, not the family's calendar
        }
        $families = Live::families(DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id'), 'f')
            ->where('g.user_id', $userId)
            ->get(['f.id', 'f.centre_id']);
        if ($families->isEmpty()) {
            return $none;
        }
        $famIds = $families->pluck('id')->map(fn ($v) => (int) $v)->unique()->values()->all();

        $children = Live::enrolledChildren(DB::table('children as c'), 'c')
            ->whereIn('c.family_id', $famIds)
            ->get(['c.id', 'c.family_id', 'c.first_name', 'c.preferred_name', 'c.expected_dropoff_time', 'c.expected_pickup_time']);
        if ($children->isEmpty()) {
            return $none;
        }
        $childIds = $children->pluck('id')->map(fn ($v) => (int) $v)->all();

        // Where the children are: open enrolments (agency "today" per centre is close enough for
        // an end date; a child who left yesterday still drops off the feed by tomorrow).
        $enrol = Live::enrolments(DB::table('enrollments as e'), 'e', Carbon::now('America/Toronto')->subDay()->toDateString())
            ->join('rooms as r', 'r.id', '=', 'e.room_id')
            ->whereIn('e.child_id', $childIds)
            ->get(['e.child_id', 'e.room_id', 'r.centre_id']);

        $liveFams = $children->pluck('family_id')->map(fn ($v) => (int) $v)->unique()->all();
        $centreIds = array_merge(
            $enrol->pluck('centre_id')->all(),
            $families->whereIn('id', $liveFams)->pluck('centre_id')->all()
        );
        $centres = Live::centres(DB::table('centres'))
            ->whereIn('id', array_values(array_unique(array_map('intval', array_filter($centreIds)))) ?: [0])
            ->get(['id', 'agency_id']);

        $centreAgency = [];
        $okAgency = [];
        foreach ($centres as $c) {
            $aid = (int) $c->agency_id;
            $okAgency[$aid] ??= self::agencyEnabled($aid);
            if ($okAgency[$aid]) {
                $centreAgency[(int) $c->id] = $aid;
            }
        }
        if (! $centreAgency) {
            return $none;
        }

        // A child counts only if one of their centres is in scope.
        $childCentres = [];
        foreach ($enrol as $e) {
            if (isset($centreAgency[(int) $e->centre_id])) {
                $childCentres[(int) $e->child_id][(int) $e->centre_id] = true;
            }
        }
        $famCentre = $families->pluck('centre_id', 'id')->all();
        $keptChildren = $children->filter(function ($c) use ($childCentres, $famCentre, $centreAgency) {
            return isset($childCentres[(int) $c->id]) || isset($centreAgency[(int) ($famCentre[$c->family_id] ?? 0)]);
        })->values();
        if ($keptChildren->isEmpty()) {
            return $none;
        }
        $rooms = $enrol->filter(fn ($e) => isset($centreAgency[(int) $e->centre_id]))->pluck('room_id')
            ->map(fn ($v) => (int) $v)->unique()->values()->all();

        return [
            'families' => $keptChildren->pluck('family_id')->map(fn ($v) => (int) $v)->unique()->values()->all(),
            'children' => $keptChildren->all(),
            'centres' => array_keys($centreAgency),
            'rooms' => $rooms,
            'agencies' => array_values(array_unique(array_values($centreAgency))),
            'centre_agency' => $centreAgency,
        ];
    }

    /** A staff member's scope: the centres on their own role rows (and rooms), open ones only. */
    public static function staffScope(int $userId): array
    {
        $none = ['centres' => [], 'rooms' => [], 'agencies' => [], 'centre_agency' => [], 'admin_agencies' => []];
        $rows = DB::table('role_assignments')->where('user_id', $userId)->where('active', 1)
            ->whereIn('role', self::STAFF_ROLES)->get(['role', 'agency_id', 'centre_id']);
        if ($rows->isEmpty()) {
            return $none;
        }
        $centreIds = [];
        $adminAgencies = [];
        foreach ($rows as $r) {
            if ($r->centre_id) {
                $centreIds[] = (int) $r->centre_id;
            } elseif ($r->role === 'agency_admin' && $r->agency_id) {
                $adminAgencies[] = (int) $r->agency_id;
                $centreIds = array_merge($centreIds, Live::centreIds((int) $r->agency_id));
            }
        }
        $centreIds = array_merge($centreIds, DB::table('educator_rooms as er')->join('rooms as r', 'r.id', '=', 'er.room_id')
            ->where('er.user_id', $userId)->pluck('r.centre_id')->map(fn ($v) => (int) $v)->all());
        $centres = Live::centres(DB::table('centres'))->whereIn('id', array_values(array_unique($centreIds)) ?: [0])->get(['id', 'agency_id']);
        $centreAgency = [];
        $okAgency = [];
        foreach ($centres as $c) {
            $aid = (int) $c->agency_id;
            // The person must still hold a staff role IN that agency (a centre row can outlive it).
            $okAgency[$aid] ??= self::agencyEnabled($aid) && $rows->contains(fn ($r) => (int) $r->agency_id === $aid
                || ($r->centre_id && (int) DB::table('centres')->where('id', $r->centre_id)->value('agency_id') === $aid));
            if ($okAgency[$aid]) {
                $centreAgency[(int) $c->id] = $aid;
            }
        }
        if (! $centreAgency) {
            return $none;
        }

        return [
            'centres' => array_keys($centreAgency),
            'rooms' => DB::table('rooms')->whereIn('centre_id', array_keys($centreAgency))->pluck('id')->map(fn ($v) => (int) $v)->all(),
            'agencies' => array_values(array_unique(array_values($centreAgency))),
            'centre_agency' => $centreAgency,
            'admin_agencies' => array_values(array_unique($adminAgencies)),
        ];
    }

    /** Which kinds this person can have right now (for the Settings card). */
    public static function kindsFor(int $userId): array
    {
        $k = [];
        if (self::parentScope($userId)['children']) {
            $k[] = 'parent';
        }
        if (self::staffScope($userId)['centres']) {
            $k[] = 'staff';
        }

        return $k;
    }

    /* ── Building the calendar ──────────────────────────────────────────────────── */

    /** @return array{0: string, 1: int} the .ics text and the number of events */
    public static function build(object $feed, ?Carbon $now = null): array
    {
        $now = $now ?: Carbon::now('UTC');
        $opts = self::options($feed);
        $uid = (int) $feed->user_id;
        $user = DB::table('users')->where('id', $uid)->first(['id', 'email', 'status', 'deleted_at']);
        $live = $user && ! $user->deleted_at && ! in_array((string) $user->status, ['suspended', 'deactivated', 'disabled', 'deleted', 'closed'], true);

        $scope = ! $live ? ['centres' => [], 'agencies' => [], 'centre_agency' => []]
            : ($feed->kind === 'staff' ? self::staffScope($uid) : self::parentScope($uid));

        $b = new CalendarFeedBuilder($now, $scope, (string) $feed->kind);
        if ($scope['centres']) {
            if ($feed->kind === 'staff') {
                if ($opts['closures']) $b->closures();
                if ($opts['events']) { $b->announcements(['all', 'educators']); $b->fieldTrips(null); $b->staffConferences($uid); }
                if ($opts['shifts']) $b->shifts($uid);
                if ($opts['timeoff']) $b->timeOff($uid);
            } else {
                if ($opts['closures']) $b->closures();
                if ($opts['events']) { $b->announcements(['parents', 'all']); $b->fieldTrips(array_map(fn ($c) => (int) $c->id, $scope['children'])); $b->rsvps($uid, (string) $user->email); $b->parentConferences($uid); }
                if ($opts['absences']) { $b->absences(); $b->vacationHolds(); }
                if ($opts['schedule']) $b->schedule();
            }
        }

        return [$b->render(), $b->count()];
    }
}
