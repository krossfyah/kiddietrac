<?php

declare(strict_types=1);

namespace App\Support;

use App\Jobs\SendRsvpMail;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Event reminders (2026-10-06): scheduled or sent by hand, about an upcoming event.
 *
 * WHO. Every RSVP — invited or through the shareable link — has an rsvp_invites row, so
 * the invite row is the person:
 *   registered   signed up, not opted out
 *   no_response  the invitation was delivered, they have not signed up, not opted out
 *   both         either
 * Anyone on the agency's opt-out list is left out, whatever the audience.
 *
 * WHEN. offset_minutes before the event starts (follows the event if its time moves), or
 * an absolute send_at. rsvp:send-reminders runs every five minutes and sends what is due.
 *
 * ONCE. A run claims a reminder with a conditional update (scheduled → sending), so two
 * overlapping runs cannot both send it, and rsvp_reminder_sends is unique on
 * (reminder, invite), so nobody gets the same reminder twice even if a run is retried.
 */
final class RsvpReminders
{
    public const AUDIENCES = ['registered', 'no_response', 'both'];

    /** When a reminder is due, in UTC, or null when it cannot be placed (no start time). */
    public static function dueAt(object $r, object $event): ?Carbon
    {
        if ($r->offset_minutes !== null) {
            if (empty($event->starts_at)) return null;
            return Carbon::parse($event->starts_at, 'UTC')->subMinutes((int) $r->offset_minutes);
        }

        return $r->send_at ? Carbon::parse($r->send_at, 'UTC') : null;
    }

    /** The invite rows a reminder goes to (opted-out addresses already removed). */
    public static function recipients(object $event, string $audience)
    {
        $q = DB::table('rsvp_invites')->where('event_id', $event->id)->where('agency_id', $event->agency_id)->whereNull('opted_out_at');
        if ($audience === 'registered') {
            $q->whereNotNull('registered_at');
        } elseif ($audience === 'no_response') {
            $q->whereNull('registered_at')->where('send_status', 'sent');
        } else {
            $q->where(function ($w) { $w->whereNotNull('registered_at')->orWhere('send_status', 'sent'); });
        }
        $optedOut = DB::table('rsvp_optouts')->where('agency_id', $event->agency_id)->pluck('email')->map(fn ($e) => strtolower((string) $e))->flip();

        return $q->orderBy('id')->get(['id', 'email', 'name', 'registered_at', 'send_status'])
            ->filter(fn ($i) => ! isset($optedOut[strtolower((string) $i->email)]))->values();
    }

    /** Why this reminder cannot go out now, or null if it can. */
    public static function blocker(object $event): ?string
    {
        if (! empty($event->deleted_at)) return 'The event was deleted.';
        if ($event->status !== 'open') return 'The event is ' . $event->status . ', not open.';
        if (! empty($event->starts_at) && Carbon::parse($event->starts_at, 'UTC')->isPast()) return 'The event has already started.';

        return null;
    }

    /**
     * Send one reminder now: claim it, write a send row per recipient, queue the mail.
     * Returns ['status' => sent|skipped|busy, 'queued' => n, 'note' => ?string].
     */
    public static function run(int $reminderId): array
    {
        // Claim: only one run may move it out of "scheduled".
        $claimed = DB::table('rsvp_reminders')->where('id', $reminderId)->where('status', 'scheduled')
            ->update(['status' => 'sending', 'updated_at' => now()]);
        if (! $claimed) return ['status' => 'busy', 'queued' => 0, 'note' => 'Already sent, cancelled or being sent.'];

        try {
            return self::runClaimed($reminderId);
        } catch (\Throwable $e) {
            // Put it back so the next run tries again; the unique key on the send rows means
            // anyone already queued is not queued twice.
            DB::table('rsvp_reminders')->where('id', $reminderId)->where('status', 'sending')
                ->update(['status' => 'scheduled', 'status_note' => mb_substr('Last attempt failed: ' . $e->getMessage(), 0, 250), 'updated_at' => now()]);
            throw $e;
        }
    }

