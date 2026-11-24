<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The emails of a website meeting request (2026-10-23).
 *
 *   visitor(): requested | confirmed | modified | declined | cancelled — the premium
 *              KiddieTrac design (emails/booking.blade.php), from sales@ (X-KT-Send-As),
 *              replies to sales@. Confirmed/modified carry the Teams link and an .ics.
 *   salesRequest(): the internal "new meeting request" notice to sales@ with buttons into
 *              Sales → Bookings, where it is accepted, modified or declined.
 *
 * $testTo sends any of them to that address instead, subject marked [TEST] (Anthony's
 * Gmail for samples; nothing goes to the visitor).
 */
class BookingMail
{
    public const TZ = 'America/Toronto';
    public const SALES = 'sales@kiddietrac.com';
    public const TYPES = [
        'demo'      => ['name' => 'Product demo',       'min' => 45],
        'sales'     => ['name' => 'Sales call',         'min' => 30],
        'onboard'   => ['name' => 'Onboarding session', 'min' => 90],
        'wl'        => ['name' => 'White label demo',   'min' => 45],
        'migration' => ['name' => 'Migration consult',  'min' => 30],
    ];

    /** A film to show in a test send before it is switched on for everyone (kt:booking-test-emails --video). */
    public static ?array $videoOverride = null;

    /* THE PROPOSAL FLOW (2026-10-23, Anthony: "remove the free 14 day trial and change to
       exploring partnership opportunities with presenting a proposal ... attach a digital
       intake form ... send me example email before using this"). Off for real visitors until
       he approves (PlatformSettings sales.booking.proposal_flow = 1); a test send can force it. */
    public static ?bool $proposal = null;
    public static ?bool $intakeDoneOverride = null;

    public static function proposalFlow(): bool
    {
        return (string) PlatformSettings::get('sales.booking.proposal_flow', '0') === '1';
    }

    public static function typeName(object $b): string
    {
        return self::TYPES[$b->type]['name'] ?? 'Meeting';
    }

    public static function whenEt($at): string
    {
        return Carbon::parse($at, 'UTC')->tz(self::TZ)->format('l, F j, Y \a\t g:i A') . ' ET';
    }

    /** "1:00 PM your time (Vancouver)" when the visitor is somewhere else, else null. */
    public static function whenLocal(object $b): ?string
    {
        $tz = (string) ($b->visitor_tz ?? '');
        if ($tz === '' || ! in_array($tz, \DateTimeZone::listIdentifiers(), true)) return null;
        $s = Carbon::parse($b->starts_at, 'UTC');
        if ($s->copy()->tz($tz)->utcOffset() === $s->copy()->tz(self::TZ)->utcOffset()) return null;
        $city = str_replace('_', ' ', substr($tz, strrpos($tz, '/') + 1));
        return $s->tz($tz)->format('l, F j \a\t g:i A') . ' your time (' . $city . ')';
    }

