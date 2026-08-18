<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use App\Support\AgencyTime;
use App\Support\Closures;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Remind families and educators that a centre is about to close.
 *
 * A closure was announced ONCE, when it was entered. A December holiday added in June
 * emailed everyone in June and was never mentioned again — which is not a reminder, it is
 * an announcement that has long since scrolled away. This sends again a week out and the
 * day before, when it can still change what somebody does.
 *
 * Parents and educators get different letters because they need different things from it:
 * a parent needs to know there is no care and whether they are still charged; an educator
 * needs to know not to come in and that the day is not missing from their hours.
 *
 * Admins and directors are BCC'd rather than sent their own copy — they asked to see what
 * went out, and a third variant of the same message is noise.
 */
class ClosureReminderCommand extends Command
{
    protected $signature = 'closures:remind
        {--days=7,1 : lead times to remind at, in days before the closure}
        {--test-to= : send one of each role variant to this address and write nothing}
        {--dry-run : list what would be sent}';

    protected $description = 'Remind parents and educators about an upcoming closure';

    public function handle(): int
    {
        $testTo = (string) ($this->option('test-to') ?? '');
        if ($testTo !== '') {
            return $this->sendSamples($testTo);
        }

        $leads = array_values(array_filter(array_map(
            fn ($d) => (int) trim($d),
            explode(',', (string) $this->option('days'))
        ), fn ($d) => $d > 0));

        $dry = (bool) $this->option('dry-run');
        $sent = 0;

        foreach (DB::table('centre_closures as cc')
            ->join('centres as c', 'c.id', '=', 'cc.centre_id')
            ->whereDate('cc.closure_date', '>=', now()->subDay()->toDateString())
            ->get(['cc.*', 'c.name as centre_name', 'c.agency_id']) as $row) {

            // "How many days away" must be counted on the AGENCY's calendar. Counted in
            // UTC, a closure starting tomorrow reads as today for part of every evening.
            $tz = AgencyTime::tzForCentre((int) $row->centre_id);
            $today = Carbon::today($tz);
            $start = Carbon::parse(substr((string) $row->closure_date, 0, 10), $tz)->startOfDay();
            $away = (int) $today->diffInDays($start, false);

            if (! in_array($away, $leads, true)) {
                continue;
            }

            $already = array_filter(explode(',', (string) ($row->reminders_sent ?? '')));
            if (in_array((string) $away, $already, true)) {
                continue;   // this lead time has already gone out
            }

            $n = $this->remindFor($row, $away, $dry);
            $sent += $n;

            if (! $dry && $n > 0) {
                $already[] = (string) $away;
                DB::table('centre_closures')->where('id', $row->id)
                    ->update(['reminders_sent' => implode(',', array_unique($already))]);
            }
        }

        $this->info(($dry ? 'Dry run: ' : '') . "Closure reminders sent: {$sent}");

        return self::SUCCESS;
    }

