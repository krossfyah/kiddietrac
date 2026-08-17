<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Warm words for a birthday, varied rather than templated.
 *
 * A birthday note that arrives in identical wording every year stops reading as a
 * greeting and starts reading as an automated record. So each message is chosen from a
 * set of variants, seeded on the person and the year: the same child gets the same
 * message all through their birthday (a re-run cannot contradict itself), two children
 * on the same day get different ones, and the same child gets different words next year.
 *
 * Deliberately not AI-generated. This mail goes to families about their own children,
 * and a model that occasionally invents a detail or lands on an odd tone is not worth
 * the risk for six sentences.
 */
final class BirthdayGreetings
{
    /** Stable per person, per year — see the note above. */
    public static function seed(int $entityId, int $year): int
    {
        return abs($entityId * 31 + $year);
    }

    /** "turns 4 tomorrow" / "turns 4 today" / "turns 4 on Friday" */
    public static function whenPhrase(int $daysAway, Carbon $on): string
    {
        if ($daysAway <= 0) return 'today';
        if ($daysAway === 1) return 'tomorrow';
        if ($daysAway <= 6) return 'on ' . $on->format('l');
        return 'on ' . $on->format('l, j F');
    }

    /** Ordinal that reads naturally in a sentence: 1st, 2nd, 3rd, 4th... */
    public static function ordinal(int $n): string
    {
        $suffix = 'th';
        if ($n % 100 < 11 || $n % 100 > 13) {
            $suffix = [1 => 'st', 2 => 'nd', 3 => 'rd'][$n % 10] ?? 'th';
        }
        return $n . $suffix;
    }

    /** To a guardian, about their own child. */
    public static function forGuardian(int $seed, string $child, int $turning, string $when): string
    {
        return Phrasing::pick($seed, [
            "There's a very big day coming up — {child} turns {age} {when}! We hope it is full of the things they love most.",
            "{child} turns {age} {when}, and we could not let that pass without saying so. What a year it has been to watch.",
            "A little note to say that {child} turns {age} {when}. Wishing them a day of cake, noise and thoroughly earned fuss.",
            "{child} turns {age} {when}. Thank you for sharing them with us — they make our days brighter than they would otherwise be.",
            "Somebody is turning {age} {when}, and we suspect they have already told us about it. Happy birthday, {child}!",
        ], ['child' => $child, 'age' => $turning, 'when' => $when]);
    }

    /** To an educator or director, about a child in their care. */
    public static function forEducator(int $seed, string $child, int $turning, string $when): string
    {
        return Phrasing::pick($seed, [
            "{child} turns {age} {when} — a good day for a song and a bit of extra fuss.",
            "Heads up: it is {child}'s birthday {when}. They will be {age}.",
            "{child} turns {age} {when}. Worth marking in the room.",
            "A birthday {when}: {child} turns {age}. Plenty of notice to plan something small.",
        ], ['child' => $child, 'age' => $turning, 'when' => $when]);
    }

    /** To the person whose birthday it is. */
    public static function forPerson(int $seed, string $name, string $when): string
    {
        return Phrasing::pick($seed, [
            "Happy birthday, {name}. The work you do here matters to a lot of small people, and to the rest of us too. We hope your day is a good one.",
            "{name}, it's your birthday {when} — and we wanted to say thank you as much as happy birthday. Enjoy every bit of it.",
            "Wishing you a very happy birthday, {name}. Take the day as it comes, and let somebody else answer the phone.",
            "Happy birthday, {name}! Whatever the day holds, we hope it holds a proper sit down and something nice to eat.",
            "It's your birthday {when}, {name}. Thank you for everything you bring to this place — have a wonderful one.",
        ], ['name' => $name, 'when' => $when]);
    }

    /** To a director or admin, about a member of their team. */
    public static function forLead(int $seed, string $name, string $when): string
    {
        return Phrasing::pick($seed, [
            "{name}'s birthday is {when} — a good moment for a card or a quiet word of thanks.",
            "Heads up: {name} has a birthday {when}.",
            "{name} celebrates a birthday {when}. Worth a mention at handover.",
        ], ['name' => $name, 'when' => $when]);
    }
}
