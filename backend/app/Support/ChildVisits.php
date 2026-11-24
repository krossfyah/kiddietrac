<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;

/**
 * A child's day as VISITS (2026-11-05).
 *
 * A child can be signed in and out several times in one day (an appointment, a
 * split day, a parent who comes back). Code that took the first check_in and the
 * last check_out drew one long visit over the gap, and code that asked "is there a
 * check_out today?" called a child who came BACK still out. Everything that shows
 * or counts a day's attendance pairs the events here instead:
 *
 *   in → out → in → out   =  two visits
 *   in → in               =  one visit (the second tap is a duplicate)
 *   out with no in        =  a visit with no in (shown, never guessed)
 *
 * Whether the child is here NOW is the LAST in/out event, never "any check_out".
 */
final class ChildVisits
{
    /**
     * @param  iterable  $events  rows with ->event_type and ->occurred_at (UTC), oldest first
     * @return array<int,array{in:?object,out:?object}>
     */
    public static function pair(iterable $events): array
    {
        $visits = [];
        $open = null;
        foreach ($events as $e) {
            $type = is_array($e) ? ($e['event_type'] ?? null) : ($e->event_type ?? null);
            $e = is_array($e) ? (object) $e : $e;
            if ($type === 'check_in') {
                if ($open === null) $open = count($visits);
                if (! isset($visits[$open])) $visits[$open] = ['in' => $e, 'out' => null];
            } elseif ($type === 'check_out') {
                if ($open !== null) { $visits[$open]['out'] = $e; $open = null; }
                else $visits[] = ['in' => null, 'out' => $e];
            }
        }

        return array_values($visits);
    }

    /** Signed in right now: the last in/out event was a check_in. */
    public static function hereNow(iterable $events): bool
    {
        $last = null;
        foreach ($events as $e) {
            $type = is_array($e) ? ($e['event_type'] ?? null) : ($e->event_type ?? null);
            if ($type === 'check_in' || $type === 'check_out') $last = $type;
        }

        return $last === 'check_in';
    }

    /** First arrival of the day (null if never signed in). */
    public static function firstIn(array $visits): ?object
    {
        foreach ($visits as $v) if ($v['in']) return $v['in'];

        return null;
    }

    /** The last sign-out, only when the child is not back in after it. */
    public static function finalOut(array $visits): ?object
    {
        $last = end($visits);

        return $last && $last['out'] ? $last['out'] : null;
    }

    /** Minutes in care: closed visits, plus an open one up to $until when given. */
    public static function minutes(array $visits, $until = null): int
    {
        $m = 0;
        foreach ($visits as $v) {
            if (! $v['in']) continue;
            $in = Carbon::parse($v['in']->occurred_at, 'UTC');
            $out = $v['out'] ? Carbon::parse($v['out']->occurred_at, 'UTC') : ($until ? Carbon::parse($until, 'UTC') : null);
            if ($out && $out->gt($in)) $m += (int) $in->diffInMinutes($out);
        }

        return $m;
    }

    /** "8:02 AM – 11:30 AM, 1:15 PM – still here" in the given timezone. */
    public static function text(array $visits, string $tz, string $open = 'still here'): string
    {
        $t = fn ($e) => Carbon::parse($e->occurred_at, 'UTC')->timezone($tz)->format('g:i A');

        return implode(', ', array_map(fn ($v) => ($v['in'] ? $t($v['in']) : '?') . ' – ' . ($v['out'] ? $t($v['out']) : $open), $visits));
    }
}
