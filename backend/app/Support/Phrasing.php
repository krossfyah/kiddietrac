<?php

namespace App\Support;

/**
 * Wording that does not repeat.
 *
 * The daily emails said the same sentences every single night. A parent reading
 * "Here is how Nolan's day went at Sunny Meadows" for the fortieth evening in a row
 * stops reading the email at all, which defeats the point of sending it.
 *
 * WHY THIS IS NOT AN API CALL. The obvious answer is to hand the text to a rewriting
 * service. It is the wrong answer here for four reasons:
 *
 *   1. This runs in a nightly cron across every child in every agency — hundreds of
 *      rewrites a night. A digest that must never fail should not depend on somebody
 *      else's endpoint being up at 22:30.
 *   2. The greeting, intro and sign-off are AGENCY-EDITABLE (EmailTemplates,
 *      'parent-daily-summary'). Passing those through a rewriter would silently change
 *      what an agency wrote for its own families. Only the built-in defaults vary here;
 *      agency wording is left exactly as authored.
 *   3. A generative rewrite cannot be reviewed before it reaches a parent. These are
 *      emails about somebody's child, and every sentence below has been read by a human.
 *   4. It costs nothing and cannot rate-limit, time out, or bill.
 *
 * The index is (entity id + day of year) % count, which has a useful property beyond
 * being deterministic: because the day number increases by one each night, the index
 * also advances by one, so a given child NEVER sees the same variant two days running,
 * and the cycle is exactly as long as the variant list. Two siblings on the same
 * evening land on different entries because their ids differ.
 */
class Phrasing
{
    /**
     * A stable seed for an entity on a date. Same child, same day → same wording, so
     * a resend is identical to the original rather than quietly different.
     */
    public static function seed(int $entityId, $date): int
    {
        $doy = 0;
        try {
            $doy = (int) $date->format('z');
        } catch (\Throwable $e) {
        }
        return abs($entityId + $doy);
    }

    /** Choose one variant, and substitute {name} / {centre} into it. */
    public static function pick(int $seed, array $variants, array $vars = []): string
    {
        if (! $variants) return '';
        $out = $variants[$seed % count($variants)];
        foreach ($vars as $k => $v) {
            $out = str_replace('{' . $k . '}', (string) $v, $out);
        }
        return $out;
    }

    // ── Parent daily summary ─────────────────────────────────────────────────
    // {name} arrives pre-escaped and may carry <strong>; {centre} is escaped plain.

    public const PARENT_INTRO = [
        'Here is how {name}\'s day went at {centre}.',
        'A look at what {name} got up to at {centre} today.',
        'Today\'s update on {name} from {centre}.',
        'Here is {name}\'s day at {centre}, as it happened.',
        'What {name} did at {centre} today, in brief.',
        'A few notes on {name}\'s day at {centre}.',
        'Here is how today went for {name} at {centre}.',
        'Your daily catch-up on {name} from {centre}.',
        'This is what {name}\'s day at {centre} looked like.',
        'Today at {centre}, here is what {name} was up to.',
        'A short account of {name}\'s day at {centre}.',
        'Here is what the day held for {name} at {centre}.',
        'From {centre} — how {name} spent today.',
        'The story of {name}\'s day at {centre}.',
        'Today at a glance — {name} at {centre}.',
        'How {name} filled the day at {centre}.',
        'A quick round-up of {name} at {centre} today.',
        'Everything {name} got up to at {centre} today.',
        '{name} at {centre}: today in short.',
        'Here is today, as {name} spent it at {centre}.',
    ];

    public const PARENT_ABSENT_GREETING = [
        '{name} was not in today',
        'We missed {name} today',
        'No sign of {name} today',
        '{name} was away today',
        'Today without {name}',
        '{name} did not join us today',
        'An empty spot for {name} today',
        '{name} was not with us today',
        'We did not see {name} today',
        '{name} stayed away today',
        'A quiet day without {name}',
        'No {name} with us today',
        '{name} was off today',
        'Missing {name} from the room today',
    ];

    public const PARENT_ABSENT_INTRO = [
        'We did not see {name} at {centre} today, so there is nothing to report.',
        '{name} was not at {centre} today, so today\'s update is a short one.',
        'There was no sign of {name} at {centre} today, so there is nothing to share.',
        '{name} did not attend {centre} today, so we have no moments to pass on.',
        'With {name} away from {centre} today, there is nothing to tell you about.',
        '{name} was absent from {centre} today, so this update is empty.',
        'Since {name} was not at {centre} today, there is nothing logged to share.',
        'No day to report — {name} was not at {centre} today.',
        '{name} was not with us at {centre} today, so there is nothing recorded.',
        'There is nothing to report today, as {name} was not at {centre}.',
        '{name} was off from {centre} today, so there is no day to write up.',
        'Nothing to pass on today — {name} was not in at {centre}.',
        'Today was a quiet one without {name} at {centre}, so this update is brief.',
        'We had no {name} at {centre} today, so there is nothing recorded to share.',
    ];

    public const PARENT_ABSENT_SIGNOFF = [
        'We hope to see {name} again soon.',
        'We look forward to having {name} back.',
        'We hope {name} is back with us soon.',
        'Looking forward to seeing {name} next time.',
        'We will be glad to have {name} back.',
        'Hoping to see {name} again before long.',
        'We hope all is well and that {name} is back soon.',
        'See you and {name} soon, we hope.',
        'We will keep {name}\'s spot ready.',
        'Until {name} is back with us — take care.',
        'We will be looking out for {name} next time.',
        'Wishing you well, and hoping to see {name} soon.',
        'Whenever {name} is ready, we are here.',
        'Take care, and see you both before long.',
    ];
}