    private static function runClaimed(int $reminderId): array
    {
        $r = DB::table('rsvp_reminders')->where('id', $reminderId)->first();
        $event = DB::table('rsvp_events')->where('id', $r->event_id)->where('agency_id', $r->agency_id)->first();
        $why = $event ? self::blocker($event) : 'The event no longer exists.';
        if ($why) {
            DB::table('rsvp_reminders')->where('id', $r->id)->update(['status' => 'skipped', 'status_note' => $why, 'updated_at' => now()]);
            return ['status' => 'skipped', 'queued' => 0, 'note' => $why];
        }

        $people = self::recipients($event, $r->audience);
        // Paced through a personal Gmail account, like invitations: a burst of identical
        // messages from a new account is what its filters treat as spam.
        $paced = ! empty($event->sender_id)
            && DB::table('rsvp_senders')->where('id', $event->sender_id)->where('agency_id', $event->agency_id)->value('type') === 'gmail_oauth';
        $n = 0;
        foreach ($people as $p) {
            $sid = null;
            try {
                $sid = DB::table('rsvp_reminder_sends')->insertGetId(['agency_id' => $r->agency_id, 'reminder_id' => $r->id, 'event_id' => $event->id,
                    'invite_id' => $p->id, 'email' => strtolower((string) $p->email), 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
            } catch (\Illuminate\Database\QueryException $e) {
                continue;   // already has this reminder (unique key)
            }
            $job = SendRsvpMail::dispatch('reminder', (int) $sid)->onQueue('mail');
            if ($paced && $n > 0) $job->delay(now()->addSeconds(min($n * 6, 6 * 3600)));
            unset($job);
            $n++;
        }
        $note = $n ? null : 'Nobody to remind: ' . match ($r->audience) {
            'registered' => 'no one has signed up yet.',
            'no_response' => 'everyone invited has replied.',
            default => 'no one has signed up or been sent an invitation.',
        };
        DB::table('rsvp_reminders')->where('id', $r->id)->update(['status' => 'sent', 'recipient_count' => $n, 'sent_at' => now(),
            'status_note' => $note, 'updated_at' => now()]);

        return ['status' => 'sent', 'queued' => $n, 'note' => $note];
    }

    /** Every due reminder, for rsvp:send-reminders. Returns a line per reminder handled. */
    public static function runDue(): array
    {
        $out = [];
        $rows = DB::table('rsvp_reminders')->where('status', 'scheduled')->orderBy('id')->get();
        foreach ($rows as $r) {
            $event = DB::table('rsvp_events')->where('id', $r->event_id)->where('agency_id', $r->agency_id)->first();
            if (! $event || ! empty($event->deleted_at)) {
                DB::table('rsvp_reminders')->where('id', $r->id)->where('status', 'scheduled')->update(['status' => 'skipped', 'status_note' => 'The event was deleted.', 'updated_at' => now()]);
                $out[] = "#{$r->id} skipped (event deleted)";
                continue;
            }
            $due = self::dueAt($r, $event);
            if (! $due || $due->isFuture()) continue;
            // Due while the event was not open, or after it began: record why, do not send.
            $why = self::blocker($event);
            if ($why) {
                // A draft or closed event may still open before its start: keep waiting
                // until the event itself has started, then give up with the reason.
                $started = ! empty($event->starts_at) && Carbon::parse($event->starts_at, 'UTC')->isPast();
                if ($event->status !== 'open' && ! $started) continue;
                DB::table('rsvp_reminders')->where('id', $r->id)->where('status', 'scheduled')->update(['status' => 'skipped', 'status_note' => $why, 'updated_at' => now()]);
                $out[] = "#{$r->id} skipped ({$why})";
                continue;
            }
            $res = self::run((int) $r->id);
            $out[] = "#{$r->id} {$res['status']} queued={$res['queued']}" . ($res['note'] ? " ({$res['note']})" : '');
        }

        return $out;
    }

    /** "1 week before", "1 day before", "2 hours before", "45 minutes before". */
    public static function offsetLabel(?int $m): string
    {
        if ($m === null) return '';
        if ($m > 0 && $m % 10080 === 0) { $w = intdiv($m, 10080); return $w . ' week' . ($w > 1 ? 's' : '') . ' before'; }
        if ($m > 0 && $m % 1440 === 0) { $d = intdiv($m, 1440); return $d . ' day' . ($d > 1 ? 's' : '') . ' before'; }
        if ($m > 0 && $m % 60 === 0) { $h = intdiv($m, 60); return $h . ' hour' . ($h > 1 ? 's' : '') . ' before'; }

        return $m . ' minutes before';
    }
}
