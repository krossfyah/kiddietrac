<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * A Teams meeting for a confirmed website booking (2026-10-23), made on the sales@
 * calendar through Microsoft Graph with the platform mail app (PlatformSettings
 * mail.graph.*). THE VISITOR IS ON THE MEETING (2026-10-26, Anthony: the recipient must be
 * invited, not just the meeting added to sales@): they are a required attendee, so Outlook
 * sends them the real Teams invitation and their reply, a move or a cancel stay with this
 * meeting. Our confirmation email still goes too, with the join link and its own .ics
 * (Anthony chose both).
 *
 * Needs the APPLICATION permission Calendars.ReadWrite on the "KiddieTrac Mailer" app
 * (admin consent). Until then create() returns ['error' => 'permission'] and Sales pastes
 * a link, or the saved default link (sales.booking.teams_link) is used.
 */
class BookingTeams
{
    public const MAILBOX = 'sales@kiddietrac.com';
    private const GRAPH = 'https://graph.microsoft.com/v1.0';

    private static function token(): ?string
    {
        $s = PlatformSettings::all();
        $tenant = $s['mail.graph.tenant'] ?? null;
        $client = $s['mail.graph.client_id'] ?? null;
        $secret = PlatformSettings::getSecret('mail.graph.client_secret');
        if (! $tenant || ! $client || ! $secret) return null;
        return Cache::remember('booking.graph.token.' . md5($tenant . $client), 3000, function () use ($tenant, $client, $secret) {
            $r = Http::asForm()->timeout(20)->post('https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/token', [
                'client_id' => $client, 'client_secret' => $secret, 'scope' => 'https://graph.microsoft.com/.default', 'grant_type' => 'client_credentials',
            ]);
            return $r->json('access_token') ?: null;
        });
    }

    /** The platform mail app's Graph token (also used by SalesMailbox to send as sales@). */
    public static function appToken(): ?string
    {
        return self::token();
    }

