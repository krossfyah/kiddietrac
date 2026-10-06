<?php

namespace App\Support;

use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Event RSVP mail (2026-10-05): the invite, the registrant's confirmation, and the
 * notice to the event's notify address.
 *
 * "SENT" MEANS WHAT THE MAIL LAYER DID. Handing a message to the mailer is not delivery:
 * SuppressAgencyMail can still cancel it (the agency switch, an unclaimed or closed
 * account on that address), and it writes its decision to email_logs in the same call.
 * So every send here reads that row back and reports sent / suppressed / failed from it,
 * never from the fact that no exception was thrown. See [[never-default-a-recipient]].
 */
final class RsvpMail
{
    public const APP = 'https://app.kiddietrac.com';

    public static function rsvpUrl(string $inviteToken): string
    {
        return self::APP . '/rsvp.html?i=' . $inviteToken;
    }

    public static function optOutUrl(string $inviteToken): string
    {
        return self::APP . '/rsvp.html?o=' . $inviteToken;
    }

    /** The endpoint a mail provider POSTs to for one-click unsubscribe (RFC 8058). */
    public static function oneClickUrl(string $inviteToken): string
    {
        return 'https://api.kiddietrac.com/api/v1/public/rsvp/optout/' . $inviteToken;
    }

    /* A PLAIN-TEXT COPY of the HTML. HTML-only mail scores worse with spam filters than
       the same message with a text part, and some readers prefer it. Links keep their
       address, written after the text, so nothing in the text copy is unclickable. */
    public static function plainText(string $html): string
    {
        $t = preg_replace('#<(head|style|script)\b[^>]*>.*?</\1>#is', '', $html);
        $t = preg_replace('#<div[^>]*display:\s*none[^>]*>.*?</div>#is', '', $t);          // preheader
        $t = preg_replace_callback('#<a\b[^>]*href="([^"]+)"[^>]*>(.*?)</a>#is', function ($m) {
            $label = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $url = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            return ($label !== '' && $label !== $url) ? $label . ': ' . $url : $url;
        }, $t);
        $t = preg_replace('#<(br|/p|/h[1-6]|/tr|/div|/li)\b[^>]*>#i', "\n", $t);
        $t = preg_replace('#</td>#i', "  ", $t);
        $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace("/[ \t]+/", ' ', $t);
        $t = preg_replace("/ *\n */", "\n", $t);
        $t = preg_replace("/\n{3,}/", "\n\n", $t);

        return trim($t) . "\n";
    }

    public static function publicUrl(string $eventToken): string
    {
        return self::APP . '/rsvp.html?e=' . $eventToken;
    }

    /* ── BRANDING (2026-10-06) ─────────────────────────────────────────────────────
       Anthony: "make event RSVP not branding to the agency with a setting and use no
       kiddietrac or agency header or footers with this setting".

       An UNBRANDED event ('none') is framed by self::frame() instead of
       EmailTemplate::wrap(): no logo header, no footer, no product or agency name — only
       the event's own image, title, text, details and the plain unsubscribe line. It is
       still sent through RsvpMail::send() exactly like the branded mail, so suppression,
       the email log and the (invisible) open-tracking pixel all still apply. */
    public static function unbranded(object $event): bool
    {
        return ($event->branding ?? 'agency') === 'none';
    }

    /** Who an unbranded event's mail is from, by name: its sender account, else the event. */
    private static function organiser(object $event): string
    {
        $s = ! empty($event->sender_id) ? RsvpSender::find((int) $event->agency_id, (int) $event->sender_id) : null;

        return $s && trim((string) $s->from_name) !== '' ? (string) $s->from_name : (string) $event->title;
    }

