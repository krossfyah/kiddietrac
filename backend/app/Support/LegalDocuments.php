<?php

declare(strict_types=1);

namespace App\Support;

/**
 * What the legal documents currently say, and what changed when (2026-08-14).
 *
 * The privacy policy, terms of use and confidentiality terms live in one document at
 * kiddietrac.com/privacy. This is the record of its versions, and the source the notice
 * email is built from — so telling people what changed is a matter of adding an entry
 * here rather than composing an email, and the version we claim to have published is the
 * version we actually notified about.
 *
 * ADDING A VERSION
 *   1. Publish the document and bump the "Version x.y" line in its header.
 *   2. Add an entry to VERSIONS, newest first, with a plain-English summary. Write the
 *      points for a parent, not a lawyer: what changed and what it means for them.
 *   3. php artisan kiddietrac:legal-notice --test=you@example.com   (read it)
 *   4. php artisan kiddietrac:legal-notice --confirm                (send it)
 *
 * The summary is the whole point of the email. "Our terms have been updated" tells nobody
 * anything and trains people to ignore the next one.
 */
final class LegalDocuments
{
    public const URL = 'https://www.kiddietrac.com/privacy';

    /** The version currently published at that URL. */
    public const CURRENT_VERSION = '2.1';

    /**
     * Newest first. Each entry:
     *   effective — the date the version took effect (ISO)
     *   headline  — one line, used as the email subject and heading
     *   summary   — a sentence of context above the list
     *   points    — what actually changed, in plain English
     *   anchor    — deep link to the section that changed, so nobody has to hunt
     *
     * @var array<string,array{effective:string,headline:string,summary:string,points:string[],anchor:string}>
     */
    public const VERSIONS = [
        '2.1' => [
            'effective' => '2026-08-14',
            'headline' => 'We have added text message (SMS) terms to our Privacy Policy',
            'summary' => 'We are preparing to offer text message alerts — sign-in and sign-out '
                . 'notices, and urgent notices from your agency. Before sending anything we have '
                . 'set out in writing exactly how that will work.',
            'points' => [
                'A new section covers text messages: what we would send, how often, and what it costs you.',
                'Text messages are OFF unless you switch them on. Nobody is enrolled automatically, and '
                . 'agreeing is never a condition of using KiddieTrac or of your child\'s place at a centre.',
                'You can stop them at any time by replying STOP to any message, or in the app under '
                . 'Settings, Notifications. Reply HELP to any message for help.',
                'Standard message and data rates from your mobile carrier may apply.',
                'Your mobile number and your consent are never shared with anyone else for their marketing.',
                'The rest of the Privacy Policy, Terms of Use and Confidentiality terms are unchanged — '
                . 'only their section numbers have shifted to make room.',
            ],
            'anchor' => '#sms',
        ],
    ];

    /** @return array{effective:string,headline:string,summary:string,points:string[],anchor:string} */
    public static function version(?string $v = null): array
    {
        $v = $v ?: self::CURRENT_VERSION;
        if (! isset(self::VERSIONS[$v])) {
            throw new \InvalidArgumentException("No legal document version '{$v}' is recorded in LegalDocuments.");
        }

        return self::VERSIONS[$v];
    }

    public static function url(?string $v = null): string
    {
        return self::URL . (self::version($v)['anchor'] ?? '');
    }
}
