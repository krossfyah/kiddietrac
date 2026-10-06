<?php

namespace App\Jobs;

use App\Support\RsvpMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One RSVP email, sent from the queue worker (2026-10-05).
 *
 *   invite        id = rsvp_invites.id
 *   confirmation  id = rsvp_responses.id   (the registrant's copy)
 *   notice        id = rsvp_responses.id   (to the event's notify address; $extra = 'new' | 'updated')
 *   optout        id = rsvp_invites.id     (to the event's notify address)
 *   reminder      id = rsvp_reminder_sends.id   (one person's copy of a reminder)
 *
 * Queued because an invite run is many sends at ~0.5 s each, which is too long for a
 * request. The outcome is written back to the row from what the mail layer recorded
 * (RsvpMail::send), and a failure is recorded rather than thrown: a retried job would
 * send the same invite twice.
 *
 * Every message for an event goes out through the event's sender account when it has one
 * (RsvpSender, 2026-10-06), otherwise through the agency's own mail.
 */
class SendRsvpMail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public string $kind, public int $id, public string $extra = '') {}

    public function handle(): void
    {
        try {
            match ($this->kind) {
                'invite' => $this->invite(),
                'confirmation' => $this->confirmation(),
                'notice' => $this->notice(),
                'optout' => $this->optOut(),
                'reminder' => $this->reminder(),
                default => null,
            };
        } catch (\Throwable $e) {
            Log::error('SendRsvpMail ' . $this->kind . '#' . $this->id . ' failed: ' . $e->getMessage());
            if ($this->kind === 'invite') {
                DB::table('rsvp_invites')->where('id', $this->id)->update(['send_status' => 'failed', 'send_error' => mb_substr($e->getMessage(), 0, 480), 'updated_at' => now()]);
            }
            if ($this->kind === 'reminder') {
                $this->finishReminder('failed', mb_substr($e->getMessage(), 0, 480), null);
            }
        }
    }

    private function event(int $eventId): ?object
    {
        return DB::table('rsvp_events')->where('id', $eventId)->whereNull('deleted_at')->first();
    }

    private function invite(): void
    {
        $inv = DB::table('rsvp_invites')->where('id', $this->id)->first();
        if (! $inv || $inv->send_status !== 'queued') return;   // cancelled, or already handled
        $event = $this->event((int) $inv->event_id);
        if (! $event || (int) $event->agency_id !== (int) $inv->agency_id) {
            DB::table('rsvp_invites')->where('id', $inv->id)->update(['send_status' => 'failed', 'send_error' => 'The event no longer exists.', 'updated_at' => now()]);
            return;
        }
        if (RsvpMail::optedOut((int) $inv->agency_id, $inv->email)) {
            DB::table('rsvp_invites')->where('id', $inv->id)->update(['send_status' => 'skipped', 'send_error' => 'This address has opted out of event invitations.', 'updated_at' => now()]);
            return;
        }
        [$subject, $html] = RsvpMail::invite($event, $inv);
        $r = RsvpMail::send((int) $event->agency_id, $inv->email, $inv->name, $subject, $html, [
            'reply_to' => $event->notify_email, 'unsubscribe' => RsvpMail::optOutUrl($inv->token), 'sender_id' => $event->sender_id ?? null, 'from' => RsvpMail::fromFor($event),
            'track' => true,
        ]);
        DB::table('rsvp_invites')->where('id', $inv->id)->update([
            'send_status' => $r['status'], 'send_error' => $r['error'], 'email_log_id' => $r['log_id'],
            'sent_at' => $r['status'] === 'sent' ? now() : $inv->sent_at,
            'send_count' => (int) $inv->send_count + ($r['status'] === 'sent' ? 1 : 0),
            'updated_at' => now(),
        ]);
    }

    private function confirmation(): void
    {
        $resp = DB::table('rsvp_responses')->where('id', $this->id)->first();
        if (! $resp) return;
        $event = $this->event((int) $resp->event_id);
        if (! $event) return;
        $inv = $resp->invite_id ? DB::table('rsvp_invites')->where('id', $resp->invite_id)->first() : null;
        [$subject, $html] = RsvpMail::confirmation($event, $resp, $inv);
        $r = RsvpMail::send((int) $event->agency_id, $resp->email, $resp->name, $subject, $html, [
            'reply_to' => $event->notify_email, 'unsubscribe' => $inv ? RsvpMail::optOutUrl($inv->token) : null, 'sender_id' => $event->sender_id ?? null, 'from' => RsvpMail::fromFor($event),
        ]);
        DB::table('rsvp_responses')->where('id', $resp->id)->update(['confirm_status' => $r['status']]);
    }

    private function notice(): void
    {
        $resp = DB::table('rsvp_responses')->where('id', $this->id)->first();
        if (! $resp) return;
        $event = $this->event((int) $resp->event_id);
        if (! $event || ! $event->notify_email) return;
        $answers = array_values(json_decode((string) $resp->answers, true) ?: []);
        [$subject, $html] = RsvpMail::notice($event, $this->extra === 'updated' ? 'updated' : 'new', $resp->email, $resp->name, $answers);
        $r = RsvpMail::send((int) $event->agency_id, $event->notify_email, null, $subject, $html, ['reply_to' => $resp->email, 'reply_to_name' => $resp->name, 'sender_id' => $event->sender_id ?? null, 'from' => RsvpMail::fromFor($event)]);
        DB::table('rsvp_responses')->where('id', $resp->id)->update(['notify_status' => $r['status']]);
    }

    /* One person's reminder. Re-checked at send time, not just when it was queued: they may
       have opted out, or (for a "not replied yet" reminder) signed up, in the minutes the
       queue took — and the reminder itself may have been cancelled. */
    private function reminder(): void
    {
        $send = DB::table('rsvp_reminder_sends')->where('id', $this->id)->first();
        if (! $send || $send->status !== 'queued') return;
        $rem = DB::table('rsvp_reminders')->where('id', $send->reminder_id)->first();
        $inv = DB::table('rsvp_invites')->where('id', $send->invite_id)->where('agency_id', $send->agency_id)->first();
        $event = $inv ? $this->event((int) $inv->event_id) : null;
        if (! $rem || $rem->status === 'cancelled') { $this->finishReminder('skipped', 'The reminder was cancelled.', null); return; }
        if (! $inv || ! $event || (int) $event->agency_id !== (int) $send->agency_id) { $this->finishReminder('failed', 'The event or the person is no longer on the list.', null); return; }
        if ($inv->opted_out_at || RsvpMail::optedOut((int) $inv->agency_id, $inv->email)) { $this->finishReminder('skipped', 'This address has opted out of event emails.', null); return; }
        if ($rem->audience === 'no_response' && $inv->registered_at) { $this->finishReminder('skipped', 'Signed up before the reminder went out.', null); return; }
        $resp = $inv->registered_at ? DB::table('rsvp_responses')->where('invite_id', $inv->id)->where('event_id', $event->id)->first() : null;
        [$subject, $html] = RsvpMail::reminder($event, $inv, $rem, $resp);
        $r = RsvpMail::send((int) $event->agency_id, $inv->email, $resp->name ?? $inv->name, $subject, $html, [
            'reply_to' => $event->notify_email, 'unsubscribe' => RsvpMail::optOutUrl($inv->token), 'sender_id' => $event->sender_id ?? null, 'from' => RsvpMail::fromFor($event),
            'track' => true,
        ]);
        $this->finishReminder($r['status'], $r['error'], $r['log_id']);
    }

    private function finishReminder(string $status, ?string $error, ?int $logId): void
    {
        $send = DB::table('rsvp_reminder_sends')->where('id', $this->id)->first();
        if (! $send) return;
        DB::table('rsvp_reminder_sends')->where('id', $send->id)->update(['status' => $status, 'error' => $error, 'email_log_id' => $logId,
            'sent_at' => $status === 'sent' ? now() : null, 'updated_at' => now()]);
        if ($status === 'sent') {
            DB::table('rsvp_reminders')->where('id', $send->reminder_id)->increment('sent_count');
        } elseif (in_array($status, ['failed', 'suppressed'], true)) {
            DB::table('rsvp_reminders')->where('id', $send->reminder_id)->increment('failed_count');
        }
    }

    private function optOut(): void
    {
        $inv = DB::table('rsvp_invites')->where('id', $this->id)->first();
        if (! $inv) return;
        $event = $this->event((int) $inv->event_id);
        if (! $event || ! $event->notify_email) return;
        [$subject, $html] = RsvpMail::notice($event, 'opt_out', $inv->email, $inv->name);
        RsvpMail::send((int) $event->agency_id, $event->notify_email, null, $subject, $html, ['sender_id' => $event->sender_id ?? null, 'from' => RsvpMail::fromFor($event)]);
    }
}