    /** What the meeting covers, by type. */
    public static function agenda(object $b): array
    {
        $who = $b->agency ? e($b->agency) : 'your program';
        return match ($b->type) {
            'onboard' => [
                '<strong>Your account, set up together</strong> &mdash; centres or homes, rooms and age groups, ratios, operating hours.',
                '<strong>Your people</strong> &mdash; importing children and families, inviting staff, and how parents get the app.',
                '<strong>Billing &amp; subsidies</strong> &mdash; fees, invoices, automatic payments and subsidy tracking for ' . $who . '.',
                '<strong>Day one</strong> &mdash; check-in with One-Tap, daily reports and messages, then your questions.',
            ],
            'sales', 'migration' => [
                '<strong>Your program today</strong> &mdash; how ' . $who . ' runs now and what you&rsquo;d like to change.',
                $b->type === 'migration'
                    ? '<strong>Moving your data</strong> &mdash; what we can bring over from your current system, and how long it takes.'
                    : '<strong>Plans &amp; pricing</strong> &mdash; what fits your size, with no surprises.',
                '<strong>Your questions</strong> &mdash; anything you want to know.',
                self::$proposal ?? self::proposalFlow()
                    ? '<strong>Next steps</strong> &mdash; a proposal tailored to ' . $who . ', and how we&rsquo;d work together.'
                    : '<strong>Next steps</strong> &mdash; a tailored proposal, free data migration and white-glove setup &mdash; live in as little as 14 days.',
            ],
            'wl' => [
                '<strong>Your brand, your app</strong> &mdash; logo, colours and your own name on the portal and parent app.',
                '<strong>How partners work</strong> &mdash; managing agencies, billing them, and support behind you.',
                '<strong>Live walkthrough</strong> &mdash; the platform your customers would use.',
                '<strong>Pricing &amp; next steps</strong> &mdash; and your questions.',
            ],
            default => [
                '<strong>A quick hello</strong> &mdash; tell us about ' . $who . ' so we focus on what matters to you.',
                '<strong>A live walkthrough</strong> &mdash; check-in with One-Tap, Safe Arrival, daily reports and the parent app, billing and subsidies, staff schedules and ratios.',
                '<strong>Your questions</strong> &mdash; ask anything, including pricing.',
                self::$proposal ?? self::proposalFlow()
                    ? '<strong>Next steps</strong> &mdash; exploring a partnership, and a proposal built around ' . $who . '.'
                    : '<strong>Next steps</strong> &mdash; a tailored proposal, free data migration and white-glove setup &mdash; live in as little as 14 days.',
            ],
        };
    }

    public static function prepare(object $b): array
    {
        $out = [];
        if ($b->meet_via !== 'phone') {
            $out[] = '<strong>A computer or tablet</strong> with a camera and microphone. The Teams link works in Chrome, Edge or Safari &mdash; no download needed.';
        } else {
            $out[] = '<strong>Your phone nearby</strong> at the time above; we&rsquo;ll call from <strong>1-855-400-9996</strong>.';
        }
        $out[] = '<strong>A rough count</strong> of locations, children and staff &mdash; it helps us show you the right setup.';
        $out[] = '<strong>The tools you use today</strong> (software, spreadsheets, paper) and the one task that takes most of your week.';
        if ($b->type === 'onboard') $out[] = '<strong>If you have them,</strong> a list of families and children (a spreadsheet export is fine) so we can import as we go.';
        return $out;
    }

    /** "Sam with Anthony from KiddieTrac": who is meeting, first names only on both sides. */
    public static function whoLine(object $b): string
    {
        $visitor = strtok(trim((string) ($b->name ?? '')), ' ') ?: 'You';
        [$host] = self::host($b);
        return $visitor . ' with ' . ($host === 'The KiddieTrac team' ? 'the KiddieTrac team' : $host . ' from KiddieTrac');
    }

    private static function host(object $b): array
    {
        // FIRST NAME ONLY for KiddieTrac staff in these emails (Anthony, 2026-10-23).
        $u = $b->host_user_id ? DB::table('users')->where('id', $b->host_user_id)->first(['first_name']) : null;
        $n = $u ? trim((string) $u->first_name) : '';
        return [$n !== '' ? $n : 'The KiddieTrac team', 'Sales &amp; onboarding'];
    }

    public static function gcal(object $b): string
    {
        $f = fn ($t) => Carbon::parse($t, 'UTC')->format('Ymd\THis\Z');
        return 'https://calendar.google.com/calendar/render?' . http_build_query([
            'action' => 'TEMPLATE', 'text' => 'KiddieTrac ' . strtolower(self::typeName($b)),
            'dates' => $f($b->starts_at) . '/' . $f($b->ends_at),
            'details' => $b->meet_via === 'phone' ? 'We will call you at ' . $b->phone . '.' : 'Join: ' . ($b->teams_url ?: 'link in your confirmation email'),
            'location' => $b->meet_via === 'phone' ? 'Phone call' : ($b->teams_url ?: 'Microsoft Teams'),
        ]);
    }

