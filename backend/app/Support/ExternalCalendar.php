<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * OUTLOOK / GOOGLE CALENDARS THROUGH THE MAIL CLIENT (2026-11-25, Anthony: "make it so the calendar is connected to
 * the email that will be setup via the mail client for customers as well (optional)"; two-way, a person's own AND the
 * agency's, opt-in with a Connect calendar button).
 *
 * THE SIGN-IN IS THE MAILBOX'S. A calendar is switched on for a mailbox already connected in Email (email_accounts,
 * Sign in with Microsoft / Google). "Connect calendar" runs that same sign-in again asking for the calendar too
 * (MicrosoftMailbox / GoogleMailbox authorizeUrl(..., cal)), and the grant is recorded as 'cal' in oauth_token the way
 * 'ms' and 'snd' are, so a refresh asks for it only when it was given. Nothing changes for anyone who never clicks.
 *
 *   personal  scope personal: only its owner sees its events (panel), and the meetings they organise are written
 *             to it.
 *   agency    scope agency, one per agency, set by an agency admin: the agency's admins and directors see its events;
 *             a private event shows as "Busy". The agency's meetings are written to it as a copy (no invitations).
 *
 * WRITING (push): a meeting organised in KiddieTrac goes on the organiser's own calendar.
 *   - agency meeting: WITH its attendees. Microsoft / Google send the invitations from the organiser's own mailbox, so
 *     KiddieTrac does not email them as well (SendMeetingMail skips the ids push() returns). Replies go to the
 *     organiser, the same as Meetings::replyAddress for an agency meeting; the answers are read back by
 *     kt:calendar-responses.
 *   - platform meeting: a copy WITHOUT attendees. Its invitations stay KiddieTrac's from sales@ (replyAddress), so the
 *     KiddieTrac team's answers keep going to sales@ (2026-11-13 rule).
 *   If the calendar write fails, KiddieTrac's own invitation goes out as before.
 *
 * NEVER TWICE: events KiddieTrac wrote (and the attendee copies of them, same iCalUId) are left out of the import - the
 * meeting already shows from the meetings table. Test agencies write nothing (TestAgency::skips).
 */
final class ExternalCalendar
{
    public const MS_SCOPE = 'Calendars.ReadWrite Calendars.ReadWrite.Shared';
    public const GOOGLE_SCOPE = 'https://www.googleapis.com/auth/calendar.events';
    private const GCAL = 'https://www.googleapis.com/calendar/v3/calendars/primary';
    private const READ_TTL = 300;      // an event list is read again after 5 minutes
    public const MAX_DAYS = 62;

    /* ── who has what ─────────────────────────────────────────────────────────────── */

    /** Was this mailbox signed in with the calendar permission? */
    public static function granted(object $acct): bool
    {
        if (! in_array($acct->auth_type ?? '', ['microsoft', 'google'], true)) return false;
        try { return ! empty((json_decode(Crypt::decryptString((string) $acct->oauth_token), true) ?: [])['cal']); } catch (\Throwable $e) { return false; }
    }

    public static function personal(int $userId): ?object
    {
        return DB::table('calendar_links')->where('scope', 'personal')->where('user_id', $userId)->whereNull('deleted_at')->orderByDesc('id')->first();
    }

    public static function agency(?int $agencyId): ?object
    {
        if (! $agencyId) return null;
        return DB::table('calendar_links')->where('scope', 'agency')->where('agency_id', $agencyId)->whereNull('deleted_at')->orderByDesc('id')->first();
    }

    public static function account(object $link): ?object
    {
        $a = DB::table('email_accounts')->where('id', $link->email_account_id)->whereNull('deleted_at')->first();
        return ($a && self::granted($a)) ? $a : null;
    }

