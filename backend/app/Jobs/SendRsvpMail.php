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
 *
 * Queued because an invite run is many sends at ~0.5 s each, which is too long for a
 * request. The outcome is written back to the row from what the mail layer recorded
 * (RsvpMail::send), and a failure is recorded rather than thrown: a retried job would
 * send the same invite twice.
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
                default => null,
            };
        } catch (\Throwable $e) {
            Log::error('SendRsvpMail ' . $this->kind . '#' . $this->id . ' failed: ' . $e->getMessage());
            if ($this->kind === 'invite') {
                DB::table('rsvp_invites')->where('id', $this->id)->update(['send_status' => 'failed', 'send_error' => mb_substr($e->getMessage(), 0, 480), 'updated_at' => now()]);
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
            'reply_to' => $event->notify_email, 'unsubscribe' => RsvpMail::optOutUrl($inv->token),
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
            'reply_to' => $event->notify_email, 'unsubscribe' => $inv ? RsvpMail::optOutUrl($inv->token) : null,
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
        $r = RsvpMail::send((int) $event->agency_id, $event->notify_email, null, $subject, $html, ['reply_to' => $resp->email, 'reply_to_name' => $resp->name]);
        DB::table('rsvp_responses')->where('id', $resp->id)->update(['notify_status' => $r['status']]);
    }

    private function optOut(): void
    {
        $inv = DB::table('rsvp_invites')->where('id', $this->id)->first();
        if (! $inv) return;
        $event = $this->event((int) $inv->event_id);
        if (! $event || ! $event->notify_email) return;
        [$subject, $html] = RsvpMail::notice($event, 'opt_out', $inv->email, $inv->name);
        RsvpMail::send((int) $event->agency_id, $event->notify_email, null, $subject, $html);
    }
}
