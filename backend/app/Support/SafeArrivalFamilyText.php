<?php

declare(strict_types=1);

namespace App\Support;

/**
 * WHAT SAFE ARRIVAL SAYS TO A FAMILY (2026-11-27, Anthony: "safe arrival messages to parents should only announce first
 * name of educator and from agency name - full sweep").
 *
 * The ONE place for the words of the parent "has X arrived?" / "has X been picked up?" messages (app, email, text), so
 * SafeArrivalCommand, the website demo phone (WebDemoPhoneController) and kt:safe-arrival-names-check all render the
 * same thing. Pure: reads names, writes and sends nothing.
 *
 *   - the sender is the agency (FamilyFacingName::sender): texts start "iLearn Home Childcare: ", the email From name is
 *     the agency, the call says "a Safe Arrival call from iLearn Home Childcare";
 *   - where the child is: FamilyFacingName::place - a home named after its provider reads "Amna's home child care"
 *     (the educator's FIRST name only); a real centre keeps its own name;
 *   - no educator is named unless one is part of the story, and then by FamilyFacingName::educator (first name only).
 */
final class SafeArrivalFamilyText
{
    /** Where the child is, as the family reads it. $raw is the centre's own name (used only when it is not a person's). */
    public static function place(?int $centreId, string $raw = ''): string
    {
        return FamilyFacingName::place($centreId ?: null, $raw !== '' ? $raw : 'the centre');
    }

    /**
     * The morning question. $at = "9:15 AM".
     *
     * @return array{title:string, body:string, sms_lead:string}  body = app + email; sms_lead + the reply line = the text
     */
    public static function ask(?int $centreId, string $centreRaw, string $child, string $at): array
    {
        $where = self::place($centreId, $centreRaw);

        return [
            'title' => "🛡️ Has {$child} arrived?",
            'body' => "{$child} was expected at {$where} around {$at} and hasn't been signed in. "
                . "If they're not coming in today, please tell us in the app (Not attending today). "
                . "If they're on the way, no need to reply.",
            'sms_lead' => "{$child} was expected at {$where} around {$at} and hasn't been signed in yet.",
        ];
    }

    /** The morning TEXT: the sender first, then the question, then "Reply 1 if running late, 2 if absent today." (or the body). */
    public static function askText(int $agencyId, array $ask, string $replyLine): string
    {
        return FamilyFacingName::textFrom($agencyId, $replyLine === '' ? $ask['body'] : $ask['sms_lead'] . ' ' . $replyLine);
    }

    /**
     * End of day: still signed in past pickup/closing. $what = "the usual pickup at 5:30 PM" / "closing at 6:00 PM".
     *
     * @return array{title:string, body:string}
     */
    public static function pickup(?int $centreId, string $centreRaw, string $child, string $what): array
    {
        $where = self::place($centreId, $centreRaw);

        return [
            'title' => "🛡️ Has {$child} been picked up?",
            'body' => "Our records still show {$child} signed in at {$where}, past {$what}. "
                . "If {$child} is with you, please let the centre know so they can update the record. "
                . 'If not, please contact the centre right away.',
        ];
    }

    /** Any Safe Arrival text to a family: starts with the agency's name. */
    public static function text(int $agencyId, string $body): string
    {
        return FamilyFacingName::textFrom($agencyId, $body);
    }

    /** The From name on a Safe Arrival email to a family. */
    public static function fromName(int $agencyId): string
    {
        return FamilyFacingName::sender($agencyId);
    }
}