    /** The link as Settings and the panel read it (no tokens). */
    public static function present(?object $link): ?array
    {
        if (! $link) return null;
        $a = DB::table('email_accounts')->where('id', $link->email_account_id)->whereNull('deleted_at')->first();
        $ok = $a && self::granted($a) && empty($a->auth_failed_at);
        return [
            'id' => (int) $link->id, 'scope' => $link->scope, 'provider' => $link->provider,
            'address' => $link->address ?: ($a->email_address ?? null),
            'status' => $ok ? ($link->status === 'error' ? 'error' : 'connected') : 'reconnect',
            'detail' => $ok ? ($link->status === 'error' ? $link->status_detail : null)
                : ($a ? 'The mailbox needs to be signed in again with the calendar allowed.' : 'The mailbox was removed from Email.'),
            'account_id' => (int) $link->email_account_id,
            'connected_at' => $link->created_at, 'last_read_at' => $link->last_read_at,
        ];
    }

    /* ── reading ──────────────────────────────────────────────────────────────────── */

    /**
     * Events on this calendar between two UTC instants, shaped for the panel. $busyOnly: private events become "Busy"
     * (the agency calendar). Cached per calendar and range for READ_TTL; one reader at a time per calendar.
     * Returns ['events' => [...], 'ok' => bool, 'message' => ?string].
     */
    public static function events(object $link, Carbon $from, Carbon $to, bool $busyOnly = false): array
    {
        $acct = self::account($link);
        if (! $acct) return ['events' => [], 'ok' => false, 'message' => 'Reconnect this calendar.'];
        $key = 'kt_xcal:' . $link->id . ':' . (int) Cache::get('kt_xcal_gen:' . $link->id, 0) . ':' . $from->timestamp . ':' . $to->timestamp;
        $raw = Cache::get($key);
        if ($raw === null) {
            $lock = Cache::lock('kt_xcal_lock:' . $link->id, 30);
            if (! $lock->get()) {
                // Someone is reading it right now: the last good copy for any range, else nothing this time.
                $raw = Cache::get('kt_xcal_last:' . $link->id);
                if ($raw === null) return ['events' => [], 'ok' => true, 'message' => null, 'pending' => true];
            } else {
                try {
                    $raw = $link->provider === 'google' ? self::readGoogle($acct, $from, $to) : self::readMicrosoft($acct, $from, $to);
                    Cache::put($key, $raw, self::READ_TTL);
                    Cache::put('kt_xcal_last:' . $link->id, $raw, 3600);
                    DB::table('calendar_links')->where('id', $link->id)->update(['last_read_at' => now(), 'status' => 'connected', 'status_detail' => null, 'updated_at' => now()]);
                } catch (\Throwable $e) {
                    $msg = mb_substr($e->getMessage(), 0, 480);
                    DB::table('calendar_links')->where('id', $link->id)->update(['status' => 'error', 'status_detail' => $msg, 'last_error_at' => now(), 'updated_at' => now()]);
                    Log::info('calendar read failed', ['link' => $link->id, 'error' => $msg]);
                    return ['events' => [], 'ok' => false, 'message' => $msg];
                } finally {
                    $lock->release();
                }
            }
        }
        $ours = self::ourUids();
        $out = [];
        foreach ($raw as $e) {
            if (($e['ical_uid'] && isset($ours[$e['ical_uid']])) || ! empty($e['kt'])) continue;   // KiddieTrac's own meeting
            if ($e['cancelled']) continue;
            if ($busyOnly && $e['private']) {
                $e['title'] = 'Busy'; $e['location'] = null; $e['join_url'] = null; $e['web_link'] = null; $e['organizer'] = null;
            }
            $e['source'] = $link->scope;
            $e['provider'] = $link->provider;
            unset($e['ical_uid'], $e['kt'], $e['cancelled']);
            $out[] = $e;
        }
        return ['events' => $out, 'ok' => true, 'message' => null];
    }

    /** iCalUIds of every meeting KiddieTrac wrote out (their invitation copies carry the same one). */
    private static function ourUids(): array
    {
        return Cache::remember('kt_xcal_ours', 60, fn () => DB::table('calendar_event_links')->whereNotNull('ical_uid')
            ->where('created_at', '>', now()->subDays(400))->pluck('ical_uid')->flip()->map(fn () => 1)->all());
    }

