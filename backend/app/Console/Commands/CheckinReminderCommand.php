<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AgencyMailer;
use App\Services\CheckEventNotifier;
use App\Services\EmailTemplate;
use App\Services\FcmService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Remind parents to sign their child in — and out (2026-07-13).
 *
 * Sign-in/out is the attendance record the centre is legally held to, and it is
 * the step parents most often skip in the rush at the door. So:
 *
 *   MORNING  — the child has no sign-in and no reported absence → remind the
 *              guardians, and offer them the "not coming in today" option.
 *   EVENING  — the child was signed IN but never signed OUT → remind them, since
 *              an open attendance record has to be closed by hand otherwise.
 *
 * Channels follow the parent's own settings (notification_prefs / check_in_out):
 * in-app and email on by default, SMS off. A parent who has already told us the
 * child is absent is NOT nagged — that is how an app gets muted.
 *
 *   php artisan kiddietrac:checkin-reminders --window=morning
 *   php artisan kiddietrac:checkin-reminders --window=evening [--dry-run]
 */
class CheckinReminderCommand extends Command
{
    protected $signature = 'kiddietrac:checkin-reminders
        {--window=morning : morning (not signed in) or evening (not signed out)}
        {--dry-run : Print what would be sent, send nothing}';

    protected $description = 'Remind parents to sign their child in or out';

    public function handle(CheckEventNotifier $notifier): int
    {
        $window = $this->option('window') === 'evening' ? 'evening' : 'morning';
        $dry = (bool) $this->option('dry-run');

        $children = DB::table('children as c')
            ->join('families as f', 'f.id', '=', 'c.family_id')
            ->join('centres as ce', 'ce.id', '=', 'f.centre_id')
            ->join('agencies as a', 'a.id', '=', 'ce.agency_id')
            ->whereNull('c.deleted_at')
            ->where('c.enrollment_status', 'enrolled')
            ->select([
                'c.id', 'c.first_name', 'c.preferred_name', 'c.family_id',
                'ce.name as centre_name', 'a.id as agency_id', 'a.timezone as tz',
            ])
            ->get();

        $sent = 0;

        foreach ($children as $child) {
            // Never nag a LIVE agency's parents while we are testing. This command
            // reached 39 real iLearn families before this guard existed.
            if (\App\Support\Suppression::isAgency((int) $child->agency_id)) {
                continue;
            }

            $tz = $child->tz ?: 'America/Toronto';
            $today = Carbon::now($tz);
            $start = $today->copy()->startOfDay()->utc();
            $end = $today->copy()->endOfDay()->utc();

            // Weekends: no reminders. Nobody wants a nag on a Sunday.
            if ($today->isWeekend()) continue;

            // A reported absence silences the reminder for that day.
            $absent = DB::table('child_absences')
                ->where('child_id', $child->id)
                ->whereDate('absent_on', $today->toDateString())
                ->exists();
            if ($absent) continue;

            $events = DB::table('check_events')
                ->where('child_id', $child->id)
                ->whereBetween('occurred_at', [$start, $end])
                ->orderByDesc('occurred_at')
                ->get(['event_type', 'occurred_at']);

            $last = $events->first();
            $name = $child->preferred_name ?: $child->first_name;

            if ($window === 'morning') {
                // Anything logged today at all → they're on top of it.
                if ($events->count()) continue;

                $title = "🕘 Has {$name} arrived?";
                $body = "{$name} hasn't been signed in at {$child->centre_name} today. "
                    . "Please sign in when you arrive — or let us know if they're not coming in.";
            } else {
                // Evening: only chase an OPEN record (signed in, never signed out).
                if (! $last || $last->event_type !== 'check_in') continue;

                $in = Carbon::parse($last->occurred_at)->timezone($tz)->format('g:i A');
                $title = "🕕 Please sign {$name} out";
                $body = "{$name} was signed in at {$in} and hasn't been signed out. "
                    . "Please sign out so the day's attendance record is complete.";
            }

            $guardians = DB::table('guardians as g')
                ->join('users as u', 'u.id', '=', 'g.user_id')
                ->where('g.family_id', $child->family_id)
                ->whereNull('u.deleted_at')
                ->get(['u.id', 'u.email', 'u.phone', 'u.first_name', 'u.last_name']);

            foreach ($guardians as $g) {
                $prefs = $notifier->prefsFor((int) $g->id);

                if ($dry) {
                    $this->line("[dry-run] {$title} → {$g->email} "
                        . '(' . implode('/', array_keys(array_filter($prefs))) . ')');
                    continue;
                }

                if ($prefs['push']) {
                    try {
                        DB::table('notifications')->insert([
                            'user_id' => $g->id, 'type' => 'checkin_reminder',
                            'title' => $title, 'body' => $body,
                            'data' => json_encode(['link' => '#today', 'child_id' => $child->id]),
                            'created_at' => now(),
                        ]);
                        app(FcmService::class)->sendToUser((int) $g->id, $title, $body, '#today');
                    } catch (\Throwable $e) {}
                }

                if ($prefs['email'] && $g->email) {
                    $this->email((int) $child->agency_id, (string) $g->email,
                        trim(($g->first_name ?? '') . ' ' . ($g->last_name ?? '')), $title, $body, $window);
                }

                if ($prefs['sms'] && $g->phone) {
                    try {
                        app(\App\Http\Controllers\Api\SmsController::class)
                            ->sendOne((int) $child->agency_id, (int) $g->id, (string) $g->phone, $body, 'checkin_reminder');
                    } catch (\Throwable $e) {}
                }

                $sent++;
            }

            $this->info(($dry ? '[dry-run] ' : '✓ ') . $title);
        }

        $this->info("Done. {$sent} reminder(s) sent.");
        return self::SUCCESS;
    }

    private function email(int $agencyId, string $to, string $toName, string $title, string $body, string $window): void
    {
        $extra = $window === 'morning'
            ? '<p style="margin:14px 0 0;font-size:13.5px;color:#475569;line-height:1.6;">'
              . 'If your child is not coming in today, you can tell us in the app — open KiddieTrac and tap '
              . '<strong>“Not attending today”</strong>. That lets the whole team know, and stops these reminders.</p>'
            : '';

        $html = EmailTemplate::wrap($agencyId,
            '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;">' . e($body) . '</p>' . $extra,
            [
                'eyebrow' => 'REMINDER',
                'title' => $title,
                'subtitle' => 'Attendance',
                'preheader' => $body,
            ]);

        dispatch(function () use ($agencyId, $to, $toName, $html, $title) {
            AgencyMailer::forAgency($agencyId)->mailer()->html($html, function ($m) use ($to, $toName, $title) {
                $m->to($to, $toName ?: null)
                  ->from('noreply@kiddietrac.com', 'Kiddietrac')
                  ->subject($title);
                $m->getHeaders()->addTextHeader('X-KT-Logged', '1');
            });
        })->onQueue('mail');

        if (\Illuminate\Support\Facades\Schema::hasTable('email_logs')) {
            DB::table('email_logs')->insert([
                'to_email' => $to, 'to_name' => $toName,
                'from_email' => 'noreply@kiddietrac.com', 'subject' => $title,
                'mailer' => config('mail.default'), 'status' => 'sent',
                'tracking_token' => Str::random(32), 'opens' => 0, 'created_at' => now(),
            ]);
        }
    }
}
