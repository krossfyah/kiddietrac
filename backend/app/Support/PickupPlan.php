<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * PICKUP COUNTDOWN + ALARM (2026-11-09, Anthony: "in the mobile app for parents add a set an alarm to pick up kids
 * feature with a count down clock and use the checkin/clocking in time as the starting point and match the scheduled
 * time to pick up child - allow user to add or decrease as optional").
 *
 * The countdown runs from the child's check-in (the visit they are in now, SafeDeparture::today) to their pickup time:
 * children.expected_pickup_time, else the centre's closing time, else the Safe Arrival default - the same time the
 * end-of-day check uses. A "we're running late" pickup time the parent gave the centre moves it later.
 *
 * Each parent can move their own countdown earlier or later for TODAY (adjust_minutes + adjust_date; it is back to the
 * usual time tomorrow) and switch on an alarm that rings their phone lead_minutes before (pickup_alarms, one row per
 * parent + child; parents:pickup-alarms sends it). Nothing here changes what the centre expects: it is the parent's
 * own reminder.
 *
 * Only the child's own guardians (guardians.user_id): a person check, not an agency one.
 */
final class PickupPlan
{
    /** Alarm lead times a parent can choose, in minutes before pickup. */
    public const LEADS = [0, 5, 10, 15, 20, 30, 45, 60];
    public const DEFAULT_LEAD = 15;
    /** The furthest a parent can move today's pickup either way, in minutes. */
    public const ADJUST_MAX = 180;

    /** The children this person is a guardian of (open families, open centres). */
    public static function children(int $userId, ?int $childId = null)
    {
        return DB::table('guardians as g')
            ->join('children as c', 'c.family_id', '=', 'g.family_id')
            ->join('families as fa', 'fa.id', '=', 'c.family_id')
            ->join('centres as ce', 'ce.id', '=', 'fa.centre_id')
            ->tap(fn ($q) => Live::centres(Live::families($q, 'fa'), 'ce'))
            ->where('g.user_id', $userId)
            ->whereNull('c.deleted_at')
            ->when($childId !== null, fn ($q) => $q->where('c.id', $childId))
            ->orderBy('c.first_name')
            ->distinct()
            ->get(['c.id', 'c.first_name', 'c.preferred_name', 'ce.id as centre_id', 'ce.name as centre_name', 'ce.agency_id']);
    }

    /**
     * One child's plan today for one parent. $dep is the child's SafeDeparture::today row (pass it when a caller
     * already has the agency's list); $setting is their pickup_alarms row (null = never set).
     */
    public static function build(object $k, ?array $dep, ?object $setting, ?Carbon $now = null): array
    {
        $agencyId = (int) $k->agency_id;
        $tz = AgencyTime::tz($agencyId);
        $date = AgencyTime::today($agencyId);
        $now = $now ?: Carbon::now();
        $lead = $setting ? (int) $setting->lead_minutes : self::DEFAULT_LEAD;
        $adjust = $setting && (string) $setting->adjust_date === $date ? (int) $setting->adjust_minutes : 0;
        $out = [
            'child_id' => (int) $k->id,
            'first_name' => $k->preferred_name ?: $k->first_name,
            'centre_name' => $k->centre_name,
            'status' => 'out',
            'alarm' => ['on' => $setting ? (bool) $setting->alarm_on : false, 'lead_minutes' => $lead],
            'leads' => self::LEADS,
            'adjust_minutes' => $adjust,
            'adjust_max' => self::ADJUST_MAX,
        ];
        if (! $dep || empty($dep['still_in'])) {
            return $out;
        }

        $usual = (string) $dep['expected'];
        $source = (string) $dep['expected_source'];
        if (! empty($dep['late']['by']) && $dep['late']['by'] > $usual) {
            $usual = (string) $dep['late']['by'];
            $source = 'late';
        }
        $usualAt = Carbon::parse($date . ' ' . $usual, $tz);
        $pickupAt = $usualAt->copy()->addMinutes($adjust);
        $inAt = ! empty($dep['checked_in_at']) ? Carbon::parse((string) $dep['checked_in_at'])->setTimezone($tz) : null;
        // Checked in after the usual pickup (an evening visit): there is no countdown to show from then.
        if ($inAt && $inAt->gte($pickupAt)) {
            $inAt = null;
        }

        return array_merge($out, [
            'status' => 'in',
            'checked_in_at' => $inAt ? $inAt->toIso8601String() : null,
            'checked_in_label' => $inAt ? $inAt->format('g:i A') : null,
            'usual_label' => $usualAt->format('g:i A'),
            'source' => $source,
            'pickup_at' => $pickupAt->toIso8601String(),
            'pickup_label' => $pickupAt->format('g:i A'),
            'alarm_at_label' => $pickupAt->copy()->subMinutes($lead)->format('g:i A'),
            'server_now' => $now->toIso8601String(),
        ]);
    }

    /** Every child of this parent, with one SafeDeparture list per agency. */
    public static function forUser(int $userId, ?int $childId = null): array
    {
        $kids = self::children($userId, $childId);
        $settings = DB::table('pickup_alarms')->where('user_id', $userId)->whereIn('child_id', $kids->pluck('id')->all() ?: [0])
            ->get()->keyBy('child_id');
        $lists = [];
        $out = [];
        foreach ($kids as $k) {
            $a = (int) $k->agency_id;
            $lists[$a] ??= SafeDeparture::today($a);
            $out[] = self::build($k, $lists[$a][(int) $k->id] ?? null, $settings[(int) $k->id] ?? null);
        }

        return $out;
    }

    /** "Pick up at 5:30 PM, in 15 minutes" - the alarm's line. */
    public static function inWords(int $minutes): string
    {
        if ($minutes <= 0) {
            return 'now';
        }
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return 'in ' . trim(($h ? $h . ' hour' . ($h > 1 ? 's' : '') : '') . ($h && $m ? ' ' : '') . ($m ? $m . ' minute' . ($m > 1 ? 's' : '') : ''));
    }
}
