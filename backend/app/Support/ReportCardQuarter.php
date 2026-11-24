<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * REPORT CARD QUARTERS (2026-11-04). Anthony: "add a report card section for parents to see their childs report
 * cards and a button to request a report card from their educator (should be done each quarter)".
 * His choice: the SCHOOL year - Q1 Sep-Nov, Q2 Dec-Feb, Q3 Mar-May, Q4 Jun-Aug - in the agency's time zone.
 *
 * The label ("Q1 2026–27 (Sep–Nov)") is what goes in report_cards.term, so a card, a parent's request and the
 * educators' quarter-end reminder all agree on which quarter a card belongs to. Never change the label format once
 * cards carry it - match() reads it back.
 */
final class ReportCardQuarter
{
    private const MONTHS = [1 => 'Sep–Nov', 2 => 'Dec–Feb', 3 => 'Mar–May', 4 => 'Jun–Aug'];

    /** @return array{key:string,label:string,q:int,year:string,start:string,end:string} */
    public static function forDate(Carbon $d): array
    {
        $m = (int) $d->month;
        $y = (int) $d->year;
        $startYear = $m >= 9 ? $y : $y - 1;                       // the school year that holds this date
        $q = $m >= 9 && $m <= 11 ? 1 : ($m === 12 || $m <= 2 ? 2 : ($m <= 5 ? 3 : 4));
        $first = [1 => [$startYear, 9], 2 => [$startYear, 12], 3 => [$startYear + 1, 3], 4 => [$startYear + 1, 6]][$q];
        $start = Carbon::create($first[0], $first[1], 1, 0, 0, 0, $d->getTimezone());
        $end = $start->copy()->addMonths(3)->subDay();
        $yr = $startYear . '–' . substr((string) ($startYear + 1), 2);
        return [
            'key' => $startYear . '-Q' . $q,
            'label' => 'Q' . $q . ' ' . $yr . ' (' . self::MONTHS[$q] . ')',
            'q' => $q,
            'year' => $yr,
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
        ];
    }

    /** The quarter today is in, in the agency's time zone. */
    public static function current(?int $agencyId): array
    {
        return self::forDate(AgencyTime::now($agencyId));
    }

    /** This quarter and the $n before it, newest first (the staff term picker). */
    public static function recent(?int $agencyId, int $n = 4): array
    {
        $d = AgencyTime::now($agencyId)->startOfMonth();
        $out = [];
        for ($i = 0; $i <= $n; $i++) {
            $out[] = self::forDate($d->copy()->subMonths(3 * $i));
        }
        return $out;
    }

    /** Does a card's term belong to this quarter? Exact label, or the label without the months ("Q1 2026–27"). */
    public static function matches(?string $term, array $quarter): bool
    {
        $t = trim((string) $term);
        if ($t === '') return false;
        $short = 'Q' . $quarter['q'] . ' ' . $quarter['year'];
        return $t === $quarter['label'] || stripos($t, $short) === 0;
    }
}
