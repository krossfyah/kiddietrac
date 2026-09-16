<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;

/**
 * Statutory holidays, computed rather than listed (2026-09-02).
 *
 * Almost every one of these is a rule, not a date: "the third Monday in February", "the
 * Monday before 25 May". A hardcoded table would be correct until January and quietly
 * wrong afterwards, and nobody notices a missing closure until the day somebody turns up
 * to a locked door. So the rules are the source and any year can be asked for.
 *
 * Two countries, because the agencies span both: Canada (Ontario's Employment Standards
 * Act list) and the United States (the federal list). Each holiday carries a short note on
 * what it is actually for -- those go into the closure email, and a closure notice that
 * says only "we are shut" is a worse message than one that says why the day matters.
 *
 * The optional ones are genuinely optional. Ontario's ESA does not oblige a childcare
 * operator to close on the August Civic Holiday, Remembrance Day, or the National Day for
 * Truth and Reconciliation, and different agencies make different choices. Generating them
 * by default would put closures on the calendar that nobody asked for, so they are only
 * created when an agency names them.
 */
final class StatHolidays
{
    /** Ontario ESA public holidays — observed by default. */
    private const CA_CORE = [
        'new-year' => "New Year's Day",
        'family-day' => 'Family Day',
        'good-friday' => 'Good Friday',
        'victoria-day' => 'Victoria Day',
        'canada-day' => 'Canada Day',
        'labour-day' => 'Labour Day',
        'thanksgiving-ca' => 'Thanksgiving Day',
        'christmas' => 'Christmas Day',
        'boxing-day' => 'Boxing Day',
    ];

    /** Real holidays, but not ones the ESA obliges anyone to close for. */
    private const CA_OPTIONAL = [
        'civic-holiday' => 'Civic Holiday',
        'truth-reconciliation' => 'National Day for Truth and Reconciliation',
        'remembrance-day' => 'Remembrance Day',
    ];

    /** US federal holidays — all observed by default. */
    private const US_CORE = [
        'new-year' => "New Year's Day",
        'mlk-day' => 'Martin Luther King Jr. Day',
        'presidents-day' => "Presidents' Day",
        'memorial-day' => 'Memorial Day',
        'juneteenth' => 'Juneteenth',
        'independence-day' => 'Independence Day',
        'labor-day' => 'Labor Day',
        'indigenous-peoples-day' => "Indigenous Peoples' Day",
        'veterans-day' => 'Veterans Day',
        'thanksgiving-us' => 'Thanksgiving Day',
        'christmas' => 'Christmas Day',
    ];

    /**
     * Why the day matters, in the voice of a note to a family.
     *
     * Written to be warm and specific rather than encyclopaedic. Two of these -- Truth and
     * Reconciliation, and Remembrance Day -- are days of mourning, not celebration, and are
     * worded so that "enjoy the long weekend" never lands on them.
     */
    private const MEANING = [
        'new-year' => 'A quiet marker between one year and the next — a day for rest, for family, and for whatever small hopes you are carrying into the months ahead.',
        'family-day' => 'Ontario set this day aside in the middle of a long winter for exactly one purpose: to be with the people you love, with nothing else asked of you.',
        'good-friday' => 'A solemn day in the Christian calendar, and for many families a quiet start to a long weekend spent together.',
        'victoria-day' => "Canada's first long weekend of the warm months, and the traditional signal that spring has properly arrived.",
        'canada-day' => 'The anniversary of Confederation in 1867 — a day for celebrating the country, and for reflecting honestly on the work of making it fair for everyone who lives here.',
        'civic-holiday' => 'A midsummer holiday kept across much of Ontario, with no obligation attached to it beyond a day off in the best part of the year.',
        'labour-day' => 'A day owed to the workers who fought for the eight-hour day, the weekend, and safe conditions — the ordinary protections that took extraordinary effort to win.',
        'truth-reconciliation' => 'A day to honour the children who never returned from residential schools, and the Survivors, families and communities still carrying that loss. It is a day for listening and remembrance, not celebration.',
        'thanksgiving-ca' => 'A harvest holiday, and the year\u{2019}s clearest invitation to sit down with the people who matter to you and say what you are grateful for.',
        'remembrance-day' => 'At eleven o\u{2019}clock we fall silent for those who served and those who did not come home. A day held with care rather than marked with festivity.',
        'christmas' => 'However your family keeps it, a day given over to being together, to generosity, and to a little more warmth than usual in the darkest part of the year.',
        'boxing-day' => 'Traditionally the day for giving to those who serve others through the year — and, more practically, a second quiet day at home.',
        'mlk-day' => 'A day for Dr. King\u{2019}s unfinished work: the plain idea that every child deserves the same chance, and the courage it still takes to insist on it.',
        'presidents-day' => 'A day set aside to consider the office and those who have held it, and what citizens are owed by the people who serve them.',
        'memorial-day' => 'A day of remembrance for those who died in military service. Held gently, and traditionally the unofficial start of summer.',
        'juneteenth' => 'The day in 1865 when word of emancipation finally reached the last enslaved people in Texas — freedom delayed, then at last delivered, and worth marking every year since.',
        'independence-day' => 'The anniversary of the Declaration of Independence in 1776 — fireworks, family, and the long argument about what the promise means.',
        'labor-day' => 'A day owed to the workers who won the weekend, the eight-hour day, and safe conditions — protections that were fought for, not granted.',
        'indigenous-peoples-day' => 'A day to honour Indigenous peoples, their histories, and their continuing presence in the places we all now share.',
        'veterans-day' => 'A day to thank those who served and came home, and to remember what that service asked of them and their families.',
        'thanksgiving-us' => 'The year\u{2019}s longest table — a day for gathering, for cooking too much, and for saying out loud what you are grateful for.',
    ];

