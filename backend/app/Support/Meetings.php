<?php

namespace App\Support;

use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * MEETINGS, LIKE OUTLOOK (2026-10-25, Anthony: "add a meeting in the calendar with adding
 * others, teams meeting etc (like how outlook does it)").
 *
 * The invitation every attendee receives: an email with the time in the meeting's zone, the join
 * link, the agenda, who else is coming, Accept / Maybe / Decline buttons (a signed link, no sign-in
 * needed) and an iTIP .ics so Outlook, Gmail and Apple add it with their own Yes / No card.
 * The same UID with a higher SEQUENCE moves it on their calendar; METHOD:CANCEL removes it.
 *
 * Platform meetings (the KiddieTrac team) get a Teams meeting made on the sales@ calendar through
 * Microsoft Graph (BookingTeams, the permission bookings already use) and send from sales@ with
 * replies to the organiser. Agency meetings send through the agency's own mailer and branding;
 * agencies have no Microsoft connection, so they paste a Teams / Zoom / Meet link.
 */
final class Meetings
{
    public const KINDS = ['staff', 'parent', 'lead', 'contact', 'guest'];
    public const RESPONSES = ['accepted' => 'Accepted', 'tentative' => 'Maybe', 'declined' => 'Declined', 'pending' => 'No reply yet'];

    public static function tzFor(object $m): string
    {
        return $m->timezone ?: ($m->scope === 'agency' ? AgencyTime::tz((int) $m->agency_id) : BookingMail::TZ);
    }

    public static function attendees(int $meetingId): array
    {
        return DB::table('meeting_attendees')->where('meeting_id', $meetingId)->orderBy('id')->get()->all();
    }

    /** The meeting as the portal reads it. */
    public static function shape(object $m, ?int $viewerId = null, bool $withPeople = true): array
    {
        $tz = self::tzFor($m);
        $s = Carbon::parse($m->starts_at, 'UTC')->tz($tz);
        $e = Carbon::parse($m->ends_at, 'UTC')->tz($tz);
        $org = DB::table('users')->where('id', $m->organizer_id)->first(['id', 'first_name', 'last_name', 'email']);
        $people = $withPeople ? self::attendees((int) $m->id) : [];
        $mine = null;
        foreach ($people as $p) { if ($viewerId && (int) $p->user_id === $viewerId) { $mine = $p->response; } }
        $counts = ['accepted' => 0, 'tentative' => 0, 'declined' => 0, 'pending' => 0];
        foreach ($people as $p) { $counts[$p->response] = ($counts[$p->response] ?? 0) + 1; }

        return [
            'id' => (int) $m->id, 'scope' => $m->scope, 'agency_id' => $m->agency_id ? (int) $m->agency_id : null, 'centre_id' => $m->centre_id ? (int) $m->centre_id : null,
            'title' => $m->title, 'description' => $m->description, 'location' => $m->location,
            'start' => Carbon::parse($m->starts_at, 'UTC')->toIso8601String(), 'end' => Carbon::parse($m->ends_at, 'UTC')->toIso8601String(),
            'date' => $s->toDateString(), 'end_date' => $e->toDateString(), 'start_time' => $s->format('H:i'), 'end_time' => $e->format('H:i'),
            'all_day' => (bool) $m->all_day, 'timezone' => $tz, 'when' => self::whenText($m),
            'video' => $m->video, 'join_url' => $m->join_url, 'status' => $m->status, 'cancel_reason' => $m->cancel_reason,
            'booking_id' => $m->booking_id ? (int) $m->booking_id : null, 'lead_id' => $m->lead_id ? (int) $m->lead_id : null,
            'organizer' => $org ? ['id' => (int) $org->id, 'name' => trim($org->first_name . ' ' . $org->last_name), 'email' => $org->email] : null,
            'is_organizer' => $viewerId && (int) $m->organizer_id === $viewerId, 'my_response' => $mine,
            'attendees' => array_map(fn ($p) => ['id' => (int) $p->id, 'user_id' => $p->user_id ? (int) $p->user_id : null, 'email' => $p->email, 'name' => $p->name,
                'kind' => $p->kind, 'optional' => (bool) $p->optional, 'response' => $p->response, 'responded_at' => $p->responded_at], $people),
            'counts' => $counts,
        ];
    }

    /** "Tue, Oct 28 · 2:00 – 3:00 PM EDT" in the meeting's own zone. */
    public static function whenText(object $m): string
    {
        $tz = self::tzFor($m);
        $s = Carbon::parse($m->starts_at, 'UTC')->tz($tz);
        $e = Carbon::parse($m->ends_at, 'UTC')->tz($tz);
        if ($m->all_day) {
            $last = $e->copy()->subDay();
            return $s->isSameDay($last) ? $s->format('D, M j, Y') . ' · all day' : $s->format('D, M j') . ' – ' . $last->format('D, M j, Y') . ' · all day';
        }
        $same = $s->isSameDay($e);
        return $s->format('D, M j, Y') . ' · ' . $s->format('g:i A') . ' – ' . ($same ? $e->format('g:i A') : $e->format('D, M j g:i A')) . ' ' . $s->format('T');
    }

    /* ── Teams on the sales@ calendar (platform meetings) ──────────────────────────── */

    private static function graphBody(object $m): array
    {
        $fmt = fn ($t) => Carbon::parse($t, 'UTC')->format('Y-m-d\TH:i:s');
        return [
            'subject' => $m->title,
            'body' => ['contentType' => 'HTML', 'content' => '<p>' . nl2br(e((string) $m->description)) . '</p><p>Organised in KiddieTrac (meeting #' . (int) $m->id . ').</p>'],
            'start' => ['dateTime' => $fmt($m->starts_at), 'timeZone' => 'UTC'],
            'end' => ['dateTime' => $fmt($m->ends_at), 'timeZone' => 'UTC'],
            'isAllDay' => (bool) $m->all_day,
            'isOnlineMeeting' => true, 'onlineMeetingProvider' => 'teamsForBusiness',
            'allowNewTimeProposals' => false, 'showAs' => 'busy',
            // No attendees on the Graph event: Outlook would send its own invitation on top of ours.
        ];
    }

    /** Make the Teams meeting. ['url' => …, 'event_id' => …] or ['error' => 'permission'|'failed']. */
    public static function teamsCreate(object $m): array
    {
        return BookingTeams::eventCreate(self::graphBody($m));
    }

    public static function teamsMove(object $m): void
    {
        if ($m->graph_event_id) {
            $b = self::graphBody($m);
            BookingTeams::eventPatch($m->graph_event_id, ['subject' => $b['subject'], 'start' => $b['start'], 'end' => $b['end'], 'isAllDay' => $b['isAllDay'], 'body' => $b['body']]);
        }
    }

    public static function teamsCancel(object $m): void
    {
        if ($m->graph_event_id) BookingTeams::eventDelete($m->graph_event_id);
    }

    /* ── The invitation ─────────────────────────────────────────────────────────────── */

    public static function rsvpUrl(object $a, string $r): string
    {
        $sig = substr(hash_hmac('sha256', 'meeting-rsvp|' . (int) $a->id . '|' . (int) $a->meeting_id, (string) config('app.key')), 0, 24);
        return 'https://api.kiddietrac.com/api/v1/meetings/rsvp/' . (int) $a->id . '/' . $r . '?s=' . $sig;
    }

    public static function rsvpValid(int $attendeeId, int $meetingId, string $sig): bool
    {
        return hash_equals(substr(hash_hmac('sha256', 'meeting-rsvp|' . $attendeeId . '|' . $meetingId, (string) config('app.key')), 0, 24), $sig);
    }

    public static function ics(object $m, string $method, array $people, ?object $org): string
    {
        $esc = fn ($s) => str_replace(["\\", ';', ',', "\r\n", "\n"], ["\\\\", '\;', '\,', '\n', '\n'], (string) $s);
        $tz = self::tzFor($m);
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//KiddieTrac//Meetings//EN', 'CALSCALE:GREGORIAN', 'METHOD:' . $method,
            'BEGIN:VEVENT', 'UID:meeting-' . (int) $m->id . '@kiddietrac.com', 'SEQUENCE:' . (int) $m->sequence, 'DTSTAMP:' . gmdate('Ymd\THis\Z')];
        if ($m->all_day) {
            $lines[] = 'DTSTART;VALUE=DATE:' . Carbon::parse($m->starts_at, 'UTC')->tz($tz)->format('Ymd');
            $lines[] = 'DTEND;VALUE=DATE:' . Carbon::parse($m->ends_at, 'UTC')->tz($tz)->format('Ymd');
        } else {
            $lines[] = 'DTSTART:' . Carbon::parse($m->starts_at, 'UTC')->format('Ymd\THis\Z');
            $lines[] = 'DTEND:' . Carbon::parse($m->ends_at, 'UTC')->format('Ymd\THis\Z');
        }
        $desc = trim((string) $m->description);
        if ($m->join_url) $desc = trim(($m->video === 'teams' ? 'Join the Microsoft Teams meeting: ' : 'Join the meeting: ') . $m->join_url . "\n\n" . $desc);
        $lines[] = 'STATUS:' . ($method === 'CANCEL' ? 'CANCELLED' : 'CONFIRMED');
        $lines[] = 'SUMMARY:' . $esc($m->title);
        if ($desc !== '') $lines[] = 'DESCRIPTION:' . $esc($desc);
        $loc = $m->location ?: ($m->join_url ? ($m->video === 'teams' ? 'Microsoft Teams Meeting' : $m->join_url) : '');
        if ($loc !== '') $lines[] = 'LOCATION:' . $esc($loc);
        if ($m->join_url && $method !== 'CANCEL') $lines[] = 'URL:' . $m->join_url;
        // First name only: no email shows a KiddieTrac person's last name (2026-10-26).
        if ($reply = self::replyAddress($m, $org)) $lines[] = 'ORGANIZER;CN="' . str_replace('"', '', trim((string) ($org->first_name ?? '')) ?: 'KiddieTrac') . '":mailto:' . $reply;
        $partstat = ['accepted' => 'ACCEPTED', 'tentative' => 'TENTATIVE', 'declined' => 'DECLINED'];
        foreach ($people as $p) {
            $lines[] = 'ATTENDEE;CN="' . str_replace('"', '', (string) ($p->name ?: $p->email)) . '";ROLE=' . ($p->optional ? 'OPT-PARTICIPANT' : 'REQ-PARTICIPANT')
                . ';PARTSTAT=' . ($partstat[$p->response] ?? 'NEEDS-ACTION')
                . ';RSVP=' . ($method === 'CANCEL' ? 'FALSE' : 'TRUE') . ':mailto:' . $p->email;
        }
        $lines[] = 'TRANSP:OPAQUE';
        if ($method !== 'CANCEL' && ! $m->all_day) $lines = array_merge($lines, ['BEGIN:VALARM', 'TRIGGER:-PT15M', 'ACTION:DISPLAY', 'DESCRIPTION:' . $esc($m->title . ' in 15 minutes'), 'END:VALARM']);
        $lines = array_merge($lines, ['END:VEVENT', 'END:VCALENDAR']);
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

    /**
     * Where attendees' answers go: the calendar card's Accept / Decline reply goes to the ORGANIZER, and an email reply to
     * Reply-To. A platform meeting is the KiddieTrac team's, held on the sales@ calendar (BookingTeams), so both are sales@
     * (2026-11-13, Anthony: "when bookings are sent and accepted etc why is it sending this to my gmail and not
     * sales@kiddietrac.com" - they named the organiser's own login, a personal Gmail). An agency meeting has no team
     * mailbox: the organiser's own address.
     */
    public static function replyAddress(object $m, ?object $org): ?string
    {
        if ($m->scope !== 'agency') return BookingMail::SALES;

        return $org && $org->email ? (string) $org->email : null;
    }

    /**
     * Send the invitation (kind 'invite' | 'update' | 'cancel') to these attendees, one email each.
     * Returns [sent, failed]. $only: attendee ids to send to (null = everyone on the meeting).
     * $gone: attendee rows already deleted (people taken off), added to the list so $only can reach them.
     */
    public static function send(object $m, string $kind, ?array $only = null, ?string $testTo = null, array $gone = []): array
    {
        $people = array_merge(self::attendees((int) $m->id), $gone);
        $org = DB::table('users')->where('id', $m->organizer_id)->first(['id', 'first_name', 'last_name', 'email']);
        $orgName = $org && trim((string) $org->first_name) !== '' ? trim($org->first_name) . ' from KiddieTrac' : 'KiddieTrac';   // first name only (2026-10-26)
        $method = $kind === 'cancel' ? 'CANCEL' : 'REQUEST';
        $ics = self::ics($m, $method, $people, $org);
        $agencyId = $m->scope === 'agency' ? (int) $m->agency_id : null;
        $agencyName = $agencyId ? (string) DB::table('agencies')->where('id', $agencyId)->value('name') : 'KiddieTrac';
        $sent = 0; $failed = 0;
        foreach ($people as $p) {
            if ($only !== null && ! in_array((int) $p->id, $only, true)) continue;
            if (! filter_var($p->email, FILTER_VALIDATE_EMAIL)) { $failed++; continue; }
            $subject = ['invite' => 'Invitation: ', 'update' => 'Updated: ', 'cancel' => 'Cancelled: '][$kind] . $m->title . ' — ' . self::whenText($m);
            $html = self::html($m, $kind, $p, $people, $orgName, $agencyId);
            try {
                $reply = self::replyAddress($m, $org);
                $build = function ($msg) use ($p, $subject, $ics, $method, $reply, $orgName, $testTo, $agencyId) {
                    if (! $agencyId) {
                        MailScope::platform($msg);
                        $msg->getHeaders()->addTextHeader('X-KT-Send-As', BookingMail::SALES);
                    }
                    $msg->to($testTo ?: $p->email, $testTo ? null : ($p->name ?: null))->subject(($testTo ? '[TEST] ' : '') . $subject);
                    if ($reply) $msg->replyTo($reply, $orgName);
                    $msg->attachData($ics, 'invite.ics', ['mime' => 'text/calendar; charset=UTF-8; method=' . $method]);
                };
                if ($agencyId) AgencyMailer::forAgency($agencyId)->html($html, $build);
                else Mail::html($html, $build);
                if (! in_array($p, $gone, true)) DB::table('meeting_attendees')->where('id', $p->id)->update(['invited_at' => now(), 'updated_at' => now()]);
                $sent++;
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('meeting invitation failed', ['meeting' => $m->id, 'attendee' => $p->id, 'error' => $e->getMessage()]);
            }
        }
        return [$sent, $failed];
    }

    private static function html(object $m, string $kind, object $p, array $people, string $orgName, ?int $agencyId): string
    {
        $navy = '#0B1F3A'; $teal = '#1F6080'; $muted = '#64748B';
        $first = trim((string) strtok((string) ($p->name ?: ''), ' '));
        $lead = [
            'invite' => e($orgName) . ' has invited you to a meeting.',
            'update' => e($orgName) . ' has changed this meeting. The details below are the new ones.',
            'cancel' => e($orgName) . ' has cancelled this meeting.' . ($m->cancel_reason ? ' ' . e($m->cancel_reason) : ''),
        ][$kind];
        $row = fn ($k, $v) => '<tr><td style="padding:6px 0;width:92px;vertical-align:top;font-size:13px;font-weight:700;color:' . $muted . '">' . $k . '</td><td style="padding:6px 0;font-size:14.5px;color:#0F172A;line-height:1.5">' . $v . '</td></tr>';
        $rows = $row('When', e(self::whenText($m)));
        if ($m->location) $rows .= $row('Where', e($m->location));
        if ($m->join_url && $kind !== 'cancel') $rows .= $row('Online', $m->video === 'teams' ? 'Microsoft Teams' : e(parse_url($m->join_url, PHP_URL_HOST) ?: 'Video call'));
        $rows .= $row('Organiser', e($orgName));
        $names = array_map(fn ($x) => e($x->name ?: $x->email) . ($x->optional ? ' <span style="color:' . $muted . '">(optional)</span>' : ''), $people);
        $rows .= $row('Invited', implode(', ', $names));

        $body = '<p style="margin:0 0 10px;font-size:15px;color:#334155">' . ($first ? 'Hi ' . e($first) . ',' : 'Hello,') . '</p>'
            . '<p style="margin:0 0 16px;font-size:15px;color:#334155;line-height:1.6">' . $lead . '</p>'
            . '<div style="font-size:21px;font-weight:800;color:' . $navy . ';margin:0 0 6px;' . ($kind === 'cancel' ? 'text-decoration:line-through;' : '') . '">' . e($m->title) . '</div>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;margin:6px 0 12px">' . $rows . '</table>';
        if ($m->join_url && $kind !== 'cancel') {
            $body .= '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 14px"><tr><td bgcolor="#4F52B2" style="border-radius:8px;background:' . ($m->video === 'teams' ? '#4F52B2' : $teal) . '">'
                . '<a href="' . e($m->join_url) . '" style="display:inline-block;padding:12px 22px;font-size:15px;font-weight:bold;color:#ffffff;text-decoration:none;border-radius:8px">'
                . ($m->video === 'teams' ? 'Join the Microsoft Teams meeting' : 'Join the meeting') . ' &rarr;</a></td></tr></table>';
        }
        if (trim((string) $m->description) !== '') {
            $body .= '<div style="--kt-edge:#94A3B8;padding:4px 14px;margin:6px 0 16px;font-size:14px;line-height:1.6">' . nl2br(e($m->description)) . '</div>';
        }
        if ($kind !== 'cancel') {
            $btn = fn ($label, $r, $bg, $fg, $bd) => '<td style="padding:0 8px 0 0"><a href="' . e(self::rsvpUrl($p, $r)) . '" style="display:inline-block;padding:9px 16px;font-size:14px;font-weight:bold;color:' . $fg . ';background:' . $bg . ';border:1px solid ' . $bd . ';border-radius:8px;text-decoration:none">' . $label . '</a></td>';
            $body .= '<div style="font-size:13px;font-weight:700;color:' . $muted . ';margin:4px 0 8px">Will you attend?</div>'
                . '<table role="presentation" cellpadding="0" cellspacing="0"><tr>' . $btn('✓ Accept', 'accepted', '#16A34A', '#ffffff', '#16A34A') . $btn('? Maybe', 'tentative', '#B45309', '#ffffff', '#B45309') . $btn('✕ Decline', 'declined', '#B91C1C', '#ffffff', '#B91C1C') . '</tr></table>'
                . '<p style="margin:14px 0 0;font-size:12.5px;color:' . $muted . ';line-height:1.55">The calendar invitation is attached &mdash; open it to add this meeting to Outlook, Gmail or Apple Calendar.</p>';
        } else {
            $body .= '<p style="margin:10px 0 0;font-size:12.5px;color:' . $muted . '">Opening the attachment removes it from your calendar.</p>';
        }

        return EmailTemplate::wrap($agencyId, $body, [
            'title' => ['invite' => 'Meeting invitation', 'update' => 'Meeting updated', 'cancel' => 'Meeting cancelled'][$kind],
            'preheader' => self::whenText($m) . ($m->join_url && $kind !== 'cancel' ? ' · online' : ''),
        ]);
    }
}