    /**
     * The From to set on an unbranded event's mail — [address, name] — or null to leave the
     * mailer's own. The name is the sender account's, else the event title; never the
     * agency's name.
     */
    public static function fromFor(object $event): ?array
    {
        if (! self::unbranded($event)) return null;
        $s = ! empty($event->sender_id) ? RsvpSender::find((int) $event->agency_id, (int) $event->sender_id) : null;
        if ($s) return [$s->from_address, trim((string) $s->from_name) !== '' ? $s->from_name : (string) $event->title];

        return [AgencyMailer::forAgency((int) $event->agency_id)->fromAddress(), (string) $event->title];
    }

    /** The branded wrapper, or for an unbranded event a plain frame with nothing added. */
    private static function frame(object $event, string $body, array $opts): string
    {
        if (! self::unbranded($event)) {
            return EmailTemplate::wrap((int) $event->agency_id, $body, $opts);
        }
        $pre = e((string) ($opts['preheader'] ?? ''));

        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="color-scheme" content="light only"><title>' . e((string) ($opts['title'] ?? $event->title)) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#F4F6F8;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">'
            . ($pre !== '' ? '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">' . $pre . '</div>' : '')
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F4F6F8;"><tr><td align="center" style="padding:24px 12px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#FFFFFF;border-radius:14px;"><tr><td style="padding:26px 26px 22px;">'
            . $body
            . '</td></tr></table></td></tr></table></body></html>';
    }

    /** An unbranded mail's title, under its image (the branded wrapper puts it in the header). */
    private static function heading(object $event, string $text): string
    {
        return self::unbranded($event) ? '<h1 style="margin:0 0 16px;font-size:22px;line-height:1.3;color:#0F172A;">' . e($text) . '</h1>' : '';
    }

    /** "Saturday, November 14, 2026 · 10:00 AM – 12:00 PM EST", in the agency's zone. */
    public static function whenLabel(object $event): string
    {
        if (! $event->starts_at) {
            return '';
        }
        $tz = AgencyTime::tz((int) $event->agency_id);
        $s = Carbon::parse($event->starts_at, 'UTC')->setTimezone($tz);
        $out = $s->format('l, F j, Y · g:i A');
        if ($event->ends_at) {
            $e = Carbon::parse($event->ends_at, 'UTC')->setTimezone($tz);
            $out .= $e->isSameDay($s) ? ' – ' . $e->format('g:i A') : ' – ' . $e->format('l, F j, g:i A');
        }

        return $out . ' ' . $s->format('T');
    }

