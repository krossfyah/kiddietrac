<?php

namespace App\Console\Commands;

use App\Support\BookingMail;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * kt:booking-test-emails {to} — every email of a website meeting request, sent to one
 * address as [TEST] samples (2026-10-23). Built from an in-memory booking: nothing is
 * saved, no Teams meeting is made, nobody else is mailed.
 */
class BookingTestEmails extends Command
{
    protected $signature = 'kt:booking-test-emails {to} {--only= : requested,sales,confirmed,modified,declined,cancelled,intake,sequence} {--video= : film URL to show (review before approval)} {--poster= : its poster image URL} {--length=90-second} {--proposal : show the proposal + intake version (before it is switched on)} {--intake-done : as if they already sent the intake form}';
    protected $description = 'Send sample website-booking emails to one address';

    public function handle(): int
    {
        $to = (string) $this->argument('to');
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) { $this->error('Give an email address.'); return self::FAILURE; }
        $start = Carbon::now(BookingMail::TZ)->addWeekdays(2)->setTime(14, 0);
        $b = (object) [
            'id' => 0, 'type' => 'demo', 'duration_min' => 45, 'starts_at' => $start->copy()->utc()->format('Y-m-d H:i:s'),
            'ends_at' => $start->copy()->addMinutes(45)->utc()->format('Y-m-d H:i:s'), 'name' => 'Anthony Hosein', 'email' => $to,
            'agency' => 'Little Steps Daycare', 'phone' => '(416) 555-0199', 'meet_via' => 'video', 'children' => '25-50',
            'notes' => 'We run two centres and want to replace paper sign-in sheets.', 'visitor_tz' => 'America/Vancouver', 'lang' => 'en',
            'status' => 'requested', 'teams_url' => 'https://teams.microsoft.com/l/meetup-join/SAMPLE-LINK-for-this-test', 'teams_event_id' => null,
            'host_user_id' => 1, 'sales_note' => null, 'decline_reason' => null, 'sequence' => 0, 'requested_starts_at' => null,
        ];
        if ($this->option('video') && $this->option('poster')) {
            BookingMail::$videoOverride = ['url' => (string) $this->option('video'), 'poster' => (string) $this->option('poster'), 'length' => (string) $this->option('length')];
        }
        if ($this->option('proposal')) BookingMail::$proposal = true;
        BookingMail::$intakeDoneOverride = $this->option('intake-done') ? true : false;
        $only = array_filter(explode(',', (string) $this->option('only')));
        $want = fn ($k) => ! $only || in_array($k, $only, true);
        if ($want('sales')) $this->line('sales notice: ' . (BookingMail::salesRequest($b, $to) ? 'sent' : 'FAILED'));
        if ($want('requested')) $this->line('request received: ' . (BookingMail::visitor($b, 'requested', $to) ? 'sent' : 'FAILED'));
        if ($want('confirmed')) {
            $c = clone $b; $c->status = 'booked'; $c->sales_note = 'Looking forward to it — I\'ll have your two centres set up as a sample so you can see your own rooms.';
            $this->line('confirmed: ' . (BookingMail::visitor($c, 'confirmed', $to) ? 'sent' : 'FAILED'));
        }
        if ($want('modified')) {
            $m = clone $b; $m->status = 'booked'; $old = $m->starts_at; $ns = $start->copy()->addDay()->setTime(10, 0);
            $m->starts_at = $ns->copy()->utc()->format('Y-m-d H:i:s'); $m->ends_at = $ns->copy()->addMinutes(45)->utc()->format('Y-m-d H:i:s'); $m->sequence = 1;
            $m->sales_note = 'Something came up at 2 PM — I\'ve moved us to the next morning. Reply if that doesn\'t suit.';
            $this->line('modified: ' . (BookingMail::visitor($m, 'modified', $to, $old) ? 'sent' : 'FAILED'));
        }
        if ($want('declined')) {
            $d = clone $b; $d->status = 'declined'; $d->decline_reason = 'Our team is fully booked that afternoon.'; $d->sales_note = 'Thursday or Friday morning would be perfect if either works for you - just pick a time and I will confirm it straight away.';
            $this->line('declined: ' . (BookingMail::visitor($d, 'declined', $to) ? 'sent' : 'FAILED'));
        }
        if ($want('intake')) {
            $r = clone $b; $r->status = 'booked';
            $this->line('intake reminder: ' . (BookingMail::visitor($r, 'intake', $to) ? 'sent' : 'FAILED'));
        }
        // THE RUN-UP SEQUENCE (2026-10-26, BookingRunUp): every email in order — the films (all of them, live
        // or not), the day before and the day itself. Only with --only=sequence: it is six emails.
        if (in_array('sequence', $only, true)) {
            \App\Support\BookingRunUp::$allFilmsLive = true;
            $r = clone $b; $r->status = 'booked'; $r->name = 'Claude Test (delete me)';
            foreach (array_keys(\App\Support\BookingRunUp::films()) as $i => $k) {
                $this->line('run-up ' . ($i + 1) . ' (' . $k . '): ' . (BookingMail::visitor($r, 'runup', $to, null, $k) ? 'sent' : 'FAILED'));
                sleep(2);
            }
            $this->line('run-up: tomorrow: ' . (BookingMail::visitor($r, 'tomorrow', $to) ? 'sent' : 'FAILED')); sleep(2);
            $this->line('run-up: today: ' . (BookingMail::visitor($r, 'today', $to) ? 'sent' : 'FAILED'));
        }
        if ($want('cancelled')) {
            $x = clone $b; $x->status = 'cancelled'; $x->sequence = 2; $x->decline_reason = 'A scheduling conflict on our side.'; $x->sales_note = 'So sorry for the change - I would still love to show you KiddieTrac. Grab any time that suits you.';
            $this->line('cancelled: ' . (BookingMail::visitor($x, 'cancelled', $to) ? 'sent' : 'FAILED'));
        }
        return self::SUCCESS;
    }
}