    private static function readMicrosoft(object $acct, Carbon $from, Carbon $to): array
    {
        $out = [];
        $q = ['startDateTime' => $from->copy()->utc()->format('Y-m-d\TH:i:s\Z'), 'endDateTime' => $to->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            '$top' => 250, '$orderby' => 'start/dateTime',
            '$select' => 'id,iCalUId,subject,start,end,isAllDay,location,onlineMeeting,isOnlineMeeting,webLink,sensitivity,showAs,isCancelled,organizer,type,seriesMasterId,responseStatus,body,bodyPreview'];
        $r = MicrosoftMailbox::call($acct, 'GET', '/calendarView', $q)->json();
        foreach ((array) ($r['value'] ?? []) as $e) {
            // calendarView answers in UTC unless asked otherwise; all-day events are midnight-to-midnight in the
            // calendar's own zone and are read as dates.
            $allDay = ! empty($e['isAllDay']);
            $s = (string) ($e['start']['dateTime'] ?? ''); $en = (string) ($e['end']['dateTime'] ?? '');
            if ($s === '' || $en === '') continue;
            $preview = (string) ($e['bodyPreview'] ?? '');
            $out[] = [
                'id' => 'ms:' . substr(sha1((string) $e['id']), 0, 20),
                'ical_uid' => (string) ($e['iCalUId'] ?? '') ?: null,
                'kt' => str_contains($preview, 'Organised in KiddieTrac (meeting #'),
                'title' => (string) ($e['subject'] ?? '') ?: '(No title)',
                'start' => $allDay ? substr($s, 0, 10) : Carbon::parse($s, 'UTC')->toIso8601String(),
                'end' => $allDay ? substr($en, 0, 10) : Carbon::parse($en, 'UTC')->toIso8601String(),
                'all_day' => $allDay,
                'location' => (string) ($e['location']['displayName'] ?? '') ?: null,
                'join_url' => $e['onlineMeeting']['joinUrl'] ?? null,
                'web_link' => $e['webLink'] ?? null,
                'private' => in_array($e['sensitivity'] ?? 'normal', ['private', 'confidential'], true),
                'tentative' => in_array($e['showAs'] ?? '', ['tentative'], true) || in_array($e['responseStatus']['response'] ?? '', ['notResponded', 'tentativelyAccepted'], true),
                'free' => ($e['showAs'] ?? '') === 'free',
                'recurring' => ($e['type'] ?? '') === 'occurrence' || ! empty($e['seriesMasterId']),
                'organizer' => (string) ($e['organizer']['emailAddress']['name'] ?? '') ?: null,
                'cancelled' => ! empty($e['isCancelled']),
            ];
        }
        return $out;
    }

    private static function readGoogle(object $acct, Carbon $from, Carbon $to): array
    {
        $out = [];
        $r = GoogleMailbox::callUrl($acct, 'GET', self::GCAL . '/events', [
            'timeMin' => $from->copy()->utc()->format('Y-m-d\TH:i:s\Z'), 'timeMax' => $to->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'singleEvents' => 'true', 'orderBy' => 'startTime', 'maxResults' => 250,
        ])->json();
        foreach ((array) ($r['items'] ?? []) as $e) {
            $allDay = isset($e['start']['date']);
            $s = $allDay ? (string) $e['start']['date'] : (string) ($e['start']['dateTime'] ?? '');
            $en = $allDay ? (string) ($e['end']['date'] ?? '') : (string) ($e['end']['dateTime'] ?? '');
            if ($s === '' || $en === '') continue;
            $me = null;
            foreach ((array) ($e['attendees'] ?? []) as $at) { if (! empty($at['self'])) $me = $at['responseStatus'] ?? null; }
            $join = $e['hangoutLink'] ?? null;
            foreach ((array) ($e['conferenceData']['entryPoints'] ?? []) as $ep) { if (($ep['entryPointType'] ?? '') === 'video') { $join = $ep['uri'] ?? $join; break; } }
            $out[] = [
                'id' => 'g:' . substr(sha1((string) $e['id']), 0, 20),
                'ical_uid' => (string) ($e['iCalUID'] ?? '') ?: null,
                'kt' => ! empty($e['extendedProperties']['private']['kt_meeting']) || ! empty($e['extendedProperties']['shared']['kt_meeting']),
                'title' => (string) ($e['summary'] ?? '') ?: '(No title)',
                'start' => $allDay ? $s : Carbon::parse($s)->utc()->toIso8601String(),
                'end' => $allDay ? $en : Carbon::parse($en)->utc()->toIso8601String(),
                'all_day' => $allDay,
                'location' => (string) ($e['location'] ?? '') ?: null,
                'join_url' => $join,
                'web_link' => $e['htmlLink'] ?? null,
                'private' => in_array($e['visibility'] ?? 'default', ['private', 'confidential'], true),
                'tentative' => in_array($me, ['needsAction', 'tentative'], true),
                'free' => ($e['transparency'] ?? '') === 'transparent',
                'recurring' => ! empty($e['recurringEventId']),
                'organizer' => (string) ($e['organizer']['displayName'] ?? '') ?: null,
                'cancelled' => ($e['status'] ?? '') === 'cancelled',
            ];
        }
        return $out;
    }