    /** Normalise the many spellings the data uses: "Canada", "CA", "ca". */
    public static function countryCode(?string $raw): string
    {
        $c = strtolower(trim((string) $raw));
        if ($c === '' ) {
            return 'CA';
        }
        if (str_starts_with($c, 'ca') || str_contains($c, 'canad')) {
            return 'CA';
        }
        if (str_starts_with($c, 'us') || str_contains($c, 'united states') || $c === 'usa') {
            return 'US';
        }

        return 'CA';
    }

    /**
     * Every holiday in a year, as [key, name, date (Y-m-d), optional, meaning].
     *
     * @param  string[]  $extraKeys  optional holidays this agency has chosen to observe
     * @return array<int,array{key:string,name:string,date:string,optional:bool,meaning:string}>
     */
    public static function forYear(int $year, string $country = 'CA', array $extraKeys = []): array
    {
        $country = self::countryCode($country);
        $dates = $country === 'US' ? self::usDates($year) : self::caDates($year);
        $core = $country === 'US' ? self::US_CORE : self::CA_CORE;
        $optional = $country === 'US' ? [] : self::CA_OPTIONAL;

        $out = [];
        foreach ($dates as $key => $date) {
            $isCore = isset($core[$key]);
            if (! $isCore && ! in_array($key, $extraKeys, true)) {
                continue;   // an optional day this agency has not asked for
            }
            $out[] = [
                'key' => $key,
                'name' => $core[$key] ?? $optional[$key] ?? $key,
                'date' => $date,
                'optional' => ! $isCore,
                'meaning' => self::MEANING[$key] ?? '',
            ];
        }

        usort($out, fn ($a, $b) => strcmp($a['date'], $b['date']));

        /* Resolve the observed dates in date order, so that when two holidays are pushed
           onto the same weekday the earlier one keeps it and the later one moves on. */
        $claimed = [];
        foreach ($out as $i => $h) {
            $obs = self::observedDate($h['date'], $country, $claimed);
            $claimed[$obs] = true;
            $out[$i]['observed'] = $obs;
            $out[$i]['moved'] = $obs !== $h['date'];
        }

        return $out;
    }

    /** Holidays between two dates, spanning as many years as the range covers. */
    public static function between(Carbon $from, Carbon $to, string $country = 'CA', array $extraKeys = []): array
    {
        $out = [];
        for ($y = (int) $from->year; $y <= (int) $to->year; $y++) {
            foreach (self::forYear($y, $country, $extraKeys) as $h) {
                /* Filtered on the OBSERVED date, because that is the day the centre is
                   shut and the only one anybody acts on. A Boxing Day that falls just
                   outside the window but is observed just inside it still counts. */
                $when = $h['observed'] ?? $h['date'];
                if ($when >= $from->toDateString() && $when <= $to->toDateString()) {
                    $out[] = $h;
                }
            }
        }

        usort($out, fn ($a, $b) => strcmp($a['observed'] ?? $a['date'], $b['observed'] ?? $b['date']));

        return $out;
    }

    /**
     * The working day a holiday is actually observed on.
     *
     * Canada: forward to the next working day -- Ontario's ESA gives a substitute day off
     * when a public holiday falls on a day that is not ordinarily a working day, and the
     * next working day is the default.
     *
     * United States: 5 U.S.C. 6103 -- a Saturday holiday is observed on the preceding
     * Friday, a Sunday holiday on the following Monday. Note the Saturday case goes
     * BACKWARDS, which is the opposite of Canada and the detail most worth getting right.
     *
     * $claimed holds dates already taken by an earlier holiday this year, so a pair like
     * Christmas-on-Saturday and Boxing-Day-on-Sunday lands on two consecutive weekdays
     * instead of stacking.
     *
     * @param  array<string,bool>  $claimed
     */
    public static function observedDate(string $date, string $country = 'CA', array $claimed = []): string
    {
        $country = self::countryCode($country);
        $d = Carbon::parse($date)->startOfDay();

        $isWeekend = fn (Carbon $c) => in_array((int) $c->isoWeekday(), [6, 7], true);

        if (! $isWeekend($d) && ! isset($claimed[$d->toDateString()])) {
            return $d->toDateString();
        }

        /* Direction is decided by the ORIGINAL day, before any nudging for collisions --
           otherwise a US Saturday holiday that has been pushed to Friday could then be
           pushed forward again by a collision and end up back on the weekend. */
        $back = $country === 'US' && (int) $d->isoWeekday() === 6;
        $step = $back ? -1 : 1;

        // At most a fortnight of stepping; a real calendar never needs more than a few.
        for ($i = 0; $i < 14; $i++) {
            $d->addDays($step);
            if ($isWeekend($d)) {
                continue;
            }
            if (isset($claimed[$d->toDateString()])) {
                continue;
            }

            return $d->toDateString();
        }

        return $d->toDateString();
    }