    /** @return int how many messages went out */
    private function remindFor(object $row, int $away, bool $dry): int
    {
        $agencyId = (int) $row->agency_id;
        $centreId = (int) $row->centre_id;
        $dates = Closures::dateLabel($row);
        $reason = Closures::reason($row);

        // Families at the centre, and the staff who work there.
        $familyIds = DB::table('families')->where('centre_id', $centreId)->whereNull('deleted_at')->pluck('id');
        $parents = DB::table('users as u')->join('guardians as g', 'g.user_id', '=', 'u.id')
            ->whereIn('g.family_id', $familyIds)->whereNull('u.deleted_at')
            ->distinct()->get(['u.id', 'u.email', 'u.first_name', 'u.last_name']);

        $educators = DB::table('users as u')->join('role_assignments as ra', 'ra.user_id', '=', 'u.id')
            ->where('ra.active', true)->where('ra.centre_id', $centreId)
            ->whereIn('ra.role', ['educator', 'home_visitor'])
            ->whereNull('u.deleted_at')
            ->distinct()->get(['u.id', 'u.email', 'u.first_name', 'u.last_name']);

        // Seen by the people accountable, without a third version of the same letter.
        $bcc = DB::table('users as u')->join('role_assignments as ra', 'ra.user_id', '=', 'u.id')
            ->where('ra.active', true)->where('ra.agency_id', $agencyId)
            ->whereIn('ra.role', ['agency_admin', 'centre_director'])
            ->whereNull('u.deleted_at')
            ->distinct()->pluck('u.email')
            ->filter(fn ($e) => filter_var((string) $e, FILTER_VALIDATE_EMAIL))->unique()->values()->all();

        if ($dry) {
            $this->line(sprintf('  [dry] %-26s %-22s in %d day(s) → %d parents, %d educators, bcc %d',
                $row->centre_name, $dates, $away, $parents->count(), $educators->count(), count($bcc)));

            return $parents->count() + $educators->count();
        }

        $n = 0;
        foreach ([['parent', $parents], ['educator', $educators]] as [$role, $people]) {
            foreach ($people as $u) {
                if (! filter_var((string) $u->email, FILTER_VALIDATE_EMAIL)
                    || \App\Support\Suppression::isUser((int) $u->id)) {
                    continue;
                }
                try {
                    $this->send($agencyId, $u, $role, (string) $row->centre_name, $dates, $reason,
                        $away, (bool) $row->affects_billing, $bcc);
                    $n++;
                } catch (\Throwable $e) {
                    // One bad address must not stop the rest of the centre being told.
                    Log::warning('Closure reminder failed', [
                        'user' => $u->id, 'closure' => $row->id, 'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $n;
    }

    /** The letter itself, worded for who is reading it. */
    public function send(?int $agencyId, object $u, string $role, string $centreName, string $dates,
                         string $reason, int $away, bool $affectsBilling, array $bcc = [],
                         bool $bypassSuppression = false): void
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $when = $away === 0 ? 'today' : ($away === 1 ? 'tomorrow' : 'in ' . $away . ' days');
        $isParent = $role === 'parent';

        $lead = $isParent
            ? 'This is a reminder that <strong>' . $e($centreName) . '</strong> will be closed ' . $e($when) . '.'
            : 'A reminder that <strong>' . $e($centreName) . '</strong> is closed ' . $e($when) . ', so you are not expected in.';

        // What each reader actually needs to do about it.
        $note = $isParent
            ? ($affectsBilling
                ? 'Fees are unchanged for these days — the closure does not alter your invoice.'
                : 'You are not charged for these days; billing is paused for the closure.')
            : 'Sign-in and clock-in are switched off for these days, so they will not appear as missing hours on your timesheet.';

        $close = $isParent
            ? 'There is nothing you need to do — sign-in is switched off and we will see you when we reopen.'
            : 'If you believe you are scheduled to work during the closure, speak to your director before the date.';

        $body = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">'
            . '<tr><td style="font-size:15px;line-height:1.6;color:#334155;padding:0 0 12px;">' . $lead . '</td></tr>'
            . '<tr><td style="padding:6px 0;"><div style="background:#F1F5F9;border-radius:10px;padding:14px 16px;">'
            . '<div style="font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#64748B;">When</div>'
            . '<div style="font-size:16px;font-weight:700;color:#0F172A;margin:2px 0 10px;">' . $e($dates) . '</div>'
            . '<div style="font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#64748B;">Why</div>'
            . '<div style="font-size:15px;color:#0F172A;margin-top:2px;">' . $e($reason) . '</div></div></td></tr>'
            . '<tr><td style="padding:14px 0 0;font-size:14px;line-height:1.6;color:#334155;">' . $e($note) . '</td></tr>'
            . '<tr><td style="padding:10px 0 0;font-size:14px;line-height:1.6;color:#64748B;">' . $e($close) . '</td></tr>'
            . '</table>';

        $html = EmailTemplate::wrap($agencyId, $body, [
            'eyebrow' => $isParent ? 'CLOSURE REMINDER' : 'CLOSURE REMINDER · STAFF',
            'title' => $centreName . ' is closed ' . $when,
            'subtitle' => $dates . ' · ' . $reason,
            'preheader' => $centreName . ' closed ' . $dates . ' — ' . $reason,
        ]);

        $name = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? ''));
        $subject = 'Reminder: ' . $centreName . ' is closed ' . $when . ' (' . $dates . ')';

        AgencyMailer::forAgency($agencyId)->mailer()->html($html,
            function ($m) use ($u, $name, $subject, $bcc, $bypassSuppression) {
                $m->to($u->email, $name ?: null)->subject($subject);
                if ($bcc) {
                    $m->bcc($bcc);
                }
                if ($bypassSuppression) {
                    // Only ever set on the --test-to path: suppression exists to keep test
                    // traffic away from real families, and must stay in force for the real send.
                    try { $m->getHeaders()->addTextHeader('X-KT-Bypass-Suppression', '1'); } catch (\Throwable $e) {}
                }
            });
    }

    /**
     * One of each variant to a single address, so the wording can be reviewed without
     * mailing a centre. Writes nothing and marks nothing as sent.
     */
    private function sendSamples(string $to): int
    {
        $row = DB::table('centre_closures as cc')->join('centres as c', 'c.id', '=', 'cc.centre_id')
            ->orderByDesc('cc.closure_date')->first(['cc.*', 'c.name as centre_name', 'c.agency_id']);
        if (! $row) {
            $this->error('No closure to base a sample on.');

            return self::FAILURE;
        }

        $dates = Closures::dateLabel($row);
        $reason = Closures::reason($row);
        $u = (object) ['email' => $to, 'first_name' => 'Anthony', 'last_name' => 'Hosein'];

        foreach ([['parent', 7], ['educator', 7], ['parent', 1]] as [$role, $away]) {
            $this->send((int) $row->agency_id, $u, $role, (string) $row->centre_name, $dates,
                $reason, $away, (bool) $row->affects_billing, [], true);
            $this->line("  sent {$role} variant ({$away} day lead) to {$to}");
        }

        return self::SUCCESS;
    }
}