    /** Forget the cached event lists of a calendar (after a write, a connect or a disconnect). */
    public static function forget(int $linkId): void
    {
        Cache::forget('kt_xcal_last:' . $linkId);
        Cache::put('kt_xcal_gen:' . $linkId, (int) Cache::get('kt_xcal_gen:' . $linkId, 0) + 1, 86400);
        Cache::forget('kt_xcal_ours');
    }

    /* ── writing ──────────────────────────────────────────────────────────────────── */

    /**
     * Put a meeting on the calendars it belongs on: the organiser's own (with attendees for an agency meeting) and,
     * for an agency meeting, the agency's (a copy). $kind create|update|cancel. Returns the attendee ids whose
     * invitation / update / cancellation the provider sent - SendMeetingMail does not email those. $invite false (the
     * organiser unticked "Email the invitation"): the calendar gets the meeting but nobody is added or told by it.
     */
    public static function push(object $m, string $kind, bool $invite = true): array
    {
        $handled = [];
        $agencyId = $m->scope === 'agency' ? (int) $m->agency_id : null;
        if ($agencyId && TestAgency::skips($agencyId, 'calendar write', ['meeting' => (int) $m->id])) return [];
        $targets = [];
        if ($own = self::personal((int) $m->organizer_id)) $targets[] = [$own, $m->scope === 'agency'];
        if ($agencyId && ($ag = self::agency($agencyId)) && (! $own || (int) $ag->email_account_id !== (int) $own->email_account_id)) $targets[] = [$ag, false];
        foreach ($targets as [$link, $withPeople]) {
            try {
                $ids = self::pushOne($m, $link, $kind, $withPeople, $invite);
                if ($withPeople) $handled = array_merge($handled, $ids);
            } catch (\Throwable $e) {
                $msg = mb_substr($e->getMessage(), 0, 480);
                DB::table('calendar_links')->where('id', $link->id)->update(['status' => 'error', 'status_detail' => $msg, 'last_error_at' => now(), 'updated_at' => now()]);
                Log::warning('calendar write failed', ['meeting' => $m->id, 'link' => $link->id, 'kind' => $kind, 'error' => $msg]);
            }
        }
        return array_values(array_unique($handled));
    }