    /** The warm note for a holiday, looked up by its display name. Blank if unknown. */
    public static function meaningOf(?string $name): string
    {
        $needle = mb_strtolower(trim((string) $name));
        if ($needle === '') {
            return '';
        }
        foreach ([self::CA_CORE, self::CA_OPTIONAL, self::US_CORE] as $set) {
            foreach ($set as $key => $label) {
                if (mb_strtolower($label) === $needle) {
                    return self::MEANING[$key] ?? '';
                }
            }
        }

        return '';
    }

    /** The optional holidays an agency may opt into, for the settings screen. */
    public static function optionalFor(string $country = 'CA'): array
    {
        return self::countryCode($country) === 'US' ? [] : self::CA_OPTIONAL;
    }

    /** @return array<string,string> key => Y-m-d */
    private static function caDates(int $y): array
    {
        $easter = self::easter($y);

        return [
            'new-year' => sprintf('%04d-01-01', $y),
            'family-day' => self::nthWeekday($y, 2, Carbon::MONDAY, 3),
            'good-friday' => $easter->copy()->subDays(2)->toDateString(),
            'victoria-day' => self::mondayBefore($y, 5, 25),
            'canada-day' => sprintf('%04d-07-01', $y),
            'civic-holiday' => self::nthWeekday($y, 8, Carbon::MONDAY, 1),
            'labour-day' => self::nthWeekday($y, 9, Carbon::MONDAY, 1),
            'truth-reconciliation' => sprintf('%04d-09-30', $y),
            'thanksgiving-ca' => self::nthWeekday($y, 10, Carbon::MONDAY, 2),
            'remembrance-day' => sprintf('%04d-11-11', $y),
            'christmas' => sprintf('%04d-12-25', $y),
            'boxing-day' => sprintf('%04d-12-26', $y),
        ];
    }

    /** @return array<string,string> key => Y-m-d */
    private static function usDates(int $y): array
    {
        return [
            'new-year' => sprintf('%04d-01-01', $y),
            'mlk-day' => self::nthWeekday($y, 1, Carbon::MONDAY, 3),
            'presidents-day' => self::nthWeekday($y, 2, Carbon::MONDAY, 3),
            'memorial-day' => self::lastWeekday($y, 5, Carbon::MONDAY),
            'juneteenth' => sprintf('%04d-06-19', $y),
            'independence-day' => sprintf('%04d-07-04', $y),
            'labor-day' => self::nthWeekday($y, 9, Carbon::MONDAY, 1),
            'indigenous-peoples-day' => self::nthWeekday($y, 10, Carbon::MONDAY, 2),
            'veterans-day' => sprintf('%04d-11-11', $y),
            'thanksgiving-us' => self::nthWeekday($y, 11, Carbon::THURSDAY, 4),
            'christmas' => sprintf('%04d-12-25', $y),
        ];
    }

    private static function nthWeekday(int $y, int $month, int $weekday, int $nth): string
    {
        $d = Carbon::create($y, $month, 1)->startOfDay();
        while ((int) $d->dayOfWeek !== $weekday) {
            $d->addDay();
        }

        return $d->addWeeks($nth - 1)->toDateString();
    }

    private static function lastWeekday(int $y, int $month, int $weekday): string
    {
        $d = Carbon::create($y, $month, 1)->endOfMonth()->startOfDay();
        while ((int) $d->dayOfWeek !== $weekday) {
            $d->subDay();
        }

        return $d->toDateString();
    }

    /** Victoria Day: the Monday preceding 25 May. */
    private static function mondayBefore(int $y, int $month, int $day): string
    {
        $d = Carbon::create($y, $month, $day)->startOfDay()->subDay();
        while ((int) $d->dayOfWeek !== Carbon::MONDAY) {
            $d->subDay();
        }

        return $d->toDateString();
    }

    /**
     * Easter Sunday, by the anonymous Gregorian algorithm.
     *
     * Computed rather than taken from PHP's easter_date(), which lives in the optional
     * calendar extension -- an extension this host may or may not have, and a holiday
     * calendar that silently loses Good Friday on a rebuild is not worth the shortcut.
     */
    private static function easter(int $y): Carbon
    {
        $a = $y % 19;
        $b = intdiv($y, 100);
        $c = $y % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $kk = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $kk) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return Carbon::create($y, $month, $day)->startOfDay();
    }
}
