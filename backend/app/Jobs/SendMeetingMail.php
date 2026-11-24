<?php

namespace App\Jobs;

use App\Support\Meetings;
use App\Support\Notify;
use App\Support\PlatformSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * A meeting's Teams work and emails, sent from the queue worker (2026-11-13, Anthony: "when the portal
 * refreshes or saves that process moves very slow"). Saving a meeting waited on Microsoft Graph for the
 * Teams meeting and then on every invitation, one email after another: 5.5 s for one attendee, most of it
 * waiting on other servers. The save now answers at once and this does the rest, within the minute.
 *
 *   create  the Teams meeting (platform, video=teams), then the invitation to everyone
 *   update  Teams made or moved; a cancellation to the people taken off ($removed: their rows as they
 *           were, already deleted), the change to the people kept, the invitation to the people added
 *   cancel  the Teams meeting removed; the cancellation to everyone who was sent the invitation
 *
 * The meeting is read again here, so the email carries what it says NOW. One try: a retried job would send
 * the same invitation twice. Whatever did not work - Teams, or an email - goes on the organiser's bell.
 */
class SendMeetingMail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(
        public string $kind,
        public int $meetingId,
        public bool $send = true,
        public ?string $teams = null,      // 'create' | 'move' | null
        public array $keep = [],
        public array $added = [],
        public array $removed = [],
    ) {}

    public function handle(): void
    {
        $m = DB::table('meetings')->where('id', $this->meetingId)->first();
        if (! $m) return;
        $problems = [];
        try {
            if ($this->kind === 'cancel') {
                Meetings::teamsCancel($m);
                if ($this->send) {
                    $told = DB::table('meeting_attendees')->where('meeting_id', $m->id)->whereNotNull('invited_at')->pluck('id')->map(fn ($x) => (int) $x)->all();
                    if ($told) $this->tally(Meetings::send($m, 'cancel', $told), $problems);
                }
            } else {
                // A meeting cancelled before this ran: nothing to make, and nobody to invite to it.
                if ($m->status !== 'scheduled') return;
                if ($this->teams === 'create' && ! $m->graph_event_id) {
                    $m = $this->teamsCreate($m, $problems);
                } elseif ($this->teams === 'move') {
                    Meetings::teamsMove($m);
                }
                if ($this->send && $this->kind === 'create') {
                    $this->tally(Meetings::send($m, 'invite'), $problems);
                } elseif ($this->send) {
                    if ($this->removed) {
                        $gone = array_map(fn ($a) => (object) $a, $this->removed);
                        $tmp = clone $m; $tmp->cancel_reason = 'You are no longer on the invitation list.';
                        $this->tally(Meetings::send($tmp, 'cancel', array_map(fn ($a) => (int) $a->id, $gone), null, $gone), $problems);
                    }
                    if ($this->keep) $this->tally(Meetings::send($m, 'update', $this->keep), $problems);
                    if ($this->added) $this->tally(Meetings::send($m, 'invite', $this->added), $problems);
                }
            }
        } catch (\Throwable $e) {
            Log::error('SendMeetingMail ' . $this->kind . '#' . $this->meetingId . ' failed: ' . $e->getMessage());
            $problems[] = 'the emails could not be sent';
        }
        if ($problems) {
            Notify::write([['user_id' => (int) $m->organizer_id, 'type' => 'meeting', 'title' => '⚠️ ' . $m->title . ': ' . $problems[0],
                'body' => ucfirst(implode('; ', $problems)) . '. Open the meeting to check it.',
                'data' => json_encode(['link' => $m->scope === 'platform' ? '#sales-calendar' : '#staff-calendar']), 'created_at' => now()]]);
        }
    }

    /** Teams on the sales@ calendar; if Graph says no, the saved Teams link, else no link (said on the bell). */
    private function teamsCreate(object $m, array &$problems): object
    {
        $t = Meetings::teamsCreate($m);
        if (! empty($t['url'])) {
            DB::table('meetings')->where('id', $m->id)->update(['join_url' => $t['url'], 'graph_event_id' => $t['event_id'] ?? null, 'updated_at' => now()]);
        } elseif ($this->kind === 'create') {
            $fallback = (string) PlatformSettings::get('sales.booking.teams_link', '');
            DB::table('meetings')->where('id', $m->id)->update(['join_url' => $fallback ?: null, 'video' => $fallback ? 'teams' : 'none', 'graph_event_id' => $t['event_id'] ?? null, 'updated_at' => now()]);
            $problems[] = $fallback ? 'Teams could not make a new meeting, so the saved Teams link was used' : 'Teams could not make a meeting, so the invitation went out without a link (add one with Edit)';
        } else {
            $problems[] = 'Teams could not make a meeting (add a link with Edit)';
        }

        return DB::table('meetings')->where('id', $m->id)->first();
    }

    private function tally(array $r, array &$problems): void
    {
        if (($r[1] ?? 0) > 0) $problems[] = $r[1] . ' email' . ($r[1] === 1 ? '' : 's') . ' could not be sent';
    }
}