    /** One calendar. Returns the attendee ids the provider told (only when $withPeople). */
    private static function pushOne(object $m, object $link, string $kind, bool $withPeople, bool $invite = true): array
    {
        $acct = self::account($link);
        if (! $acct) return [];
        $el = DB::table('calendar_event_links')->where('meeting_id', $m->id)->where('calendar_link_id', $link->id)->first();
        // Not emailing this time: an event that already carries its attendees keeps them untouched.
        $keepPeople = ! $invite && $el && $el->with_attendees;
        $people = $withPeople && $invite ? array_values(array_filter(Meetings::attendees((int) $m->id), fn ($p) => filter_var($p->email, FILTER_VALIDATE_EMAIL)
            && strtolower($p->email) !== strtolower((string) $acct->email_address))) : [];
        $google = $link->provider === 'google';
        $did = false;

        if ($kind === 'cancel' || $m->status !== 'scheduled') {
            if (! $el) return [];
            if ($google) {
                try { GoogleMailbox::callUrl($acct, 'DELETE', self::GCAL . '/events/' . rawurlencode($el->external_id), ['sendUpdates' => $el->with_attendees ? 'all' : 'none']); }
                catch (\RuntimeException $e) { if (! in_array($e->getCode(), [404, 410], true)) throw $e; }
            } else {
                try {
                    if ($el->with_attendees) MicrosoftMailbox::call($acct, 'POST', '/events/' . rawurlencode($el->external_id) . '/cancel', [], ['comment' => (string) ($m->cancel_reason ?: '')]);
                    else MicrosoftMailbox::call($acct, 'DELETE', '/events/' . rawurlencode($el->external_id));
                } catch (\RuntimeException $e) { if ($e->getCode() !== 404) throw $e; }
            }
            $told = $el->with_attendees ? DB::table('meeting_attendees')->where('meeting_id', $m->id)->pluck('id')->map(fn ($x) => (int) $x)->all() : [];
            DB::table('calendar_event_links')->where('id', $el->id)->delete();
            self::forget((int) $link->id);
            self::audit($m, $link, 'calendar.event_removed', 'Removed "' . $m->title . '" from ' . ($link->address ?: 'a connected calendar'));
            return $told;
        }

        $body = $google ? self::googleBody($m, $people) : self::graphBody($m, $people);
        if ($keepPeople) unset($body['attendees']);
        if ($el) {
            if ($google) {
                $r = GoogleMailbox::callUrl($acct, 'PATCH', self::GCAL . '/events/' . rawurlencode($el->external_id),
                    ['sendUpdates' => $people ? 'all' : 'none', 'conferenceDataVersion' => 1], $body)->json();
            } else {
                $r = MicrosoftMailbox::call($acct, 'PATCH', '/events/' . rawurlencode($el->external_id), [], $body)->json();
            }
            $did = true;
        } else {
            if ($google) {
                $r = GoogleMailbox::callUrl($acct, 'POST', self::GCAL . '/events', ['sendUpdates' => $people ? 'all' : 'none', 'conferenceDataVersion' => 1], $body)->json();
            } else {
                $r = MicrosoftMailbox::call($acct, 'POST', '/events', [], $body)->json();
            }
            $did = true;
        }
        if (! $did || empty($r['id'])) return [];
        $row = ['external_id' => (string) $r['id'], 'ical_uid' => (string) ($r['iCalUId'] ?? $r['iCalUID'] ?? '') ?: null,
            'etag' => (string) ($r['@odata.etag'] ?? $r['etag'] ?? '') ?: null, 'with_attendees' => ($people || $keepPeople) ? 1 : 0, 'updated_at' => now()];
        if ($el) DB::table('calendar_event_links')->where('id', $el->id)->update($row);
        else DB::table('calendar_event_links')->insert($row + ['meeting_id' => $m->id, 'calendar_link_id' => $link->id, 'created_at' => now()]);
        self::forget((int) $link->id);

        // The video link the provider made (Teams / Meet) becomes the meeting's join link.
        if (! $m->join_url && in_array($m->video, ['teams', 'meet'], true)) {
            $join = $google ? (($r['hangoutLink'] ?? null) ?: null) : ($r['onlineMeeting']['joinUrl'] ?? null);
            if ($join) DB::table('meetings')->where('id', $m->id)->update(['join_url' => $join, 'updated_at' => now()]);
        }
        self::audit($m, $link, $el ? 'calendar.event_updated' : 'calendar.event_created',
            ($el ? 'Updated' : 'Added') . ' "' . $m->title . '" on ' . ($link->address ?: 'a connected calendar') . ($people ? ' with ' . count($people) . ' invited by ' . ($google ? 'Google' : 'Microsoft') : ''));
        return array_map(fn ($p) => (int) $p->id, $people);
    }