    /**
     * THE CALENDAR INVITATION (2026-10-23, Anthony: "allow for the calendar attachments to
     * support all mail platforms (gmail, outlook etc)"). An iTIP REQUEST - organiser sales@,
     * the visitor as attendee - is what Gmail, Outlook, Apple Mail and Yahoo turn into their
     * own "add to calendar / Yes · Maybe · No" card. The same UID with a higher SEQUENCE moves
     * it, and CANCEL takes it off their calendar. Lines folded at 75 octets (RFC 5545), CRLF.
     * $publish: the plain download version (no RSVP), for the "Apple / other" link.
     */
    public static function ics(object $b, bool $cancel = false, ?string $attendee = null, bool $publish = false): string
    {
        $fmt = fn ($t) => Carbon::parse($t, 'UTC')->format('Ymd\THis\Z');
        $esc = fn ($s) => str_replace(["\\", ';', ',', "\r\n", "\n"], ["\\\\", '\;', '\,', '\n', '\n'], (string) $s);
        $type = self::typeName($b);
        $loc = $b->meet_via === 'phone' ? 'Phone call to ' . $b->phone : ($b->teams_url ? 'Microsoft Teams Meeting' : 'Microsoft Teams');
        $desc = 'Topic: ' . $type . "\n" . 'Meeting: ' . self::whoLine($b) . "\n\n"
            . ($b->meet_via === 'phone' ? 'We will call you at ' . $b->phone . '.' : 'Join on Microsoft Teams: ' . ($b->teams_url ?: '(link in your email)'))
            . "\n\n" . 'To change the time, reply to the confirmation email or call 1-855-400-9996.';
        $email = $attendee ?: $b->email;
        $method = $publish ? 'PUBLISH' : ($cancel ? 'CANCEL' : 'REQUEST');
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//KiddieTrac//Website booking//EN', 'CALSCALE:GREGORIAN', 'METHOD:' . $method,
            'BEGIN:VEVENT', 'UID:booking-' . $b->id . '@kiddietrac.com', 'SEQUENCE:' . (int) ($b->sequence ?? 0), 'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            'DTSTART:' . $fmt($b->starts_at), 'DTEND:' . $fmt($b->ends_at), 'STATUS:' . ($cancel ? 'CANCELLED' : 'CONFIRMED'),
            'SUMMARY:' . $esc('KiddieTrac ' . strtolower($type)), 'DESCRIPTION:' . $esc($desc), 'LOCATION:' . $esc($loc),
            'TRANSP:OPAQUE', 'CLASS:PUBLIC'];
        if (! $publish) {
            $lines[] = 'ORGANIZER;CN=KiddieTrac Sales:mailto:' . self::SALES;
            $lines[] = 'ATTENDEE;CN="' . str_replace('"', '', (string) $b->name) . '";ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=' . ($cancel ? 'FALSE' : 'TRUE') . ':mailto:' . $email;
        }
        if (! $cancel && $b->meet_via !== 'phone' && $b->teams_url) $lines[] = 'URL:' . $b->teams_url;
        if (! $cancel) { $lines = array_merge($lines, ['BEGIN:VALARM', 'TRIGGER:-PT15M', 'ACTION:DISPLAY', 'DESCRIPTION:' . $esc('KiddieTrac ' . strtolower($type) . ' in 15 minutes'), 'END:VALARM']); }
        $lines = array_merge($lines, ['END:VEVENT', 'END:VCALENDAR']);
        // Fold every line at 75 octets without splitting a UTF-8 character.
        $fold = function (string $l): string {
            if (strlen($l) <= 75) return $l;
            $out = ''; $cur = ''; $max = 75;
            foreach (mb_str_split($l) as $ch) {
                if (strlen($cur) + strlen($ch) > $max) { $out .= $cur . "\r\n "; $cur = ''; $max = 74; }
                $cur .= $ch;
            }
            return $out . $cur;
        };
        return implode("\r\n", array_map($fold, $lines)) . "\r\n";
    }

    /** A signed link to download the meeting's .ics (Apple Calendar and anything else). */
    public static function icsUrl(object $b): string
    {
        $sig = substr(hash_hmac('sha256', 'booking-ics|' . (int) $b->id . '|' . (int) ($b->sequence ?? 0), (string) config('app.key')), 0, 24);
        return 'https://api.kiddietrac.com/api/v1/marketing-site/booking/' . (int) $b->id . '/calendar.ics?v=' . (int) ($b->sequence ?? 0) . '&s=' . $sig;
    }

    /** One-click "add to calendar" links for the web calendars. */
    public static function calLinks(object $b): array
    {
        $type = 'KiddieTrac ' . strtolower(self::typeName($b));
        $body = $b->meet_via === 'phone' ? 'We will call you at ' . $b->phone . '.' : 'Join on Microsoft Teams: ' . ($b->teams_url ?: 'link in your confirmation email');
        $loc = $b->meet_via === 'phone' ? 'Phone call' : ($b->teams_url ?: 'Microsoft Teams');
        $iso = fn ($t) => Carbon::parse($t, 'UTC')->format('Y-m-d\TH:i:s\Z');
        $ol = fn ($host) => 'https://' . $host . '/calendar/0/deeplink/compose?' . http_build_query(['path' => '/calendar/action/compose', 'rru' => 'addevent',
            'subject' => $type, 'startdt' => $iso($b->starts_at), 'enddt' => $iso($b->ends_at), 'body' => $body, 'location' => $loc]);
        // [label, url, logo, logo width, logo height, show label] — official logos, hosted PNGs
        // (mail apps strip SVG). Yahoo's mark is its wordmark, so it carries no label.
        $ico = 'https://www.kiddietrac.com/email-icons/cal-';
        return [
            ['Google Calendar', self::gcal($b), $ico . 'google.png', 18, 18, true],
            ['Outlook.com', $ol('outlook.live.com'), $ico . 'outlook.png', 19, 18, true],
            ['Office 365', $ol('outlook.office.com'), $ico . 'outlook.png', 19, 18, true],
            ['Yahoo Calendar', 'https://calendar.yahoo.com/?' . http_build_query(['v' => 60, 'title' => $type, 'st' => Carbon::parse($b->starts_at, 'UTC')->format('Ymd\THis\Z'),
                'et' => Carbon::parse($b->ends_at, 'UTC')->format('Ymd\THis\Z'), 'desc' => $body, 'in_loc' => $loc]), $ico . 'yahoo.png', 54, 15, false],
            ['Apple Calendar', self::icsUrl($b), $ico . 'apple.png', 18, 18, true],
        ];
    }

    /** The email to the visitor. Returns true when the mailer took it. */
    public static function visitor(object $b, string $kind, ?string $testTo = null, ?string $oldStartsAt = null, ?string $step = null): bool
    {
        // The run-up sequence (BookingRunUp): runup <film step> | tomorrow | today.
        $runUp = in_array($kind, ['runup', 'tomorrow', 'today'], true);
        if ($kind === 'runup' && ! isset(BookingRunUp::films()[(string) $step])) return false;
        [$host, $hostTitle] = self::host($b);
        $type = self::typeName($b);
        $d = [
            'first' => strtok(trim((string) $b->name), ' ') ?: '', 'typeName' => $type, 'minutes' => (int) $b->duration_min,
            'whenEt' => self::whenEt($b->starts_at), 'whenLocal' => self::whenLocal($b), 'viaPhone' => $b->meet_via === 'phone', 'phone' => (string) $b->phone,
            'teamsUrl' => $b->teams_url ?? null, 'agenda' => self::agenda($b), 'prepare' => self::prepare($b), 'host' => $host, 'hostTitle' => html_entity_decode($hostTitle),
            'note' => in_array($kind, ['confirmed', 'modified', 'declined', 'cancelled'], true) ? ($b->sales_note ?? null) : null,
            'reason' => in_array($kind, ['declined', 'cancelled'], true) ? ($b->decline_reason ?? null) : null,
            'oldWhenEt' => $oldStartsAt ? self::whenEt($oldStartsAt) : null, 'gcal' => self::gcal($b), 'book' => 'https://www.kiddietrac.com/?book=1',
            'calLinks' => in_array($kind, ['confirmed', 'modified', 'tomorrow'], true) ? self::calLinks($b) : [],
            'step' => $kind === 'runup' ? $step : null,
            'film' => $kind === 'runup' ? BookingRunUp::films()[$step] + ['url' => BookingRunUp::watchUrl($step), 'poster' => BookingRunUp::poster($step)] : null,
            // The day before: the films, so anything not watched yet is one tap away.
            'films' => $kind === 'tomorrow' ? collect(BookingRunUp::films())->filter(fn ($f, $k) => BookingRunUp::live($k))
                ->map(fn ($f, $k) => ['title' => $f['title'], 'url' => BookingRunUp::watchUrl($k), 'poster' => BookingRunUp::poster($k), 'length' => $f['length']])->values()->all() : [],
            'proposal' => self::$proposal ?? self::proposalFlow(),
            'intakeDone' => self::$intakeDoneOverride ?? (! empty($b->id) && DB::table('prospect_intakes')->where('booking_id', $b->id)->exists()),
            'intakeUrl' => \App\Http\Controllers\Api\ProspectIntakeController::url($b),
            // The value film, once approved: {"url","poster","length"} in sales.booking.value_video.
            'video' => self::$videoOverride ?? (json_decode((string) PlatformSettings::get('sales.booking.value_video', ''), true) ?: null),
        ];
        $subject = [
            'requested' => 'We received your request: KiddieTrac ' . strtolower($type) . ' — ' . Carbon::parse($b->starts_at, 'UTC')->tz(self::TZ)->format('M j, g:i A') . ' ET',
            'confirmed' => 'Confirmed: your KiddieTrac ' . strtolower($type) . ' — ' . Carbon::parse($b->starts_at, 'UTC')->tz(self::TZ)->format('D, M j \a\t g:i A') . ' ET',
            'modified'  => 'New time: your KiddieTrac ' . strtolower($type) . ' — ' . Carbon::parse($b->starts_at, 'UTC')->tz(self::TZ)->format('D, M j \a\t g:i A') . ' ET',
            'declined'  => 'About your KiddieTrac ' . strtolower($type) . ' request',
            'cancelled' => 'Cancelled: your KiddieTrac ' . strtolower($type),
            'intake'    => 'Before we meet: tell us about your program — ' . Carbon::parse($b->starts_at, 'UTC')->tz(self::TZ)->format('D, M j'),
            'runup'     => $kind === 'runup' ? BookingRunUp::films()[$step]['subject'] : '',
            'tomorrow'  => 'See you tomorrow: your KiddieTrac ' . strtolower($type) . ' — ' . Carbon::parse($b->starts_at, 'UTC')->tz(self::TZ)->format('D, M j \a\t g:i A') . ' ET',
            'today'     => 'Today at ' . Carbon::parse($b->starts_at, 'UTC')->tz(self::TZ)->format('g:i A') . ' ET: your KiddieTrac ' . strtolower($type),
        ][$kind];
        try {
            $html = view('emails.booking', ['kind' => $kind, 'd' => $d])->render();
            $ics = in_array($kind, ['confirmed', 'modified'], true) ? self::ics($b, false, $testTo) : ($kind === 'cancelled' ? self::ics($b, true, $testTo) : null);
            $plain = $runUp ? BookingRunUp::text($kind, $step, $d) : null;
            Mail::html($html, function ($m) use ($b, $subject, $ics, $testTo, $kind, $plain) {
                MailScope::platform($m);
                if ($plain !== null) $m->text($plain);
                $m->to($testTo ?: $b->email, $testTo ? null : $b->name)->subject(($testTo ? '[TEST] ' : '') . $subject)->replyTo(self::SALES, 'KiddieTrac Sales');
                $m->getHeaders()->addTextHeader('X-KT-Send-As', self::SALES);
                if ($ics) $m->attachData($ics, 'invite.ics', ['mime' => 'text/calendar; charset=UTF-8; method=' . ($kind === 'cancelled' ? 'CANCEL' : 'REQUEST')]);
            });
            return true;
        } catch (\Throwable $e) {
            Log::warning('booking visitor mail failed', ['booking' => $b->id ?? null, 'kind' => $kind, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /** Accept / Change / Decline buttons for the sales notice (BookingEmailActionController). */
    private static function actionButtons(object $b): string
    {
        $c = \App\Http\Controllers\Api\BookingEmailActionController::class;
        $btn = fn ($label, $url, $bg, $fg, $bd) => '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" style="display:inline-block;margin:0 8px 8px 0;padding:12px 20px;border-radius:10px;font-size:15px;font-weight:bold;text-decoration:none;background:' . $bg . ';color:' . $fg . ' !important;border:1px solid ' . $bd . '">' . $label . '</a>';
        return '<div>' . $btn('&#10003; Accept', empty($b->id) ? '#' : $c::link($b, 'accept'), '#047857', '#ffffff', '#047857')
            . $btn('&#9998; Change time', empty($b->id) ? '#' : $c::link($b, 'modify'), '#ffffff', '#0B1F3A', '#CBD5E1')
            . $btn('&#10005; Decline', empty($b->id) ? '#' : $c::link($b, 'decline'), '#ffffff', '#B91C1C', '#FCA5A5') . '</div>'
            . '<div style="font-size:12.5px;color:#64748B;margin-top:4px">Each button opens a KiddieTrac page — no sign-in — where you add a note and confirm. Nothing happens until you press the button there.</div>';
    }

    /** The "new meeting request" notice to sales@, with buttons into Sales → Bookings. */
    public static function salesRequest(object $b, ?string $testTo = null): bool
    {
        $type = self::typeName($b);
        $link = 'https://app.kiddietrac.com/dashboard.html#sales-bookings?id=' . (int) $b->id;
        try {
            $html = SalesMail::render([
                'preheader' => 'Review: ' . $type . ' with ' . $b->name . ' — ' . self::whenEt($b->starts_at),
                'eyebrow' => 'New meeting request — needs your decision', 'icon' => '📅',
                'title' => $b->name . ($b->agency ? ' · ' . $b->agency : ''),
                'subtitle' => $type . ' · ' . $b->duration_min . ' min · ' . self::whenEt($b->starts_at),
                'intro' => 'Nothing has been sent to them except "we received your request". Accept to send the confirmation with a Teams link, modify the time, or decline.',
                'sections' => [['heading' => 'Details', 'rows' => array_filter([
                    'When' => self::whenEt($b->starts_at) . (self::whenLocal($b) ? ' — ' . self::whenLocal($b) : ''),
                    'How' => $b->meet_via === 'phone' ? 'Phone call to ' . $b->phone : 'Video (Microsoft Teams)',
                    'Name' => $b->name, 'Email' => $b->email, 'Phone' => $b->phone ?: '—', 'Agency' => $b->agency ?: '—',
                    'Children' => $b->children ?: '—', 'Notes' => $b->notes ?: '—',
                ], fn ($v) => $v !== null)]],
                // Decide from the email, no sign-in (2026-10-23): signed links, confirmed on the page.
                'html_heading' => 'Decide from here',
                'html' => self::actionButtons($b),
                'button' => ['label' => 'Or open Sales → Bookings', 'url' => $link],
            ]);
            Mail::html($html, function ($m) use ($b, $type, $testTo) {
                MailScope::platform($m);
                $m->to($testTo ?: self::SALES)->subject(($testTo ? '[TEST] ' : '') . 'Meeting request: ' . $type . ' with ' . $b->name . ' — ' . Carbon::parse($b->starts_at, 'UTC')->tz(self::TZ)->format('M j, g:i A'))
                  ->replyTo($b->email, $b->name);
            });
            return true;
        } catch (\Throwable $e) {
            Log::warning('booking sales mail failed', ['booking' => $b->id ?? null, 'error' => $e->getMessage()]);
            return false;
        }
    }
}
