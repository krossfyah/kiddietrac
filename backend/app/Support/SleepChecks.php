<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SLEEP SUPERVISION CHECKS — the rules in one place (2026-10-13).
 *
 * Ontario, O. Reg. 137/15 s.33.1 and Licensing Manual Part 5: infants under 12 months are
 * placed on their back unless a physician directs otherwise in writing, and every sleeping
 * child under 24 months gets documented DIRECT VISUAL checks at the frequency the centre's
 * own sleep policy sets (a monitor does not replace them). The regulation does not name a
 * number of minutes, so the interval is the centre's policy, configured here.
 *
 * WHAT "ASLEEP" MEANS. There is no second nap record: a child is asleep from a daily_events
 * nap_start (Today roster "Nap down", the nap quick-log, the Sleep room) until a nap_end at or
 * after it. A check belongs to that nap_start row (sleep_checks.nap_event_id). A nap is also
 * over when the child is signed out after it started, or after OPEN_HOURS, so a forgotten
 * "woke up" cannot alert anyone all evening.
 *
 * A nap ENTERED IN ADVANCE (created more than an hour before the time it names) is
 * a plan or a seeded demo row, not somebody putting a child down, and is not tracked. The
 * Test Agency demo generator writes the day's naps at 05:00 UTC; without this rule it
 * would page real people in that agency every afternoon.
 *
 * Settings: agencies.settings.sleep_checks = {enabled, infant_minutes, toddler_minutes,
 * alert_director, director_grace_minutes, centres: {id: {enabled, infant_minutes,
 * toddler_minutes}}}. enabled unset = ON for Ontario (CA-ON), OFF elsewhere.
 */
final class SleepChecks
{
    public const DEFAULT_INFANT_MINUTES = 15;   // under 12 months
    public const DEFAULT_TODDLER_MINUTES = 15;  // 12 to 24 months
    public const DEFAULT_DIRECTOR_GRACE = 5;
    public const MIN_MINUTES = 5;
    public const MAX_MINUTES = 60;
    public const OPEN_HOURS = 6;
    /** A nap_start entered more than this long BEFORE the time it names is a plan / seeded row. */
    public const ADVANCE_MINUTES = 60;
    public const POSITIONS = ['back' => 'Back', 'side' => 'Side', 'tummy' => 'Tummy', 'sitting' => 'Sitting up', 'other' => 'Other'];

    /** The agency's raw sleep_checks settings block. */
    public static function raw(int $agencyId): array
    {
        $s = json_decode((string) DB::table('agencies')->where('id', $agencyId)->value('settings'), true);

        return is_array($s['sleep_checks'] ?? null) ? $s['sleep_checks'] : [];
    }

    public static function defaultEnabled(int $agencyId): bool
    {
        return Jurisdictions::codeFor($agencyId) === 'CA-ON';
    }

    /** The agency-level settings with defaults filled in. */
    public static function agency(int $agencyId, ?array $raw = null): array
    {
        $r = $raw ?? self::raw($agencyId);

        return [
            'enabled' => array_key_exists('enabled', $r) && $r['enabled'] !== null ? (bool) $r['enabled'] : self::defaultEnabled($agencyId),
            'infant_minutes' => self::clamp($r['infant_minutes'] ?? self::DEFAULT_INFANT_MINUTES),
            'toddler_minutes' => self::clamp($r['toddler_minutes'] ?? self::DEFAULT_TODDLER_MINUTES),
            'alert_director' => array_key_exists('alert_director', $r) ? (bool) $r['alert_director'] : true,
            'director_grace_minutes' => max(0, min(60, (int) ($r['director_grace_minutes'] ?? self::DEFAULT_DIRECTOR_GRACE))),
        ];
    }

    /** What applies at one centre: the agency default, with that centre's own policy on top. */
    public static function forCentre(int $agencyId, ?int $centreId, ?array $raw = null): array
    {
        $r = $raw ?? self::raw($agencyId);
        $cfg = self::agency($agencyId, $r);
        $o = $centreId ? ($r['centres'][(string) $centreId] ?? $r['centres'][$centreId] ?? null) : null;
        $cfg['centre_override'] = is_array($o);
        if (is_array($o)) {
            if (array_key_exists('enabled', $o) && $o['enabled'] !== null) $cfg['enabled'] = (bool) $o['enabled'];
            if (! empty($o['infant_minutes'])) $cfg['infant_minutes'] = self::clamp($o['infant_minutes']);
            if (! empty($o['toddler_minutes'])) $cfg['toddler_minutes'] = self::clamp($o['toddler_minutes']);
        }

        return $cfg;
    }