    /** Has the app been given calendar rights? (decoded from the token's roles) */
    public static function available(): bool
    {
        try {
            $t = self::token();
            if (! $t) return false;
            $p = json_decode(base64_decode(strtr(explode('.', $t)[1] ?? '', '-_', '+/')), true) ?: [];
            return (bool) array_intersect(['Calendars.ReadWrite', 'OnlineMeetings.ReadWrite.All'], $p['roles'] ?? []);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Extra people on the invite (marketing_bookings.invitees, JSON list of emails). */
    public static function invitees(object $b): array
    {
        $v = json_decode((string) ($b->invitees ?? ''), true);
        return is_array($v) ? array_values(array_filter($v, fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))) : [];
    }

    /** The person who booked, then anyone added on the booking - each once. */
    private static function attendees(object $b): array
    {
        $out = []; $seen = [];
        $visitor = trim((string) ($b->email ?? ''));
        if (filter_var($visitor, FILTER_VALIDATE_EMAIL)) {
            $out[] = ['emailAddress' => ['address' => $visitor, 'name' => (string) ($b->name ?? '')], 'type' => 'required'];
            $seen[strtolower($visitor)] = 1;
        }
        foreach (self::invitees($b) as $e) {
            if (isset($seen[strtolower($e)])) continue;
            $seen[strtolower($e)] = 1;
            $out[] = ['emailAddress' => ['address' => $e], 'type' => 'required'];
        }
        return $out;
    }

    private static function body(object $b, string $typeName): array
    {
        $tz = 'Eastern Standard Time';   // Windows zone name Graph expects for America/Toronto
        $fmt = fn ($t) => Carbon::parse($t, 'UTC')->tz('America/Toronto')->format('Y-m-d\TH:i:s');
        return [
            // Topic and who is meeting, first names only on both sides - no booking number, no
            // contact details: Microsoft sends this invitation to the visitor too (2026-10-27).
            'subject' => 'KiddieTrac ' . strtolower($typeName) . ': ' . \App\Support\BookingMail::whoLine($b),
            'body' => ['contentType' => 'HTML', 'content' => '<p><strong>Topic:</strong> ' . e($typeName)
                . '<br><strong>Meeting:</strong> ' . e(\App\Support\BookingMail::whoLine($b)) . '</p>'],
            'start' => ['dateTime' => $fmt($b->starts_at), 'timeZone' => $tz],
            'end' => ['dateTime' => $fmt($b->ends_at), 'timeZone' => $tz],
            'isOnlineMeeting' => true,
            'onlineMeetingProvider' => 'teamsForBusiness',
            'allowNewTimeProposals' => false,
            'showAs' => 'busy',
            'attendees' => self::attendees($b),
        ];
    }

    /** With a pasted (or default) Teams link but people to invite: a plain Outlook event that carries the link. */
    public static function createPlain(object $b, string $typeName, string $url): ?string
    {
        try {
            $t = self::token();
            if (! $t) return null;
            $body = self::body($b, $typeName);
            $body['isOnlineMeeting'] = false; unset($body['onlineMeetingProvider']);
            $body['location'] = ['displayName' => 'Microsoft Teams', 'locationUri' => $url];
            $body['body']['content'] .= '<p><a href="' . e($url) . '">Join the Microsoft Teams meeting</a></p>';
            $r = Http::withToken($t)->timeout(25)->post(self::GRAPH . '/users/' . rawurlencode(self::MAILBOX) . '/events', $body);
            return $r->successful() ? (string) $r->json('id') : null;
        } catch (\Throwable $e) {
            Log::warning('plain calendar event failed', ['booking' => $b->id ?? null, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /** ['url' => joinUrl, 'event_id' => id] or ['error' => 'permission'|'failed', 'detail' => ...] */
    public static function create(object $b, string $typeName): array
    {
        try {
            $t = self::token();
            if (! $t) return ['error' => 'failed', 'detail' => 'no Graph credentials'];
            $r = Http::withToken($t)->timeout(25)->post(self::GRAPH . '/users/' . rawurlencode(self::MAILBOX) . '/events', self::body($b, $typeName));
            if ($r->status() === 403 || $r->status() === 401) return ['error' => 'permission', 'detail' => (string) $r->json('error.message')];
            if (! $r->successful()) return ['error' => 'failed', 'detail' => $r->status() . ' ' . $r->json('error.message')];
            $url = (string) ($r->json('onlineMeeting.joinUrl') ?? '');
            return $url !== '' ? ['url' => $url, 'event_id' => (string) $r->json('id')] : ['error' => 'failed', 'detail' => 'no join link returned', 'event_id' => (string) $r->json('id')];
        } catch (\Throwable $e) {
            Log::warning('Teams meeting create failed', ['booking' => $b->id ?? null, 'error' => $e->getMessage()]);
            return ['error' => 'failed', 'detail' => $e->getMessage()];
        }
    }

    /** Move the calendar event to the booking's (new) time. The join link stays the same. */
    public static function move(object $b, string $typeName): bool
    {
        if (empty($b->teams_event_id)) return false;
        try {
            $t = self::token();
            $body = self::body($b, $typeName);
            $r = Http::withToken($t)->timeout(25)->patch(self::GRAPH . '/users/' . rawurlencode(self::MAILBOX) . '/events/' . rawurlencode($b->teams_event_id),
                ['subject' => $body['subject'], 'start' => $body['start'], 'end' => $body['end'], 'attendees' => $body['attendees']]);
            return $r->successful();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /* ── Generic events on the sales@ calendar, for Meetings (2026-10-25) ── */

    /** Create an event from a ready Graph body. ['url','event_id'] or ['error' => 'permission'|'failed']. */
    public static function eventCreate(array $body): array
    {
        try {
            $t = self::token();
            if (! $t) return ['error' => 'failed', 'detail' => 'no Graph credentials'];
            $r = Http::withToken($t)->timeout(25)->post(self::GRAPH . '/users/' . rawurlencode(self::MAILBOX) . '/events', $body);
            if ($r->status() === 403 || $r->status() === 401) return ['error' => 'permission', 'detail' => (string) $r->json('error.message')];
            if (! $r->successful()) return ['error' => 'failed', 'detail' => $r->status() . ' ' . $r->json('error.message')];
            $url = (string) ($r->json('onlineMeeting.joinUrl') ?? '');
            return $url !== '' ? ['url' => $url, 'event_id' => (string) $r->json('id')] : ['error' => 'failed', 'detail' => 'no join link returned', 'event_id' => (string) $r->json('id')];
        } catch (\Throwable $e) {
            Log::warning('calendar event create failed', ['error' => $e->getMessage()]);
            return ['error' => 'failed', 'detail' => $e->getMessage()];
        }
    }

    public static function eventPatch(string $eventId, array $body): bool
    {
        try {
            $r = Http::withToken(self::token())->timeout(25)->patch(self::GRAPH . '/users/' . rawurlencode(self::MAILBOX) . '/events/' . rawurlencode($eventId), $body);
            return $r->successful();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function eventDelete(string $eventId): void
    {
        try {
            Http::withToken(self::token())->timeout(20)->delete(self::GRAPH . '/users/' . rawurlencode(self::MAILBOX) . '/events/' . rawurlencode($eventId));
        } catch (\Throwable $e) {
        }
    }

    public static function cancel(object $b): void
    {
        if (empty($b->teams_event_id)) return;
        try {
            $url = self::GRAPH . '/users/' . rawurlencode(self::MAILBOX) . '/events/' . rawurlencode($b->teams_event_id);
            // With people invited (the visitor always is), CANCEL so Outlook tells them; otherwise just remove it.
            if (self::attendees($b)) Http::withToken(self::token())->timeout(20)->post($url . '/cancel', ['Comment' => 'This meeting has been cancelled.']);
            else Http::withToken(self::token())->timeout(20)->delete($url);
        } catch (\Throwable $e) {
        }
    }
}
