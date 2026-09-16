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
        {--days= : override the configured lead times for this run, e.g. 5,3,1}
        {--test-to= : send one of each role variant to this address and write nothing}
        {--dry-run : list what would be sent}';

    protected $description = 'Remind parents and educators about an upcoming closure';

    public function handle(): int
    {
        $testTo = (string) ($this->option('test-to') ?? '');
        if ($testTo !== '') {
            return $this->sendSamples($testTo);
        }

        // --days is an override for a manual run; normally each agency's own setting
        // decides, so one agency reminding at 5/3/1 and another at 14/2 both work.
        $override = $this->option('days') !== null && $this->option('days') !== ''
            ? array_values(array_filter(array_map(
                fn ($d) => (int) trim($d),
                explode(',', (string) $this->option('days'))
            ), fn ($d) => $d > 0))
            : [];

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

            $cfg = $this->agencySettings((int) $row->agency_id);
            if (! $cfg['enabled']) {
                continue;   // this agency has closure reminders switched off
            }

            /* A generated statutory holiday gets its own lead time -- one notice, the day
               before, by default. The generic 5/3/1 would send three letters about
               Christmas Day, which is nagging rather than reminding. */
            $isHoliday = $row->closure_type === \App\Console\Commands\HolidaySyncCommand::GENERATED_TYPE;
            if ($isHoliday) {
                $hcfg = \App\Console\Commands\HolidaySyncCommand::configFor(
                    DB::table('agencies')->where('id', $row->agency_id)->first()
                );
                if (! $hcfg['enabled']) {
                    continue;   // holiday closures exist but announcements are switched off
                }
            }
            $leads = $override ?: ($isHoliday ? $hcfg['notice_days'] : $cfg['days']);

            /* A holiday's lead time is counted in WORKING days at this centre, so "1" is
               the last day anyone is in before the holiday. Counted in calendar days, the
               notice for a Monday holiday fell on the Sunday, when the centre is shut and
               nobody reads it -- and most statutory holidays are Mondays. */
            $matched = null;
            if ($isHoliday) {
                foreach ($leads as $lead) {
                    if ($this->noticeDateFor((int) $row->centre_id, $start, (int) $lead) === $today->toDateString()) {
                        $matched = (int) $lead;
                        break;
                    }
                }
                if ($matched === null) {
                    continue;
                }
            } elseif (! in_array($away, $leads, true)) {
                continue;
            }

            $already = array_filter(explode(',', (string) ($row->reminders_sent ?? '')));
            /* Business-day leads are stamped "b1" so they cannot be confused with the
               calendar-day "1" a generic closure records -- the two mean different days. */
            $stamp = $isHoliday ? ('b' . $matched) : (string) $away;
            if (in_array($stamp, $already, true)) {
                continue;   // this lead time has already gone out
            }

            $n = $this->remindFor($row, $away, $dry);
            $sent += $n;

            if (! $dry && $n > 0) {
                $already[] = $stamp;
                DB::table('centre_closures')->where('id', $row->id)
                    ->update(['reminders_sent' => implode(',', array_unique($already))]);
            }
        }

        $this->info(($dry ? 'Dry run: ' : '') . "Closure reminders sent: {$sent}");

        return self::SUCCESS;
    }

    /**
     * The agency's closure-reminder settings, defaulted the same way the settings screen
     * shows them. An agency that has never touched the screen reminds at 5, 3 and 1 days.
     *
     * @return array{enabled:bool,days:int[]}
     */
    private function agencySettings(int $agencyId): array
    {
        static $cache = [];
        if (isset($cache[$agencyId])) {
            return $cache[$agencyId];
        }
        $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $s = $raw ? (json_decode($raw, true) ?: []) : [];

        $days = array_values(array_filter(array_map(
            fn ($d) => (int) trim((string) $d),
            explode(',', (string) ($s['closure_reminder_days'] ?? '5,3,1'))
        ), fn ($d) => $d > 0));

        return $cache[$agencyId] = [
            'enabled' => ($s['closure_reminders_enabled'] ?? true) !== false,
            'days' => $days ?: [5, 3, 1],
        ];
    }

    /** @return int how many messages went out */
    private function remindFor(object $row, int $away, bool $dry): int
    {
        $agencyId = (int) $row->agency_id;
        $centreId = (int) $row->centre_id;
        $dates = Closures::dateLabel($row);
        $reason = Closures::reason($row);

        /* What the day is for. Blank for an ordinary closure, which is correct -- there is
           nothing warm to say about a boiler failure. */
        $meaning = $row->closure_type === \App\Console\Commands\HolidaySyncCommand::GENERATED_TYPE
            ? \App\Support\StatHolidays::meaningOf($reason)
            : '';

        // Who the family is actually with. See the note on providerNameFor().
        $provider = $this->providerNameFor($centreId);

        // Families at the centre, and the staff who work there.
        $familyIds = DB::table('families')->where('centre_id', $centreId)->whereNull('deleted_at')->pluck('id');
        // Deborah Black is deactivated and was still on this list — a switched-off
        // account is not soft-deleted, so deleted_at alone does not catch it.
        $parents = \App\Support\Audience::excludeOff(
            DB::table('users as u')->join('guardians as g', 'g.user_id', '=', 'u.id')
                ->whereIn('g.family_id', $familyIds)->whereNull('u.deleted_at'))
            ->distinct()->get(['u.id', 'u.email', 'u.first_name', 'u.last_name']);

        $educators = \App\Support\Audience::excludeOff(
            DB::table('users as u')->join('role_assignments as ra', 'ra.user_id', '=', 'u.id')
                ->where('ra.active', true)->where('ra.centre_id', $centreId)
                ->whereIn('ra.role', ['educator', 'home_visitor'])
                ->whereNull('u.deleted_at'))
            ->distinct()->get(['u.id', 'u.email', 'u.first_name', 'u.last_name']);

        /* Seen by the people accountable, without a third version of the same letter.
           Resolved through the one shared helper (2026-08-25) rather than a local copy:
           the inline version required ra.agency_id on BOTH roles, so a centre_director
           whose role row carries centre_id and no agency_id was silently never copied. */
        $bcc = \App\Support\MailOversight::bccFor(
            $agencyId, $centreId,
            $parents->pluck('email')->merge($educators->pluck('email'))->all()
        );

        if ($dry) {
            $this->line(sprintf('  [dry] %-26s %-22s in %d day(s) → %d parents, %d educators, bcc %d',
                $row->centre_name, $dates, $away, $parents->count(), $educators->count(), count($bcc)));

            return $parents->count() + $educators->count();
        }

        $n = 0;
        /* The blind copy rides on the FIRST message only. It used to be attached inside
           this loop, so a centre with ten families sent its director ten identical copies
           of one reminder - oversight becomes a filter rule at that point. */
        $bccIndex = 0;
        foreach ([['parent', $parents], ['educator', $educators]] as [$role, $people]) {
            foreach ($people as $u) {
                if (! filter_var((string) $u->email, FILTER_VALIDATE_EMAIL)
                    || \App\Support\Suppression::isUser((int) $u->id)) {
                    continue;
                }
                try {
                    $this->send($agencyId, $u, $role, (string) $row->centre_name, $dates, $reason,
                        $away, (bool) $row->affects_billing,
                        \App\Support\MailOversight::firstOnly($bcc, $bccIndex++),
                        false, $meaning, $provider,
                        $meaning !== '' ? substr((string) $row->closure_date, 0, 10) : '');
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

    /**
     * The date a holiday notice should go out: $n working days before the closure.
     *
     * Working is judged against the CENTRE's own open days, not Monday-to-Friday, because
     * they differ -- a Monday-to-Thursday centre's last working day before a Monday
     * holiday is the Thursday. Days that are themselves closures are stepped over as well,
     * so the Boxing Day notice in a Christmas week does not get scheduled for Christmas
     * Day itself.
     *
     * Returns null if no working day can be found within a fortnight, and the caller then
     * simply does not send -- inventing a date would put the notice somewhere arbitrary.
     */
    private function noticeDateFor(int $centreId, Carbon $closureStart, int $n): ?string
    {
        if ($n < 1) {
            $n = 1;
        }

        static $meta = [];
        if (! isset($meta[$centreId])) {
            $centre = DB::table('centres')->where('id', $centreId)->first(['settings']);
            $cs = json_decode((string) ($centre->settings ?? ''), true);
            $open = is_array($cs) ? ($cs['open_days'] ?? null) : null;
            if (! is_array($open) || ! count($open)) {
                $open = [1, 2, 3, 4, 5];
            }
            $meta[$centreId] = [
                'open' => array_map('intval', $open),
                'closed' => DB::table('centre_closures')->where('centre_id', $centreId)
                    ->pluck('end_date', 'closure_date')->toArray(),
            ];
        }
        $open = $meta[$centreId]['open'];
        $closures = $meta[$centreId]['closed'];

        $isClosed = function (Carbon $d) use ($closures) {
            $s = $d->toDateString();
            foreach ($closures as $from => $to) {
                $f = substr((string) $from, 0, 10);
                $t = substr((string) ($to ?: $from), 0, 10);
                if ($s >= $f && $s <= $t) {
                    return true;
                }
            }

            return false;
        };

        $d = $closureStart->copy()->startOfDay();
        $found = 0;
        for ($i = 0; $i < 21; $i++) {
            $d->subDay();
            if (! in_array((int) $d->isoWeekday(), $open, true)) {
                continue;
            }
            if ($isClosed($d)) {
                continue;
            }
            if (++$found === $n) {
                return $d->toDateString();
            }
        }

        return null;
    }

    /**
     * The person a family is with at this centre.
     *
     * The centre's supervisor field is preferred, and for a home provider -- which is
     * every centre at iLearn -- it IS the answer: centre #7 is Bruni Meeser's home and
     * her name is on the record. educator_rooms is only the fallback, because it is
     * currently noisy: one agency staff member is attached to every room, so building the
     * sentence from it would tell all nine providers' families they are also with her.
     *
     * Returns '' when nothing can be said with confidence, and the letter then simply
     * omits the line rather than guessing at it.
     */
    private function providerNameFor(int $centreId): string
    {
        static $cache = [];
        if (array_key_exists($centreId, $cache)) {
            return $cache[$centreId];
        }

        $c = DB::table('centres')->where('id', $centreId)
            ->first(['supervisor_first_name', 'supervisor_last_name']);
        $name = trim(($c->supervisor_first_name ?? '') . ' ' . ($c->supervisor_last_name ?? ''));
        if ($name !== '') {
            return $cache[$centreId] = $name;
        }

        $eds = \App\Support\Audience::excludeOff(
            DB::table('users as u')->join('role_assignments as ra', 'ra.user_id', '=', 'u.id')
                ->where('ra.active', true)->where('ra.centre_id', $centreId)
                ->where('ra.role', 'educator')->whereNull('u.deleted_at'))
            ->distinct()->limit(3)->get(['u.first_name', 'u.last_name'])
            ->map(fn ($u) => trim($u->first_name . ' ' . $u->last_name))
            ->filter()->values()->all();

        return $cache[$centreId] = count($eds) === 1 ? $eds[0] : '';
    }

    /** The letter itself, worded for who is reading it. */
    public function send(?int $agencyId, object $u, string $role, string $centreName, string $dates,
                         string $reason, int $away, bool $affectsBilling, array $bcc = [],
                         bool $bypassSuppression = false, string $meaning = '', string $provider = '',
                         string $closureDate = ''): void
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $when = $away === 0 ? 'today' : ($away === 1 ? 'tomorrow' : 'in ' . $away . ' days');
        $isParent = $role === 'parent';

        /* Naming the day beats counting to it. A notice sent on Friday about a Monday
           holiday reads "closed on Monday"; "closed in 3 days" makes the reader do
           arithmetic to reach the same fact. Only for holidays, where the notice lands on
           the last working day and the gap is usually a weekend. */
        if ($closureDate !== '' && $away > 1) {
            try {
                $when = 'on ' . Carbon::parse($closureDate)->format('l');
            } catch (\Throwable $e) {
                // keep the counted form
            }
        }

        $isHoliday = $meaning !== '';

        /* A holiday opens differently from a burst pipe. Same facts, but a family reading
           "closed tomorrow" about Christmas should not get the same sentence they would
           get about an emergency. */
        if ($isHoliday) {
            $lead = $isParent
                ? 'A gentle reminder that <strong>' . $e($centreName) . '</strong> will be closed '
                    . $e($when) . ' for <strong>' . $e($reason) . '</strong>.'
                : '<strong>' . $e($centreName) . '</strong> is closed ' . $e($when) . ' for <strong>'
                    . $e($reason) . '</strong>, so you are not expected in.';
        } else {
            $lead = $isParent
                ? 'This is a reminder that <strong>' . $e($centreName) . '</strong> will be closed ' . $e($when) . '.'
                : 'A reminder that <strong>' . $e($centreName) . '</strong> is closed ' . $e($when) . ', so you are not expected in.';
        }

        // What each reader actually needs to do about it.
        //
        // Deliberately not a promise about the invoice. affects_billing records the
        // intention behind the closure; no invoicing code reads it, so stating a definite
        // outcome here would claim something the system does not do. Both variants say the
        // same true thing — an adjustment is made where it applies, on the terms already
        // agreed — and differ only in which way they lean.
        $note = $isParent
            ? ($affectsBilling
                ? 'Fees for these days are adjusted where applicable, in line with the agreement between your '
                    . 'family and us. Anything owing or credited will appear on your next invoice.'
                : 'Your usual fees continue to apply for these days. Where an adjustment is due under the '
                    . 'agreement between your family and us, it will appear on your next invoice.')
            : 'Sign-in and clock-in are switched off for these days, so they will not appear as missing hours on your timesheet.';

        $close = $isParent
            ? 'There is nothing you need to do — sign-in is switched off and we will see you when we reopen.'
            : 'If you believe you are scheduled to work during the closure, speak to your director before the date.';

        /* Who they are with. Named for a parent because "your provider" is a person to
           them; an educator already knows where they work. */
        $withWhom = ($isParent && $provider !== '')
            ? '<tr><td style="padding:0 0 12px;font-size:15px;line-height:1.6;color:#334155;">'
                . 'Your child is with <strong>' . $e($provider) . '</strong>, and they will be back to '
                . 'their usual hours the day after.</td></tr>'
            : '';

        /* The meaning of the day, set apart so it reads as a note rather than as more
           logistics. Only ever present for a statutory holiday. */
        $meaningBlock = $isHoliday
            ? '<tr><td style="padding:14px 0 2px;"><div style="border-left:3px solid #8EC73C;background:#F6FBEF;'
                . 'border-radius:0 10px 10px 0;padding:14px 16px;">'
                . '<div style="font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#4D7C0F;">'
                . 'About ' . $e($reason) . '</div>'
                . '<div style="font-size:14.5px;line-height:1.65;color:#1F2937;margin-top:6px;">' . $e($meaning) . '</div>'
                . '</div></td></tr>'
            : '';

        $body = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">'
            . '<tr><td style="font-size:15px;line-height:1.6;color:#334155;padding:0 0 12px;">' . $lead . '</td></tr>'
            . $withWhom
            . '<tr><td style="padding:6px 0;"><div style="background:#F1F5F9;border-radius:10px;padding:14px 16px;">'
            . '<div style="font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#64748B;">When</div>'
            . '<div style="font-size:16px;font-weight:700;color:#0F172A;margin:2px 0 10px;">' . $e($dates) . '</div>'
            . '<div style="font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#64748B;">Why</div>'
            . '<div style="font-size:15px;color:#0F172A;margin-top:2px;">' . $e($reason) . '</div></div></td></tr>'
            . $meaningBlock
            . '<tr><td style="padding:14px 0 0;font-size:14px;line-height:1.6;color:#334155;">' . $e($note) . '</td></tr>'
            . '<tr><td style="padding:10px 0 0;font-size:14px;line-height:1.6;color:#64748B;">' . $e($close) . '</td></tr>'
            . '</table>';

        $html = EmailTemplate::wrap($agencyId, $body, [
            'eyebrow' => $isHoliday
                ? ('HOLIDAY CLOSURE' . ($isParent ? '' : ' · STAFF'))
                : ($isParent ? 'CLOSURE REMINDER' : 'CLOSURE REMINDER · STAFF'),
            'title' => $isHoliday
                ? ($reason . ' — we are closed ' . $when)
                : ($centreName . ' is closed ' . $when),
            'subtitle' => $dates . ' · ' . ($isHoliday ? $centreName : $reason),
            'preheader' => $centreName . ' closed ' . $dates . ' — ' . $reason,
        ]);

        $name = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? ''));
        $subject = $isHoliday
            ? ($reason . ': ' . $centreName . ' is closed ' . $when . ' (' . $dates . ')')
            : ('Reminder: ' . $centreName . ' is closed ' . $when . ' (' . $dates . ')');

        AgencyMailer::forAgency($agencyId)->html($html,
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

        /* The holiday variants, built from the next real statutory holiday rather than
           from a database row -- so the sample can be reviewed before the feature has been
           switched on for anybody, which is the point at which somebody wants to see it. */
        $agencyId = (int) $row->agency_id;
        $agency = DB::table('agencies')->where('id', $agencyId)->first();
        $cfg = \App\Console\Commands\HolidaySyncCommand::configFor($agency);
        $tz = $agency->timezone ?: 'America/Toronto';
        $next = \App\Support\StatHolidays::between(
            Carbon::now($tz)->startOfDay(),
            Carbon::now($tz)->addMonths(14),
            $cfg['country'],
            $cfg['optional']
        );
        if (! $next) {
            $this->warn('  no upcoming statutory holiday to build a sample from');

            return self::SUCCESS;
        }

        $h = $next[0];
        $when = Carbon::parse($h['observed'] ?? $h['date'], $tz);
        $label = $when->format('D j M Y');
        // A real centre from this agency, so the provider line shows a real name.
        $centre = DB::table('centres')->where('agency_id', $agencyId)->whereNull('deleted_at')
            ->orderBy('id')->first(['id', 'name']);
        $provider = $centre ? $this->providerNameFor((int) $centre->id) : '';

        /* The real gap between the notice and the holiday, not a hardcoded 1. The notice
           goes out on the last working day, so a Monday holiday is announced on the Friday
           and the letter says "closed on Monday" -- which is what the sample must show. */
        $holidayAway = 1;
        $noticeOn = null;
        if ($centre) {
            $nd = $this->noticeDateFor((int) $centre->id, $when->copy(), 1);
            if ($nd) {
                $noticeOn = $nd;
                $holidayAway = (int) Carbon::parse($nd, $tz)->startOfDay()->diffInDays($when->copy()->startOfDay(), false);
            }
        }

        foreach ([['parent'], ['educator']] as [$role]) {
            $this->send($agencyId, $u, $role, (string) ($centre->name ?? $row->centre_name), $label,
                $h['name'], $holidayAway, false, [], true, $h['meaning'], $provider,
                $h['observed'] ?? $h['date']);
            $this->line(sprintf('  sent %s HOLIDAY variant (%s, %s) to %s%s',
                $role, $h['name'], $label, $to,
                $noticeOn ? ' — as it would go out on ' . Carbon::parse($noticeOn)->format('D j M') : ''));
        }

        return self::SUCCESS;
    }
}