    /** {{name}}, {{first_name}}, {{event}}, {{date}}, {{location}}, {{agency}} */
    public static function fill(string $text, object $event, ?string $name): string
    {
        // Unbranded: {{agency}} is the organiser (sender account name, else the event), never the agency.
        $agency = self::unbranded($event) ? self::organiser($event) : (string) DB::table('agencies')->where('id', $event->agency_id)->value('name');
        $name = trim((string) $name);
        $first = $name !== '' ? strtok($name, ' ') : '';
        $map = [
            'name' => $name !== '' ? $name : 'there',
            'first_name' => $first !== '' ? $first : 'there',
            'event' => (string) $event->title,
            'date' => self::whenLabel($event),
            'location' => (string) ($event->location ?? ''),
            'agency' => $agency,
        ];

        return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/i', function ($m) use ($map) {
            $k = strtolower($m[1]);
            return array_key_exists($k, $map) ? $map[$k] : $m[0];
        }, $text);
    }

    private static function para(string $text): string
    {
        $out = '';
        foreach (preg_split('/\n\s*\n/', trim($text)) as $p) {
            if (trim($p) === '') continue;
            $out .= '<p style="margin:0 0 14px;font-size:15px;color:#334155;line-height:1.6">' . nl2br(e(trim($p))) . '</p>';
        }
        return $out;
    }

    private static function image(?string $url, string $alt = ''): string
    {
        if (! $url) return '';
        // Described, not alt="": an image with no text is a junk-mail signal, and the text
        // is what a reader sees when their mail app blocks images.
        return '<div style="margin:0 0 18px;text-align:center"><img src="' . e($url) . '" alt="' . e($alt) . '" width="560" '
            . 'style="display:block;width:100%;max-width:560px;height:auto;border:0;border-radius:12px;margin:0 auto"></div>';
    }

    private static function detailsBox(object $event): string
    {
        $rows = '';
        $when = self::whenLabel($event);
        if ($when !== '') $rows .= '<tr><td style="padding:4px 10px 4px 0;font-weight:700;color:#1E40AF;vertical-align:top">When</td><td style="padding:4px 0;color:#334155">' . e($when) . '</td></tr>';
        if (trim((string) $event->location) !== '') $rows .= '<tr><td style="padding:4px 10px 4px 0;font-weight:700;color:#1E40AF;vertical-align:top">Where</td><td style="padding:4px 0;color:#334155">' . nl2br(e($event->location)) . '</td></tr>';
        if ($rows === '') return '';

        // calloutBox, not a hand-painted box: its kt-callout cell is what the template's
        // dark-mode rules repaint, so the light tint does not end up under light text.
        return EmailTemplate::calloutBox('<table role="presentation" cellpadding="0" cellspacing="0" style="font-size:14px;line-height:1.5">' . $rows . '</table>', 'info');
    }

    private static function optOutLine(object $event, string $inviteToken): string
    {
        if (self::unbranded($event)) {
            return '<p style="margin:22px 0 0;font-size:12px;color:#94A3B8;line-height:1.5;text-align:center">'
                . 'You received this because you were invited to ' . e((string) $event->title) . '. '
                . '<a href="' . e(self::optOutUrl($inviteToken)) . '" style="color:#64748B;text-decoration:underline">Unsubscribe from event invitations</a>.</p>';
        }
        $agency = (string) DB::table('agencies')->where('id', $event->agency_id)->value('name');

        return '<p style="margin:22px 0 0;font-size:12px;color:#94A3B8;line-height:1.5;text-align:center">'
            . 'You received this because ' . e($agency) . ' invited you to an event. '
            . '<a href="' . e(self::optOutUrl($inviteToken)) . '" style="color:#64748B;text-decoration:underline">Unsubscribe from event invitations</a>.</p>';
    }

    /** The invite. Returns [subject, html]. */
    public static function invite(object $event, object $invite): array
    {
        $subject = self::fill((string) $event->email_subject, $event, $invite->name);
        $body = self::image($event->image_url, (string) ($event->title ?? ''))
            . self::heading($event, (string) $event->title)
            . self::para(self::fill((string) $event->email_body, $event, $invite->name))
            . self::detailsBox($event)
            . EmailTemplate::button('RSVP now', self::rsvpUrl($invite->token))
            // Unbranded: the fallback is link TEXT, so the platform's address is never printed.
            . (self::unbranded($event)
                ? '<p style="margin:0;font-size:12.5px;color:#64748B;text-align:center">If the button doesn’t work, <a href="' . e(self::rsvpUrl($invite->token)) . '" style="color:#1F6080">open the RSVP form here</a>.</p>'
                : '<p style="margin:0;font-size:12.5px;color:#64748B;text-align:center">Or open this link: <a href="' . e(self::rsvpUrl($invite->token)) . '" style="color:#1F6080">' . e(self::rsvpUrl($invite->token)) . '</a></p>')
            . self::optOutLine($event, $invite->token);
        $html = self::frame($event, $body, ['title' => (string) $event->title, 'preheader' => 'You’re invited — please RSVP']);

        return [$subject, $html];
    }

    /** The registrant's copy of what they submitted. */
    public static function confirmation(object $event, object $response, ?object $invite): array
    {
        $answers = json_decode((string) $response->answers, true) ?: [];
        $msg = trim((string) ($event->confirmation_message ?? '')) !== ''
            ? self::fill((string) $event->confirmation_message, $event, $response->name)
            : 'Thank you for registering for {{event}}. Here is a copy of the details you sent us.';
        $body = self::image($event->image_url, (string) ($event->title ?? ''))
            . self::heading($event, 'You’re registered: ' . $event->title)
            . self::para(self::fill('Hi {{first_name}},', $event, $response->name) . "\n\n" . self::fill($msg, $event, $response->name))
            . self::detailsBox($event)
            . self::answersTable($answers)
            . ($invite ? '<p style="margin:14px 0 0;font-size:13px;color:#64748B;text-align:center">Need to change something? <a href="' . e(self::rsvpUrl($invite->token)) . '" style="color:#1F6080">Update your RSVP</a>.</p>' : '')
            . ($invite ? self::optOutLine($event, $invite->token) : '');
        $html = self::frame($event, $body, ['title' => 'You’re registered', 'preheader' => (string) $event->title]);

        return ['You’re registered: ' . $event->title, $html];
    }

    /** To the event's notify address: a sign-up or an opt-out, with the details. */
    public static function notice(object $event, string $kind, string $email, ?string $name, array $answers = []): array
    {
        $who = trim((string) $name) !== '' ? $name . ' <' . $email . '>' : $email;
        $tz = AgencyTime::tz((int) $event->agency_id);
        $at = now('UTC')->setTimezone($tz)->format('M j, Y g:i A T');
        if ($kind === 'opt_out') {
            $subject = 'RSVP opt-out: ' . ($name ?: $email) . ' — ' . $event->title;
            $body = self::para($who . ' unsubscribed from event invitations on ' . $at . ".\n\nThey will not be sent further invitations from your agency.");
        } else {
            $subject = ($kind === 'updated' ? 'RSVP updated: ' : 'New RSVP: ') . ($name ?: $email) . ' — ' . $event->title;
            $body = self::para($who . ($kind === 'updated' ? ' updated their RSVP' : ' signed up') . ' for ' . $event->title . ' on ' . $at . '.')
                . self::answersTable($answers)
                . '<p style="margin:14px 0 0;font-size:13px;color:#64748B">Reply to this email to reach them directly. The full list is in '
                . (self::unbranded($event) ? 'Event RSVPs' : 'KiddieTrac → Event RSVPs') . '.</p>';
        }
        $body = self::heading($event, ($kind === 'opt_out' ? 'Opt-out: ' : 'New RSVP: ') . $event->title) . $body;
        $html = self::frame($event, $body, ['title' => $kind === 'opt_out' ? 'Opt-out' : 'New RSVP', 'preheader' => (string) $event->title]);

        return [$subject, $html];
    }

    private static function answersTable(array $answers): string
    {
        if (! $answers) return '';
        $rows = '';
        foreach ($answers as $a) {
            $v = $a['value'] ?? '';
            if (is_bool($v)) $v = $v ? 'Yes' : 'No';
            if (trim((string) $v) === '') continue;   // an optional question left blank
            $rows .= '<tr><td style="padding:6px 10px;border-top:1px solid #E2E8F0;font-weight:700;color:#0F172A;vertical-align:top;width:38%">' . e((string) ($a['label'] ?? '')) . '</td>'
                . '<td style="padding:6px 10px;border-top:1px solid #E2E8F0;color:#334155">' . nl2br(e((string) $v)) . '</td></tr>';
        }

        return '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;font-size:14px;line-height:1.5;border:1px solid #E2E8F0;border-radius:10px;border-collapse:separate;margin:4px 0 6px">' . $rows . '</table>';
    }

    /**
     * Send one message and report what the mail layer actually did.
     *
     * @return array{status:string, error:?string, log_id:?int}
     */
    public static function send(int $agencyId, string $to, ?string $toName, string $subject, string $html, array $opts = []): array
    {
        $to = strtolower(trim($to));
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['status' => 'failed', 'error' => 'Not a valid email address.', 'log_id' => null];
        }
        /* A per-event sender account (RsvpSender) or the agency's own mail. A sender that has
           since been deleted is a FAILURE, not a quiet fallback to another mailbox: the event
           said which address its mail comes from. */
        $sender = null;
        if (! empty($opts['sender_id'])) {
            $sender = RsvpSender::find($agencyId, (int) $opts['sender_id']);
            if (! $sender) {
                return ['status' => 'failed', 'error' => 'The sender account chosen for this event no longer exists. Pick another on the event.', 'log_id' => null];
            }
        }
        $build = function ($m) use ($to, $toName, $subject, $opts, $agencyId, $html) {
            $m->to($to, $toName ?: null)->subject($subject);
            // An unbranded event names itself (or its sender account), never the agency.
            if (! empty($opts['from'][0])) {
                $m->from($opts['from'][0], $opts['from'][1] ?? null);
            }
            if (! empty($opts['reply_to'])) {
                $m->replyTo($opts['reply_to'], $opts['reply_to_name'] ?? null);
            }
            MailScope::agency($m, $agencyId);
            /* DELIVERABILITY (2026-10-05: invitations from the Town of Mono Gmail landed in
               junk). A text part alongside the HTML; no tracking pixel; and RFC 8058
               one-click unsubscribe, which Gmail and Yahoo expect on invitation-style mail
               and show as an "Unsubscribe" link beside the sender instead of a spam report. */
            try { $m->getSymfonyMessage()->text(self::plainText($html)); } catch (\Throwable $e) {}
            try { $m->getHeaders()->addTextHeader('X-KT-No-Track', '1'); } catch (\Throwable $e) {}
            if (! empty($opts['unsubscribe'])) {
                try {
                    $tok = preg_match('/[?&]o=([A-Za-z0-9]{20,48})/', (string) $opts['unsubscribe'], $mm) ? $mm[1] : null;
                    if ($tok) {
                        $m->getHeaders()->addTextHeader('List-Unsubscribe', '<' . self::oneClickUrl($tok) . '>, <' . $opts['unsubscribe'] . '>');
                        $m->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
                    } else {
                        $m->getHeaders()->addTextHeader('List-Unsubscribe', '<' . $opts['unsubscribe'] . '>');
                    }
                } catch (\Throwable $e) {}
            }
        };
        $before = (int) DB::table('email_logs')->max('id');
        try {
            if ($sender) {
                RsvpSender::html($sender, $agencyId, $html, $build);
            } else {
                AgencyMailer::forAgency($agencyId)->html($html, $build);
            }
        } catch (\Throwable $e) {
            $msg = $sender ? RsvpSender::scrub($e->getMessage(), $sender) : mb_substr($e->getMessage(), 0, 480);
            Log::warning('rsvp mail failed', ['to' => $to, 'sender_id' => $sender->id ?? null, 'error' => $msg]);
            return ['status' => 'failed', 'error' => $msg, 'log_id' => null];
        }
        // Rows written for THIS message: the suppression listener's (suppressed) or the
        // MessageSent logger's (sent), or the failure-auditing transport's (failed).
        $row = DB::table('email_logs')->where('id', '>', $before)
            ->where('to_email', 'like', '%' . str_replace(['%', '_'], ['\%', '\_'], $to) . '%')
            ->orderByDesc('id')->first(['id', 'status', 'error']);
        if (! $row) {
            return ['status' => 'failed', 'error' => 'The mail layer recorded nothing for this message, so it cannot be confirmed as sent.', 'log_id' => null];
        }
        $status = in_array($row->status, ['sent', 'suppressed', 'failed'], true) ? $row->status : 'failed';

        $err = $status === 'sent' ? null : (string) ($row->error ?: ($status === 'suppressed' ? 'Held back by the agency’s email settings.' : 'Delivery failed.'));
        if ($err !== null && $sender) $err = RsvpSender::scrub($err, $sender);

        return ['status' => $status, 'error' => $err === null ? null : mb_substr($err, 0, 480), 'log_id' => (int) $row->id];
    }

    public static function optedOut(int $agencyId, string $email): bool
    {
        return DB::table('rsvp_optouts')->where('agency_id', $agencyId)->where('email', strtolower(trim($email)))->exists();
    }
}
