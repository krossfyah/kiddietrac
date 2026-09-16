<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Telling a system action apart from a person's (2026-08-24).
 *
 * The nightly AutoSignOffCommand closes children who were never checked out and staff
 * who never clocked out. Both are written with a real user id in recorded_by_id /
 * by_user_id, because those columns are NOT NULL — so an automatic 00:00 check-out is
 * filed under whichever educator happened to touch that room last. On screen it is
 * indistinguishable from that educator standing at the door signing the child out.
 *
 * That matters for ratios, for payroll, and for anyone reading an attendance record
 * months later: it currently asserts something about a person that is not true.
 *
 * The distinguishing mark is already in the data — AutoSignOffCommand writes a known
 * phrase into notes. This turns that into one shared test and one shared label, so
 * every screen says the same thing rather than each inventing its own wording.
 */
final class SystemAction
{
    /** Phrases AutoSignOffCommand writes. Matched case-insensitively. */
    private const MARKERS = [
        'auto sign-off',
        'auto signoff',
        'no check-out was recorded',
        'no clock-out recorded',
    ];

    /**
     * Was this ROW written by the nightly job? Prefers the is_automatic column and
     * falls back to the notes marker for anything written before it existed.
     *
     * Prefer this over isAuto() wherever the whole row is available: a boolean is
     * indexable and cannot be broken by someone rewording a note.
     */
    public static function isAutoRow($row): bool
    {
        if (is_object($row) && isset($row->is_automatic)) {
            return (bool) $row->is_automatic;
        }
        if (is_array($row) && array_key_exists('is_automatic', $row)) {
            return (bool) $row['is_automatic'];
        }
        $notes = is_object($row) ? ($row->notes ?? null) : ($row['notes'] ?? null);

        return self::isAuto($notes === null ? null : (string) $notes);
    }

    /** Was this row written by the nightly job rather than by a person? */
    public static function isAuto(?string $notes): bool
    {
        if ($notes === null || $notes === '') {
            return false;
        }
        $n = mb_strtolower($notes);
        foreach (self::MARKERS as $m) {
            if (str_contains($n, $m)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The bracketed suffix to show beside a time or a name.
     *
     * Deliberately short: it sits inline next to "4:30 PM" in tables, timelines and
     * digests, and a longer phrase would wrap. The full explanation belongs in a
     * tooltip, not in every row.
     */
    public static function label(?string $notes): string
    {
        return self::isAuto($notes) ? ' (auto)' : '';
    }

    /** Longer wording, for a tooltip or an email where there is room to explain. */
    public static function explain(?string $notes): string
    {
        return self::isAuto($notes)
            ? 'Recorded automatically overnight because no one signed out.'
            : '';
    }
}