    public static function clamp($v): int
    {
        return max(self::MIN_MINUTES, min(self::MAX_MINUTES, (int) $v ?: self::DEFAULT_INFANT_MINUTES));
    }

    /** Whole months of age on a date (calendar months, the way a parent counts them). */
    public static function ageMonths(?string $dob, $at): ?int
    {
        if (! $dob) return null;
        $b = Carbon::parse(substr($dob, 0, 10));
        $a = $at instanceof \DateTimeInterface ? Carbon::instance($at) : Carbon::parse((string) $at);
        $m = ($a->year - $b->year) * 12 + ($a->month - $b->month) - ($a->day < $b->day ? 1 : 0);

        return max(0, $m);
    }

    public static function intervalFor(array $cfg, ?int $months): ?int
    {
        if ($months === null || $months >= 24) return null;

        return $months < 12 ? $cfg['infant_minutes'] : $cfg['toddler_minutes'];
    }

    /**
     * Open naps for children under 24 months, with their check state.
     *
     * @param  int[]|null  $roomIds  null = every room (the scheduler), [] = nothing
     * @return array<int, array> keyed by nap_event_id
     */
    public static function openNaps(?array $roomIds, ?Carbon $now = null): array
    {
        if ($roomIds !== null && ! $roomIds) return [];
        $now = $now ? $now->copy()->utc() : Carbon::now('UTC');
        $nowS = $now->format('Y-m-d H:i:s');

        $q = DB::table('daily_events as e')
            ->join('children as c', 'c.id', '=', 'e.child_id')
            ->join('rooms as r', 'r.id', '=', 'e.room_id')
            ->join('centres as ce', 'ce.id', '=', 'r.centre_id')
            ->where('e.event_type', 'nap_start')
            ->whereNull('e.deleted_at')
            ->where('e.occurred_at', '>=', $now->copy()->subHours(self::OPEN_HOURS)->format('Y-m-d H:i:s'))
            ->where('e.occurred_at', '<=', $nowS)
            // Entered in advance = a plan or a seeded demo row, not a child being put down.
            ->whereRaw('NOT (e.created_at < e.occurred_at - INTERVAL ' . self::ADVANCE_MINUTES . ' MINUTE)')
            ->whereNotNull('c.date_of_birth')
            ->where('c.date_of_birth', '>', $now->copy()->subMonths(24)->subDay()->toDateString())
            /* Only what has HAPPENED closes a nap: a wake-up or sign-out entered in advance
               (the Test Agency generator writes the whole day at 05:00) is not one yet. */
            ->whereNotExists(function ($w) use ($nowS) {
                $w->from('daily_events as x')->whereColumn('x.child_id', 'e.child_id')
                    ->whereIn('x.event_type', ['nap_end', 'nap_start'])->whereNull('x.deleted_at')->where('x.occurred_at', '<=', $nowS)
                    ->where(function ($z) {
                        $z->where(fn ($a) => $a->where('x.event_type', 'nap_end')->whereColumn('x.occurred_at', '>=', 'e.occurred_at'))
                          ->orWhere(fn ($a) => $a->where('x.event_type', 'nap_start')->where(fn ($b) => $b->whereColumn('x.occurred_at', '>', 'e.occurred_at')
                              ->orWhere(fn ($c) => $c->whereColumn('x.occurred_at', 'e.occurred_at')->whereColumn('x.id', '>', 'e.id'))));
                    });
            })
            ->whereNotExists(function ($w) use ($nowS) {
                $w->from('check_events as k')->whereColumn('k.child_id', 'e.child_id')->where('k.occurred_at', '<=', $nowS)
                    ->where('k.event_type', 'check_out')->whereColumn('k.occurred_at', '>', 'e.occurred_at');
            });
        Live::enrolledChildren($q, 'c');
        Live::centres($q, 'ce');
        if ($roomIds !== null) $q->whereIn('e.room_id', $roomIds);

        $rows = $q->get([
            'e.id as nap_event_id', 'e.child_id', 'e.room_id', 'e.occurred_at', 'e.created_at', 'e.recorded_by_id',
            'r.name as room_name', 'r.centre_id', 'ce.agency_id', 'ce.name as centre_name',
            'c.first_name', 'c.last_name', 'c.preferred_name', 'c.date_of_birth', 'c.photo_url',
            'c.sleep_position_direction', 'c.sleep_position_direction_note',
        ]);
        if ($rows->isEmpty()) return [];

        $ids = $rows->pluck('nap_event_id')->all();
        $checks = DB::table('sleep_checks as s')->leftJoin('users as u', 'u.id', '=', 's.checked_by')
            ->whereIn('s.nap_event_id', $ids)->orderBy('s.checked_at')
            ->get(['s.nap_event_id', 's.checked_at', 's.position', 's.breathing_ok', 's.colour_ok', 's.note', 'u.first_name', 'u.last_name'])
            ->groupBy('nap_event_id');

        $rawCache = [];
        $out = [];
        foreach ($rows as $r) {
            $agencyId = (int) $r->agency_id;
            $rawCache[$agencyId] ??= self::raw($agencyId);
            $cfg = self::forCentre($agencyId, (int) $r->centre_id, $rawCache[$agencyId]);
            $tz = AgencyTime::tz($agencyId);
            $months = self::ageMonths($r->date_of_birth, Carbon::now($tz));
            $interval = self::intervalFor($cfg, $months);
            if ($interval === null) continue;

            $list = $checks->get($r->nap_event_id, collect());
            $last = $list->last();
            $start = Carbon::parse($r->occurred_at, 'UTC');
            $logged = Carbon::parse($r->created_at, 'UTC');
            /* The clock starts at the last check, else when the nap was entered or began,
               whichever is later — alerting about a check that was due before anyone told
               the system the child was asleep would only be noise. The REPORT measures from
               the real start, so a late entry still shows as a gap there. */
            $anchor = $last ? Carbon::parse($last->checked_at, 'UTC') : ($logged->gt($start) ? $logged : $start);
            $due = $anchor->copy()->addMinutes($interval);

            $out[(int) $r->nap_event_id] = [
                'nap_event_id' => (int) $r->nap_event_id,
                'child_id' => (int) $r->child_id,
                'room_id' => (int) $r->room_id,
                'room_name' => $r->room_name,
                'centre_id' => (int) $r->centre_id,
                'centre_name' => $r->centre_name,
                'agency_id' => $agencyId,
                'enabled' => $cfg['enabled'],
                'cfg' => $cfg,
                'tz' => $tz,
                'name' => trim(($r->preferred_name ?: $r->first_name) . ' ' . $r->last_name),
                'first_name' => $r->preferred_name ?: $r->first_name,
                'photo_url' => $r->photo_url,
                'date_of_birth' => $r->date_of_birth,
                'age_months' => $months,
                'under12' => $months !== null && $months < 12,
                'physician_direction' => (bool) $r->sleep_position_direction,
                'physician_note' => $r->sleep_position_direction_note,
                'interval' => $interval,
                'since' => $start->format('Y-m-d\TH:i:s\Z'),
                'since_local' => $start->copy()->setTimezone($tz)->format('g:i A'),
                'checks_count' => $list->count(),
                'last_check' => $last ? [
                    'at' => Carbon::parse($last->checked_at, 'UTC')->format('Y-m-d\TH:i:s\Z'),
                    'at_local' => AgencyTime::fmt($last->checked_at, $tz),
                    'by' => trim(($last->first_name ?? '') . ' ' . ($last->last_name ?? '')),
                    'position' => $last->position,
                    'breathing_ok' => (bool) $last->breathing_ok,
                    'colour_ok' => (bool) $last->colour_ok,
                ] : null,
                'due_at' => $due->format('Y-m-d\TH:i:s\Z'),
                'due_local' => $due->copy()->setTimezone($tz)->format('g:i A'),
                'due' => $due,
                'overdue' => $now->gt($due),
            ];
        }

        return $out;
    }

    /** Is an open nap still open right now? Returns the nap_start row or null. */
    public static function openNap(int $napEventId): ?array
    {
        $roomId = DB::table('daily_events')->where('id', $napEventId)->where('event_type', 'nap_start')->whereNull('deleted_at')->value('room_id');
        if (! $roomId) return null;

        return self::openNaps([(int) $roomId])[$napEventId] ?? null;
    }
}
