<?php

namespace App\Support;

/**
 * EMAILS TO JOB APPLICANTS (2026-11-24, Anthony: "for resumes submitted wire up a book a meeting, add to our
 * database for future considerations and or politely decline (make these emails sound personable, politely and
 * kindly thank the recipient for reaching out)").
 *
 * Four letters, all signed "The KiddieTrac Team" (Anthony's choice), replies to info@:
 *   received  - the automatic "we have your application" (was a SalesMail with a sales-team footer)
 *   interview - we picked a time: when / how, Teams button, Accept / Maybe / Can't make it, .ics attached by the caller
 *   on_file   - kept on file for future roles for KEEP_MONTHS months, with the date and how to be removed
 *   decline   - a kind no, with an open door to apply again
 *
 * Letter layout in the KiddieTrac frame (navy band + wordmark, brand strip). Dark mode: a real dark palette under
 * prefers-color-scheme and mirrored for Outlook.com ([data-ogsc]/[data-ogsb]); the band is navy in both modes so the
 * light wordmark always reads. Solid background colours only (Microsoft strips gradient backgrounds).
 * Applicant names are their own; no KiddieTrac person is named (first-names rule does not even arise).
 */
final class CareersMail
{
    public const KEEP_MONTHS = 24;
    public const REPLY_TO = 'info@kiddietrac.com';
    public const CAREERS_URL = 'https://www.kiddietrac.com/careers';
    private const BAND = '#081C41';
    private const TEAL = '#1F6080';

    private static function e($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }

    private static function p(string $html, string $extra = ''): string
    {
        return '<p class="tx" style="margin:0 0 14px;font-size:15.5px;line-height:1.65;color:#334155' . $extra . '">' . $html . '</p>';
    }

    private static function button(string $label, string $url, string $bg = self::TEAL): string
    {
        return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:4px 0 18px"><tr><td bgcolor="' . $bg . '" style="border-radius:10px;background-color:' . $bg . '">'
            . '<a href="' . self::e($url) . '" style="display:inline-block;padding:13px 24px;font-size:15px;font-weight:bold;color:#ffffff;text-decoration:none;border-radius:10px">'
            . self::e($label) . ' &rarr;</a></td></tr></table>';
    }

    /** A soft panel (dark-mode aware) holding already-built HTML. */
    private static function box(string $inner, string $heading = ''): string
    {
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="box" style="background-color:#F3F8FA;border:1px solid #DCE8EE;border-radius:12px;margin:4px 0 18px">'
            . '<tr><td style="padding:16px 18px">'
            . ($heading !== '' ? '<div class="mu" style="font-size:12px;letter-spacing:1px;text-transform:uppercase;font-weight:bold;color:#5B6B7C;margin:0 0 8px">' . self::e($heading) . '</div>' : '')
            . $inner . '</td></tr></table>';
    }

    private static function note(?string $note): string
    {
        $note = trim((string) $note);
        if ($note === '') return '';
        return self::box('<div class="tx" style="font-size:15px;line-height:1.6;color:#0D1B2A">' . nl2br(self::e($note)) . '</div>', 'A note from our team');
    }

    private static function signoff(string $closing): string
    {
        return '<p class="tx" style="margin:22px 0 0;font-size:15.5px;line-height:1.6;color:#334155">' . self::e($closing) . ',</p>'
            . '<p class="h" style="margin:2px 0 0;font-size:16px;font-weight:bold;color:#1E2A5E">The KiddieTrac Team</p>';
    }