    private static function graphBody(object $m, array $people): array
    {
        $fmt = fn ($t) => Carbon::parse($t, 'UTC')->format('Y-m-d\TH:i:s');
        $tz = Meetings::tzFor($m);
        $b = [
            'subject' => $m->title,
            'body' => ['contentType' => 'HTML', 'content' => '<p>' . nl2br(e((string) $m->description)) . '</p>'
                . ($m->join_url ? '<p><a href="' . e($m->join_url) . '">Join the meeting</a></p>' : '')
                . '<p style="color:#64748B">Organised in KiddieTrac (meeting #' . (int) $m->id . ').</p>'],
            'isAllDay' => (bool) $m->all_day,
            'location' => ['displayName' => (string) ($m->location ?: ($m->join_url && $m->video !== 'teams' ? $m->join_url : ''))],
            'showAs' => 'busy', 'allowNewTimeProposals' => false,
            'attendees' => array_map(fn ($p) => ['emailAddress' => ['address' => $p->email, 'name' => $p->name ?: $p->email], 'type' => $p->optional ? 'optional' : 'required'], $people),
        ];
        if ($m->all_day) {
            // All-day: midnight to midnight in the meeting's own zone, said in that zone.
            $b['start'] = ['dateTime' => Carbon::parse($m->starts_at, 'UTC')->tz($tz)->format('Y-m-d\T00:00:00'), 'timeZone' => self::windowsTz($tz)];
            $b['end'] = ['dateTime' => Carbon::parse($m->ends_at, 'UTC')->tz($tz)->format('Y-m-d\T00:00:00'), 'timeZone' => self::windowsTz($tz)];
        } else {
            $b['start'] = ['dateTime' => $fmt($m->starts_at), 'timeZone' => 'UTC'];
            $b['end'] = ['dateTime' => $fmt($m->ends_at), 'timeZone' => 'UTC'];
        }
        if ($m->scope === 'agency' && $m->video === 'teams' && ! $m->join_url) { $b['isOnlineMeeting'] = true; $b['onlineMeetingProvider'] = 'teamsForBusiness'; }
        return $b;
    }

    private static function googleBody(object $m, array $people): array
    {
        $tz = Meetings::tzFor($m);
        $desc = trim((string) $m->description);
        if ($m->join_url) $desc = trim('Join the meeting: ' . $m->join_url . "\n\n" . $desc);
        $b = [
            'summary' => $m->title,
            'description' => trim($desc . "\n\nOrganised in KiddieTrac (meeting #" . (int) $m->id . ').'),
            'location' => (string) ($m->location ?: ''),
            'attendees' => array_map(fn ($p) => ['email' => $p->email, 'displayName' => $p->name ?: null, 'optional' => (bool) $p->optional], $people),
            'extendedProperties' => ['private' => ['kt_meeting' => (string) (int) $m->id]],
            'guestsCanModify' => false,
        ];
        if ($m->all_day) {
            $b['start'] = ['date' => Carbon::parse($m->starts_at, 'UTC')->tz($tz)->toDateString()];
            $b['end'] = ['date' => Carbon::parse($m->ends_at, 'UTC')->tz($tz)->toDateString()];
        } else {
            $b['start'] = ['dateTime' => Carbon::parse($m->starts_at, 'UTC')->toIso8601ZuluString(), 'timeZone' => $tz];
            $b['end'] = ['dateTime' => Carbon::parse($m->ends_at, 'UTC')->toIso8601ZuluString(), 'timeZone' => $tz];
        }
        if ($m->scope === 'agency' && $m->video === 'meet' && ! $m->join_url) {
            $b['conferenceData'] = ['createRequest' => ['requestId' => 'kt-m' . (int) $m->id . '-' . (int) $m->sequence, 'conferenceSolutionKey' => ['type' => 'hangoutsMeet']]];
        }
        return $b;
    }

