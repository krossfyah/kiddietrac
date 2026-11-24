<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Educator points (2026-10-20).
 *
 * Anthony: "add points system for educators to encourage them to use the features of the
 * app such as observations, daily logs, walks etc which adds up at the end of the month
 * which directors/admins can issue out a monetary bonus, pay out or giftcard".
 *
 * POINTS ARE WORKED OUT, NEVER STORED. Every month's total is computed from the records
 * the work itself left behind (an observation's recorded_by_id, a photo's uploaded_by_id,
 * a walk's staff_lead_id ...). Nothing has to be kept in sync, last month is already
 * there on day one, and deleting a record takes its points with it. Only AWARDS are
 * stored (educator_rewards), with the points as they stood when the award was made.
 *
 * A DAILY CAP PER ACTIVITY, in the agency's own days. Points reward using the app, not
 * tapping it: past the cap an activity still counts, it just stops adding points that day.
 *
 * Settings live in agencies.settings->educator_points (point values, caps, the optional
 * points-to-dollars rate that pre-fills a suggested award, and the leaderboard switch).
 */
final class EducatorPoints
{
    public const KEY = 'educator_points';

    /** People who earn points. Agency admins award them; they do not earn them. */
    public const EARNER_ROLES = ['educator', 'home_visitor', 'centre_director'];

    /** key => [label, icon, default points, default daily cap] */
    public const ACTIVITIES = [
        'observation' => ['Observations', '👀', 10, 10],
        'lesson_plan' => ['Lesson plans', '📚', 10, 3],
        'walk'        => ['Walks & outings', '🚶', 5, 4],
        'incident'    => ['Incident reports', '⚠️', 5, 5],
        'photo'       => ['Photos & videos shared', '📸', 3, 10],
        'daily_log'   => ['Daily logs', '✅', 1, 40],
    ];

    public const METHODS_ALLOWED = ['payroll_bonus', 'payout', 'gift_card_manual', 'gift_card_provider'];

    public const DEFAULTS = [
        'enabled' => true,
        'leaderboard' => true,
        'currency' => 'CAD',
        // Suggested award: rate_amount dollars per rate_points points. 0 = no suggestion.
        'rate_points' => 100,
        'rate_amount' => 0,
        // The Award dialog's preselected method (2026-11-02, Settings → Educator awards).
        'default_method' => 'payroll_bonus',
    ];

    public static function settings(int $agencyId): array
    {
        $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
        $mine = (isset($s[self::KEY]) && is_array($s[self::KEY])) ? $s[self::KEY] : [];
        $out = array_merge(self::DEFAULTS, array_intersect_key($mine, self::DEFAULTS));
        $out['points'] = [];
        $out['caps'] = [];
        foreach (self::ACTIVITIES as $k => $a) {
            $out['points'][$k] = isset($mine['points'][$k]) ? max(0, (int) $mine['points'][$k]) : $a[2];
            $out['caps'][$k] = isset($mine['caps'][$k]) ? max(0, (int) $mine['caps'][$k]) : $a[3];
        }
        $out['enabled'] = (bool) $out['enabled'];
        $out['leaderboard'] = (bool) $out['leaderboard'];
        $out['rate_points'] = max(1, (int) $out['rate_points']);
        $out['rate_amount'] = max(0, round((float) $out['rate_amount'], 2));
        $out['currency'] = strtoupper(substr((string) $out['currency'], 0, 3)) ?: 'CAD';
        $out['default_method'] = in_array($out['default_method'], self::METHODS_ALLOWED, true) ? $out['default_method'] : 'payroll_bonus';

        return $out;
    }

    public static function saveSettings(int $agencyId, array $in): array
    {
        $cur = self::settings($agencyId);
        $next = [
            'enabled' => array_key_exists('enabled', $in) ? (bool) $in['enabled'] : $cur['enabled'],
            'leaderboard' => array_key_exists('leaderboard', $in) ? (bool) $in['leaderboard'] : $cur['leaderboard'],
            'currency' => isset($in['currency']) && in_array(strtoupper((string) $in['currency']), ['CAD', 'USD'], true) ? strtoupper((string) $in['currency']) : $cur['currency'],
            'rate_points' => isset($in['rate_points']) ? max(1, min(100000, (int) $in['rate_points'])) : $cur['rate_points'],
            'rate_amount' => isset($in['rate_amount']) ? max(0, min(100000, round((float) $in['rate_amount'], 2))) : $cur['rate_amount'],
            'default_method' => isset($in['default_method']) && in_array($in['default_method'], self::METHODS_ALLOWED, true) ? $in['default_method'] : $cur['default_method'],
            'points' => [], 'caps' => [],
        ];
        foreach (self::ACTIVITIES as $k => $a) {
            $next['points'][$k] = isset($in['points'][$k]) ? max(0, min(1000, (int) $in['points'][$k])) : $cur['points'][$k];
            $next['caps'][$k] = isset($in['caps'][$k]) ? max(0, min(1000, (int) $in['caps'][$k])) : $cur['caps'][$k];
        }
        DB::transaction(function () use ($agencyId, $next) {
            $raw = DB::table('agencies')->where('id', $agencyId)->lockForUpdate()->value('settings');
            $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
            $s[self::KEY] = $next;
            DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($s), 'updated_at' => now()]);
        });

        return self::settings($agencyId);
    }

    /** 'YYYY-MM' (the agency's current month when blank or malformed). */
    public static function month(int $agencyId, ?string $month): string
    {
        if ($month && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            return $month;
        }

        return substr(AgencyTime::today($agencyId), 0, 7);
    }

    /** UTC [from, to) bounding an agency month. */
    public static function window(int $agencyId, string $month): array
    {
        $first = $month . '-01';
        $last = Carbon::parse($first)->endOfMonth()->toDateString();

        return AgencyTime::spanRange($agencyId, $first, $last);
    }

    /**
     * Everyone who earns points in this agency: user_id => [name, role, centre_ids].
     * $centreScope (a director's centres) limits it to people working at those centres.
     */
    public static function earners(int $agencyId, ?array $centreScope = null): array
    {
        $rows = DB::table('role_assignments as ra')
            ->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.agency_id', $agencyId)->where('ra.active', 1)
            ->whereIn('ra.role', self::EARNER_ROLES)
            ->whereNull('u.deleted_at')
            ->whereNotIn('u.status', Audience::OFF_STATUSES)
            ->get(['u.id', 'u.first_name', 'u.last_name', 'ra.role', 'ra.centre_id']);
        $order = array_flip(self::EARNER_ROLES);
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r->id;
            if (! isset($out[$id])) {
                $out[$id] = ['user_id' => $id, 'name' => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: 'Staff member',
                    'role' => $r->role, 'centre_ids' => []];
            }
            if (($order[$r->role] ?? 9) > ($order[$out[$id]['role']] ?? 9)) {
                $out[$id]['role'] = $r->role;   // the senior role names them (director over educator)
            }
            if ($r->centre_id) {
                $out[$id]['centre_ids'][] = (int) $r->centre_id;
            }
        }
        if ($centreScope !== null) {
            $out = array_filter($out, fn ($e) => (bool) array_intersect($e['centre_ids'], $centreScope));
        }

        return $out;
    }

    /**
     * The month's points: user_id => [user_id, name, role, centre_ids, total, by => [key => [count, counted, points]]].
     * Only the people in $earners are counted.
     */
    public static function compute(int $agencyId, string $month, array $earners): array
    {
        $cfg = self::settings($agencyId);
        $tz = AgencyTime::tz($agencyId);
        [$from, $to] = self::window($agencyId, $month);
        $ids = array_keys($earners);
        $res = [];
        foreach ($earners as $id => $e) {
            $res[$id] = $e + ['total' => 0, 'by' => [], 'days' => []];   // days: agency date => points (2026-10-28)
            foreach (self::ACTIVITIES as $k => $a) {
                $res[$id]['by'][$k] = ['count' => 0, 'counted' => 0, 'points' => 0];
            }
        }
        if (! $ids) {
            return $res;
        }

        // [user, utc timestamp] per activity; daily logs from both log tables.
        $hits = [];
        $add = function (string $k, $rows) use (&$hits) {
            foreach ($rows as $r) {
                $hits[$k][] = [(int) $r->u, (string) $r->t];
            }
        };
        $kids = fn ($q, $col) => $q->join('children as ch', 'ch.id', '=', $col)
            ->join('families as fa', 'fa.id', '=', 'ch.family_id')
            ->join('centres as ce', 'ce.id', '=', 'fa.centre_id')
            ->where('ce.agency_id', $agencyId);

        $add('observation', $kids(DB::table('observations as o'), 'o.child_id')
            ->whereIn('o.recorded_by_id', $ids)->where('o.created_at', '>=', $from)->where('o.created_at', '<', $to)
            ->get(['o.recorded_by_id as u', 'o.created_at as t']));
        $add('daily_log', $kids(DB::table('daily_care_logs as l'), 'l.child_id')
            ->whereIn('l.recorded_by_id', $ids)->where('l.created_at', '>=', $from)->where('l.created_at', '<', $to)
            ->get(['l.recorded_by_id as u', 'l.created_at as t']));
        /* daily_events: not walks (counted from the walk itself) and not the timeline copy
           of a shared photo (counted as the photo). A "whole room" bulk entry is ONE log. */
        $ev = $kids(DB::table('daily_events as e'), 'e.child_id')
            ->whereIn('e.recorded_by_id', $ids)->where('e.created_at', '>=', $from)->where('e.created_at', '<', $to)
            ->whereNull('e.deleted_at')->where('e.event_type', '!=', 'walk')->whereNull('e.photo_id')
            ->get(['e.recorded_by_id as u', 'e.created_at as t', 'e.bulk_log_id as b']);
        $seenBulk = [];
        $add('daily_log', $ev->filter(function ($r) use (&$seenBulk) {
            if (! $r->b) {
                return true;
            }
            $k = $r->u . ':' . $r->b;
            if (isset($seenBulk[$k])) {
                return false;
            }
            $seenBulk[$k] = 1;

            return true;
        }));
        $add('incident', $kids(DB::table('incidents as i'), 'i.child_id')
            ->whereIn('i.recorded_by_id', $ids)->where('i.created_at', '>=', $from)->where('i.created_at', '<', $to)
            ->get(['i.recorded_by_id as u', 'i.created_at as t']));
        $add('photo', DB::table('photos as p')->join('centres as ce', 'ce.id', '=', 'p.centre_id')
            ->where('ce.agency_id', $agencyId)
            ->whereIn('p.uploaded_by_id', $ids)->where('p.created_at', '>=', $from)->where('p.created_at', '<', $to)
            ->get(['p.uploaded_by_id as u', 'p.created_at as t']));
        $add('walk', DB::table('field_trips')->where('agency_id', $agencyId)
            ->whereIn('staff_lead_id', $ids)->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->get(['staff_lead_id as u', 'created_at as t']));
        $add('lesson_plan', DB::table('lesson_plans as lp')->join('centres as ce', 'ce.id', '=', 'lp.centre_id')
            ->where('ce.agency_id', $agencyId)
            ->whereIn('lp.created_by_id', $ids)->where('lp.created_at', '>=', $from)->where('lp.created_at', '<', $to)
            ->get(['lp.created_by_id as u', 'lp.created_at as t']));

        foreach ($hits as $k => $list) {
            $perDay = [];
            foreach ($list as [$u, $t]) {
                if (! isset($res[$u])) {
                    continue;
                }
                $day = Carbon::parse($t, 'UTC')->setTimezone($tz)->toDateString();
                $n = ($perDay[$u][$day] ?? 0) + 1;
                $perDay[$u][$day] = $n;
                $res[$u]['by'][$k]['count']++;
                $cap = $cfg['caps'][$k];
                if ($cap === 0 || $n <= $cap) {
                    $res[$u]['by'][$k]['counted']++;
                    // The same counted hit, on its own day: the days add up to the month's total exactly.
                    $res[$u]['days'][$day] = ($res[$u]['days'][$day] ?? 0) + $cfg['points'][$k];
                }
            }
        }
        foreach ($res as $u => &$r) {
            $total = 0;
            foreach ($r['by'] as $k => &$b) {
                $b['points'] = $b['counted'] * $cfg['points'][$k];
                $total += $b['points'];
            }
            unset($b);
            $r['total'] = $total;
        }
        unset($r);

        return $res;
    }

    /** Suggested award for these points, or null when no rate is set. */
    public static function suggest(array $cfg, int $points): ?float
    {
        if ($cfg['rate_amount'] <= 0 || $points <= 0) {
            return null;
        }

        return round($points / $cfg['rate_points'] * $cfg['rate_amount'], 2);
    }

    public static function activityList(array $cfg): array
    {
        $out = [];
        foreach (self::ACTIVITIES as $k => $a) {
            $out[] = ['key' => $k, 'label' => $a[0], 'icon' => $a[1], 'points' => $cfg['points'][$k], 'cap' => $cfg['caps'][$k]];
        }

        return $out;
    }
}