    /**
     * Build one letter. Returns ['subject' => ..., 'html' => ..., 'text' => ...].
     *  $a: object with first_name, role (and email)
     *  $o: note, keep_until (Y-m-d, on_file), meeting => ['when','how','where','minutes','join_url','video','rsvp'=>[accepted,tentative,declined]]
     */
    public static function build(string $kind, object $a, array $o = []): array
    {
        $first = trim((string) $a->first_name) ?: 'there';
        $role = trim((string) $a->role) ?: 'the role';
        $F = self::e($first);
        $R = '<strong>' . self::e($role) . '</strong>';
        $note = self::note($o['note'] ?? null);

        switch ($kind) {
            case 'interview':
                $m = (array) ($o['meeting'] ?? []);
                $subject = 'We’d love to meet you, ' . $first . ' (' . $role . ' at KiddieTrac)';
                $pre = 'Thank you for applying. We’ve set aside time to talk: ' . ($m['when'] ?? '');
                $eyebrow = 'Let’s talk';
                $title = 'We’d love to meet you, ' . $first . '!';
                $row = fn ($k, $v) => '<tr><td valign="top" class="mu" style="padding:6px 14px 6px 0;font-size:13px;font-weight:bold;color:#5B6B7C;white-space:nowrap;width:1%">' . $k . '</td>'
                    . '<td valign="top" class="tx" style="padding:6px 0;font-size:15px;line-height:1.5;color:#0D1B2A">' . $v . '</td></tr>';
                $rows = $row('When', '<strong>' . self::e($m['when'] ?? '') . '</strong>')
                    . $row('How', self::e($m['how'] ?? ''))
                    . (! empty($m['where']) ? $row('Where', self::e($m['where'])) : '')
                    . $row('Length', self::e(($m['minutes'] ?? 30) . ' minutes'));
                $body = self::p('Hi ' . $F . ',')
                    . self::p('Thank you so much for applying for the ' . $R . ' role at KiddieTrac, and for taking the time to tell us about yourself. We really enjoyed reading your application, and we’d love to get to know you better.')
                    . self::p('We’ve set aside some time for a conversation:')
                    . self::box('<table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $rows . '</table>');
                if (! empty($m['join_url'])) {
                    $body .= self::button(($m['video'] ?? '') === 'teams' ? 'Join the Microsoft Teams meeting' : 'Join the meeting', $m['join_url'], ($m['video'] ?? '') === 'teams' ? '#4F52B2' : self::TEAL);
                }
                if (! empty($m['rsvp'])) {
                    [$yes, $maybe, $no] = $m['rsvp'];
                    $b = fn ($label, $url, $bg) => '<td style="padding:0 8px 8px 0"><a href="' . self::e($url) . '" style="display:inline-block;padding:9px 16px;font-size:14px;font-weight:bold;color:#ffffff;background-color:' . $bg . ';border-radius:8px;text-decoration:none">' . $label . '</a></td>';
                    $body .= '<div class="mu" style="font-size:13px;font-weight:bold;color:#5B6B7C;margin:0 0 8px">Does this time work for you?</div>'
                        . '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 10px"><tr>'
                        . $b('✓ Yes, I’ll be there', $yes, '#16A34A') . $b('? Maybe', $maybe, '#B45309') . $b('✕ I can’t make it', $no, '#B91C1C') . '</tr></table>';
                }
                $body .= self::p('If the time doesn’t suit you, no problem at all. Just reply to this email and we’ll happily find another time that works better.')
                    . self::p('<strong>What to expect:</strong> a relaxed, friendly conversation. We’ll tell you more about KiddieTrac and the role, and we’d love to hear about your experience and what you’re looking for next. Please bring any questions you have; there are no wrong ones.')
                    . $note
                    . self::p('The calendar invitation is attached, so you can add it to Outlook, Gmail or Apple Calendar in one tap.', ';font-size:13.5px;color:#5B6B7C')
                    . self::p('We’re really looking forward to speaking with you.')
                    . self::signoff('Warm regards');
                $text = "Hi {$first},\n\nThank you so much for applying for the {$role} role at KiddieTrac. We'd love to get to know you better.\n\n"
                    . 'When: ' . ($m['when'] ?? '') . "\nHow: " . ($m['how'] ?? '') . (! empty($m['where']) ? "\nWhere: " . $m['where'] : '') . "\nLength: " . ($m['minutes'] ?? 30) . " minutes\n"
                    . (! empty($m['join_url']) ? 'Join: ' . $m['join_url'] . "\n" : '')
                    . "\nIf the time doesn't suit you, just reply and we'll find another.\n\nWarm regards,\nThe KiddieTrac Team";
                break;

            case 'on_file':
                $until = ! empty($o['keep_until']) ? \Carbon\Carbon::parse($o['keep_until'])->format('F j, Y') : '';
                $subject = 'Thank you, ' . $first . '. We’d like to keep your application on file';
                $pre = 'Thank you for reaching out to KiddieTrac. We’d love to keep you in mind for future roles.';
                $eyebrow = 'Thank you';
                $title = 'Thank you for reaching out, ' . $first;
                $body = self::p('Hi ' . $F . ',')
                    . self::p('Thank you so much for reaching out and applying for the ' . $R . ' role at KiddieTrac. We know it takes real time and care to put an application together, and we’re genuinely grateful that you thought of us.')
                    . self::p('We enjoyed learning about your background. We don’t have the right opening for you at the moment, but we’d love to keep your application on file and reach out when a role comes up that’s a great match for your experience.')
                    . self::box(
                        '<div class="tx" style="font-size:14.5px;line-height:1.6;color:#0D1B2A">'
                        . 'We’ll keep your resume and application for <strong>' . self::KEEP_MONTHS . ' months</strong>' . ($until !== '' ? ', until <strong>' . self::e($until) . '</strong>' : '') . '.'
                        . ' We’ll only use it to consider you for future roles at KiddieTrac, and we never share it with anyone else.'
                        . ' If you’d like us to remove it sooner, just reply to this email and we’ll take care of it right away.</div>',
                        'What this means')
                    . $note
                    . self::p('In the meantime, you’re always welcome to keep an eye on our careers page. New roles appear there first, and you’re welcome to apply for any that catch your eye.')
                    . self::button('See open roles', self::CAREERS_URL)
                    . self::p('Thank you again for your interest in KiddieTrac. We hope our paths cross again soon.')
                    . self::signoff('With warm wishes');
                $text = "Hi {$first},\n\nThank you so much for reaching out and applying for the {$role} role at KiddieTrac. We don't have the right opening for you at the moment, but we'd love to keep your application on file and reach out when a role comes up that's a great match.\n\n"
                    . 'We will keep your resume and application for ' . self::KEEP_MONTHS . ' months' . ($until !== '' ? ', until ' . $until : '') . ", and only use it to consider you for future roles at KiddieTrac. Reply any time if you'd like it removed sooner.\n\n"
                    . 'Open roles: ' . self::CAREERS_URL . "\n\nWith warm wishes,\nThe KiddieTrac Team";
                break;

            case 'decline':
                $subject = 'Thank you for applying to KiddieTrac, ' . $first;
                $pre = 'Thank you for your interest in the ' . $role . ' role and for reaching out to us.';
                $eyebrow = 'Thank you';
                $title = 'Thank you for applying, ' . $first;
                $body = self::p('Hi ' . $F . ',')
                    . self::p('Thank you so much for your interest in the ' . $R . ' role at KiddieTrac, and for taking the time to apply. We truly appreciate you reaching out and sharing your experience with us.')
                    . self::p('We’ve carefully reviewed every application, and after a lot of thought we’ve decided to move forward with other candidates whose experience more closely matches what this role needs right now. We know this isn’t the news you were hoping for, and we’re sorry to share it.')
                    . self::p('This decision is about the fit for this particular role, not about your talent or potential. We were glad to learn about you, and we sincerely wish you every success in your search and in everything ahead.')
                    . $note
                    . self::p('If a future role at KiddieTrac catches your eye, please don’t hesitate to apply again. You’ll always be welcome.')
                    . self::button('See open roles', self::CAREERS_URL)
                    . self::signoff('With warm wishes');
                $text = "Hi {$first},\n\nThank you so much for your interest in the {$role} role at KiddieTrac, and for taking the time to apply. After careful thought, we've decided to move forward with other candidates whose experience more closely matches what this role needs right now. We're sorry to share this news.\n\n"
                    . "This is about the fit for this particular role, not your talent or potential. We wish you every success, and you're always welcome to apply again: " . self::CAREERS_URL . "\n\nWith warm wishes,\nThe KiddieTrac Team";
                break;

            default: // received
                $kind = 'received';
                $subject = 'We received your application, ' . $first;
                $pre = 'Thank you for applying for ' . $role . ' at KiddieTrac. Here is what happens next.';
                $eyebrow = 'Application received';
                $title = 'Thank you for applying, ' . $first . '!';
                $body = self::p('Hi ' . $F . ',')
                    . self::p('Thank you for applying for the ' . $R . ' role at KiddieTrac, and for reaching out to us. Your application arrived safely, and we’re so glad you’re interested in joining the team.')
                    . self::box('<div class="tx" style="font-size:14.5px;line-height:1.6;color:#0D1B2A">A real person on our team reads every application. We’ll be in touch <strong>within 5 business days</strong> to let you know where things stand.</div>', 'What happens next')
                    . self::p('If there’s anything you’d like to add in the meantime, such as a portfolio link or a quick note, just reply to this email.')
                    . self::signoff('Thank you again, and warm regards');
                $text = "Hi {$first},\n\nThank you for applying for the {$role} role at KiddieTrac. Your application arrived safely. A real person on our team reads every application, and we'll be in touch within 5 business days.\n\nIf you'd like to add anything, just reply to this email.\n\nWarm regards,\nThe KiddieTrac Team";
        }

        /* AN AGENCY'S OWN HIRING (2026-11-28, KiddieTrac HR phase 2). The same four letters, written by the agency:
           every "KiddieTrac" in a letter means the employer, so it becomes the agency's name; the careers link becomes
           its jobs page, the keep-on-file months its own setting. With no org the letter is exactly as before. */
        $org = is_array($o['org'] ?? null) ? $o['org'] : null;
        if ($org) {
            $n = (string) $org['name'];
            $swap = function (string $s, bool $html) use ($org, $n) {
                $s = str_replace(self::CAREERS_URL, (string) $org['careers_url'], $s);
                $s = str_replace(self::KEEP_MONTHS . ' months', (int) ($org['keep_months'] ?? self::KEEP_MONTHS) . ' months', $s);
                return str_replace('KiddieTrac', $html ? self::e($n) : $n, $s);
            };
            [$subject, $pre, $title, $text] = [$swap($subject, false), $swap($pre, false), $swap($title, false), $swap($text, false)];
            $body = $swap($body, true);
        }

        return ['subject' => $subject, 'html' => self::frame($kind, $pre, $eyebrow, $title, $body, $org), 'text' => $text];
    }

    /**
     * Send one letter to the applicant (or, with $testTo, to that address with [TEST] in the subject).
     * Platform mail, Reply-To info@. $ics: a calendar invitation to attach (interview). Returns true when handed to the mailer.
     * The recipient is always explicit: the applicant's own address or the named test address, never a default.
     */
    public static function send(string $kind, object $a, array $o = [], ?string $testTo = null, ?string $ics = null): bool
    {
        $to = $testTo ?: (string) ($a->email ?? '');
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
        $mail = self::build($kind, $a, $o);
        \Illuminate\Support\Facades\Mail::html($mail['html'], function ($m) use ($mail, $to, $testTo, $a, $ics, $o) {   // $o carries the agency (org): without it tenant mail went out as platform mail
            $org = is_array($o['org'] ?? null) ? $o['org'] : null;
            if ($org) {
                // Tenant mail: the agency's own switches apply, and a test agency sends nothing (SuppressAgencyMail).
                MailScope::agency($m, (int) $org['agency_id']);
                $m->from((string) config('mail.from.address', 'noreply@kiddietrac.com'), (string) $org['name']);
                if (! empty($org['reply_to'])) $m->replyTo((string) $org['reply_to'], (string) $org['name']);
            } else {
                MailScope::platform($m);
            }
            $m->to($to, $testTo ? null : (trim((string) $a->first_name) ?: null))
              ->subject(($testTo ? '[TEST] ' : '') . $mail['subject']);
            if (! $org) $m->replyTo(self::REPLY_TO, 'KiddieTrac');
            if ($ics) $m->attachData($ics, 'invite.ics', ['mime' => 'text/calendar; charset=UTF-8; method=REQUEST']);
        });
        return true;
    }

    private static function frame(string $kind, string $pre, string $eyebrow, string $title, string $body, ?array $org = null): string
    {
        $e = fn ($v) => self::e($v);
        // One dark palette, written twice: for prefers-color-scheme and for Outlook.com's [data-ogsc]/[data-ogsb].
        $rules = [
            '.bg' => 'background-color:#0B1220', '.card' => 'background-color:#111A2E', '.tx' => 'color:#E2E8F0', '.tx strong' => 'color:#FFFFFF',
            '.mu' => 'color:#9FB0C7', '.h' => 'color:#FFFFFF', '.box' => 'background-color:#16233B;border-color:#2A3A57',
            '.eb' => 'background-color:#16324A;color:#8FD3E6', '.ft' => 'background-color:#0E1729;border-color:#22314C;color:#9FB0C7',
            '.ft strong' => 'color:#E2E8F0', '.ft a' => 'color:#8FD3E6', '.kt-band' => 'background-color:' . self::BAND,
        ];
        $imp = fn ($d) => implode(';', array_map(fn ($x) => $x . ' !important', explode(';', $d)));
        $dark = '';
        $og = '';
        foreach ($rules as $sel => $decl) {
            $dark .= $sel . '{' . $imp($decl) . '}';
            $og .= '[data-ogsc] ' . $sel . ',[data-ogsb] ' . $sel . '{' . $imp($decl) . '}';
        }

        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark">'
            . '<title>' . $e($title) . '</title>'
            . '<style>@media (prefers-color-scheme: dark){' . $dark . '}' . $og
            . '@media (max-width:600px){.pad{padding-left:22px !important;padding-right:22px !important}}</style></head>'
            . '<body class="bg" style="margin:0;padding:0;background-color:#EEF3F6">'
            . '<div style="display:none;max-height:0;overflow:hidden">' . $e($pre) . '</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="bg" style="background-color:#EEF3F6"><tr><td align="center" style="padding:24px 10px">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" class="card" style="width:100%;max-width:600px;background-color:#ffffff;border-radius:16px;overflow:hidden;font-family:Arial,Helvetica,sans-serif;color:#0D1B2A">'
            . ($org
                // The agency's logo on a white tile (any logo reads on it, in light and dark mode), or its name.
                ? '<tr><td bgcolor="' . self::BAND . '" class="kt-band" style="background-color:' . self::BAND . ';padding:18px 24px;text-align:center">'
                    . (! empty($org['logo'])
                        ? '<table role="presentation" cellpadding="0" cellspacing="0" align="center" style="margin:0 auto"><tr><td bgcolor="#FFFFFF" style="background-color:#FFFFFF;border-radius:12px;padding:10px 16px"><img src="' . $e($org['logo']) . '" height="44" alt="' . $e($org['name']) . '" style="display:block;border:0;height:44px;max-width:220px;width:auto"></td></tr></table>'
                        : '<div style="font-size:20px;font-weight:bold;color:#FFFFFF;letter-spacing:.3px">' . $e($org['name']) . '</div>')
                    . '</td></tr>'
                : '<tr><td bgcolor="' . self::BAND . '" class="kt-band" style="background-color:' . self::BAND . ';padding:20px 24px;text-align:center"><img src="https://api.kiddietrac.com/email/wordmark-tagline-navy.png" width="220" alt="KiddieTrac - Smart Childcare Management Platform" style="display:block;margin:0 auto;border:0;width:220px;max-width:70%;height:auto"></td></tr>')
            . '<tr><td bgcolor="#1FA2A6" style="font-size:0;line-height:0;background-color:#1FA2A6"><img src="https://api.kiddietrac.com/email/brand-gradient.png" width="600" height="6" alt="" style="display:block;border:0;width:100%;height:6px"></td></tr>'
            . '<tr><td class="pad" style="padding:28px 36px 4px">'
            . '<div class="eb" style="display:inline-block;font-size:11.5px;letter-spacing:1.2px;text-transform:uppercase;font-weight:bold;color:' . self::TEAL . ';background-color:#E8F3F7;border-radius:999px;padding:5px 12px;margin:0 0 14px">' . $e($eyebrow) . '</div>'
            . '<div class="h" style="font-size:24px;line-height:1.3;font-weight:bold;color:#1E2A5E;margin:0 0 18px">' . $e($title) . '</div>'
            . $body
            . '</td></tr>'
            . '<tr><td style="padding:26px 0 0"></td></tr>'
            . '<tr><td class="pad ft" style="background-color:#F6F9FB;border-top:1px solid #E5ECF1;padding:16px 36px;font-size:12px;line-height:1.6;color:#7A8B9A;text-align:center">'
            . ($org
                ? '<strong style="color:#1E2A5E">' . $e($org['name']) . '</strong><br>'
                    . 'You’re receiving this because you applied for a role at ' . $e($org['name']) . '. Questions? Just reply to this email.'
                    . (empty($org['white_label']) ? '<div style="margin-top:8px;font-size:11px">Sent with KiddieTrac</div>' : '')
                : '<strong style="color:#1E2A5E">KiddieTrac</strong> &middot; Smart Childcare Management Platform<br>'
                    . 'You’re receiving this because you applied for a role at KiddieTrac. Questions? Just reply, or write to <a href="mailto:' . self::REPLY_TO . '" style="color:' . self::TEAL . ';text-decoration:none">' . self::REPLY_TO . '</a>.'
                    . '<div style="margin-top:10px">' . SocialLinks::block(SocialLinks::row()) . '</div>')
            . '</td></tr></table></td></tr></table></body></html>';
    }
}