    /** Graph wants a Windows zone name for an all-day event; the few the platform's agencies use, else UTC. */
    private static function windowsTz(string $iana): string
    {
        return [
            'America/Toronto' => 'Eastern Standard Time', 'America/New_York' => 'Eastern Standard Time', 'America/Montreal' => 'Eastern Standard Time',
            'America/Winnipeg' => 'Central Standard Time', 'America/Chicago' => 'Central Standard Time', 'America/Regina' => 'Canada Central Standard Time',
            'America/Edmonton' => 'Mountain Standard Time', 'America/Denver' => 'Mountain Standard Time', 'America/Vancouver' => 'Pacific Standard Time',
            'America/Los_Angeles' => 'Pacific Standard Time', 'America/Halifax' => 'Atlantic Standard Time', 'America/St_Johns' => 'Newfoundland Standard Time',
        ][$iana] ?? 'UTC';
    }

    /* ── answers read back ────────────────────────────────────────────────────────── */

    /**
     * The attendees' answers on meetings the provider invited for (kt:calendar-responses). Writes changes into
     * meeting_attendees; the organiser already gets each answer in their own mailbox, so no bell. Returns how many
     * answers changed.
     */
    public static function readResponses(int $limit = 40): int
    {
        $rows = DB::table('calendar_event_links as l')->join('meetings as m', 'm.id', '=', 'l.meeting_id')
            ->where('l.with_attendees', 1)->where('m.status', 'scheduled')->where('m.ends_at', '>', now()->subDay())
            ->orderByRaw('l.responses_read_at IS NOT NULL, l.responses_read_at')
            ->limit($limit)->get(['l.*', 'm.agency_id as m_agency', 'm.scope as m_scope']);
        $changed = 0;
        $map = ['accepted' => 'accepted', 'tentativelyAccepted' => 'tentative', 'declined' => 'declined', 'tentative' => 'tentative'];
        foreach ($rows as $l) {
            $link = DB::table('calendar_links')->where('id', $l->calendar_link_id)->whereNull('deleted_at')->first();
            $acct = $link ? self::account($link) : null;
            DB::table('calendar_event_links')->where('id', $l->id)->update(['responses_read_at' => now()]);
            if (! $acct) continue;
            try {
                if ($link->provider === 'google') {
                    $e = GoogleMailbox::callUrl($acct, 'GET', self::GCAL . '/events/' . rawurlencode($l->external_id))->json();
                    $list = array_map(fn ($a) => [strtolower((string) ($a['email'] ?? '')), (string) ($a['responseStatus'] ?? '')], (array) ($e['attendees'] ?? []));
                } else {
                    $e = MicrosoftMailbox::call($acct, 'GET', '/events/' . rawurlencode($l->external_id), ['$select' => 'attendees'])->json();
                    $list = array_map(fn ($a) => [strtolower((string) ($a['emailAddress']['address'] ?? '')), (string) ($a['status']['response'] ?? '')], (array) ($e['attendees'] ?? []));
                }
            } catch (\Throwable $ex) {
                continue;
            }
            foreach ($list as [$email, $resp]) {
                $to = $map[$resp] ?? null;
                if (! $to || $email === '') continue;
                $changed += DB::table('meeting_attendees')->where('meeting_id', $l->meeting_id)->whereRaw('LOWER(email) = ?', [$email])
                    ->where('response', '!=', $to)->update(['response' => $to, 'responded_at' => now(), 'updated_at' => now()]);
            }
        }
        return $changed;
    }

    private static function audit(object $m, object $link, string $action, string $summary): void
    {
        try {
            Audit::write(['user_id' => (int) $m->organizer_id, 'agency_id' => $m->agency_id, 'action' => $action, 'entity_type' => 'meeting', 'entity_id' => (int) $m->id,
                'payload' => json_encode(['summary' => $summary, 'calendar' => (int) $link->id]), 'created_at' => now()]);
        } catch (\Throwable $e) {}
    }
}
