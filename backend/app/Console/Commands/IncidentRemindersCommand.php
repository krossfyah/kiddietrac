<?php

namespace App\Console\Commands;

use App\Services\EmailTemplate;
use App\Services\FcmService;
use App\Support\AgencyTime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Chases every incident that is still open, every morning, until it is closed.
 *
 * An incident report is only useful if somebody acts on it, and the one thing this
 * platform could not do was notice that nobody had. A report filed on Friday and
 * left at "submitted" looked exactly like one filed an hour ago — no badge, no
 * chase, nothing. Amna's report sat unactioned because it never saved at all; the
 * next one will sit unactioned because it is Tuesday and everyone is busy.
 *
 * So: one email per centre, every morning, listing what is open and how long it has
 * been open, sorted worst-first. It goes to the directors of that centre and the
 * agency's admins, and it stops arriving when the list is empty — a daily email
 * that arrives when there is nothing to do is a daily email people filter away,
 * and then they miss the one that mattered.
 *
 * Ages are counted in the AGENCY's timezone, because "3 days old" should mean
 * three of their days.
 */
class IncidentRemindersCommand extends Command
{
    protected $signature = 'incidents:remind
                            {--dry : List what would be sent without sending it}
                            {--agency= : Limit to one agency}';

    protected $description = 'Email directors and admins every morning about incidents still waiting to be actioned';

    /** Anything not closed is still somebody\'s job. */
    private const OPEN = ['draft', 'submitted', 'director_reviewed', 'parent_notified', 'acknowledged'];

    /** Older than this and the row is called out rather than merely listed. */
    private const OVERDUE_DAYS = 2;

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');
        $onlyAgency = $this->option('agency') ? (int) $this->option('agency') : null;

        /* Grouped by CENTRE, not by agency: a director runs one centre and a list
           of other people's incidents is a list they learn to skip. */
        $rows = DB::table('incidents as i')
            ->join('children as c', 'c.id', '=', 'i.child_id')
            ->join('families as f', 'f.id', '=', 'c.family_id')
            ->join('centres as ce', 'ce.id', '=', 'f.centre_id')
            ->whereIn('i.status', self::OPEN)
            ->whereNull('c.deleted_at')
            ->when($onlyAgency, fn ($q) => $q->where('ce.agency_id', $onlyAgency))
            ->orderBy('i.occurred_at')
            ->get([
                'i.id', 'i.status', 'i.occurred_at', 'i.incident_type', 'i.severity',
                'i.is_serious_occurrence', 'i.created_at',
                'c.first_name', 'c.last_name',
                'ce.id as centre_id', 'ce.name as centre_name', 'ce.agency_id',
            ]);

        if ($rows->isEmpty()) {
            $this->info('Nothing open. No reminders sent.');

            return self::SUCCESS;
        }

        $byCentre = $rows->groupBy('centre_id');
        $sent = 0;
        $skipped = 0;

        foreach ($byCentre as $centreId => $items) {
            $agencyId = (int) $items->first()->agency_id;
            $centreName = (string) $items->first()->centre_name;
            $tz = AgencyTime::tz($agencyId) ?: 'America/Toronto';

            $recipients = $this->recipientsFor((int) $centreId, $agencyId);
            if ($recipients->isEmpty()) {
                $this->warn("  {$centreName}: " . $items->count() . ' open, but nobody to tell.');
                $skipped++;
                continue;
            }

            $oldest = 0;
            $serious = 0;
            $lines = '';
            /* Listed oldest-first and capped: a sixty-row email is one nobody reads,
               and then the row that mattered is missed with all the others. */
            $LIST_MAX = 15;
            $shown = 0;
            foreach ($items as $it) {
                /* created_at is a UTC instant; the age is measured in the agency's
                   own days, so "3 days old" means three of theirs. */
                $age = (int) now($tz)->startOfDay()->diffInDays(
                    \Illuminate\Support\Carbon::parse($it->created_at, 'UTC')->setTimezone($tz)->startOfDay()
                );
                $oldest = max($oldest, $age);
                if ($it->is_serious_occurrence) {
                    $serious++;
                }

                $overdue = $age >= self::OVERDUE_DAYS;
                $kid = trim(($it->first_name ?? '') . ' ' . ($it->last_name ?? '')) ?: 'A child';
                // occurred_at is WALL CLOCK — printed as stored, never converted.
                $when = $it->occurred_at
                    ? \Illuminate\Support\Carbon::parse($it->occurred_at)->format('j M, g:i A')
                    : '—';

                if ($shown >= $LIST_MAX) { continue; }
                $shown++;

                $lines .= '<tr>'
                    . '<td style="padding:7px 10px 7px 0;font-size:14px;color:#0B1A33;">'
                        . '<strong>' . e($kid) . '</strong>'
                        . ($it->is_serious_occurrence ? ' <span style="color:#B3261E;font-weight:800;font-size:11px;">SO</span>' : '')
                        . '<div style="font-size:12px;color:#64748B;">' . e(ucwords(str_replace('_', ' ', (string) $it->incident_type)))
                        . ' · ' . e($when) . '</div>'
                    . '</td>'
                    . '<td style="padding:7px 10px;font-size:13px;color:#475569;white-space:nowrap;">'
                        . e(ucwords(str_replace('_', ' ', (string) $it->status))) . '</td>'
                    . '<td style="padding:7px 0;font-size:13px;white-space:nowrap;text-align:right;color:'
                        . ($overdue ? '#B3261E;font-weight:700' : '#64748B') . ';">'
                        . ($age === 0 ? 'today' : ($age === 1 ? '1 day' : $age . ' days')) . '</td>'
                    . '</tr>';
            }

            $n = $items->count();
            $subject = ($serious > 0 ? 'URGENT: ' : '')
                . $n . ' incident report' . ($n === 1 ? '' : 's')
                . ' waiting — ' . $centreName;

            if ($dry) {
                $this->line("  {$centreName}: {$n} open (oldest {$oldest}d, {$serious} serious) → "
                    . $recipients->implode(', '));
                continue;
            }

            $body = $this->body($centreName, $n, $oldest, $serious, $lines, $shown);
            $html = EmailTemplate::wrap($agencyId, $body, [
                'eyebrow'   => $serious > 0 ? 'URGENT — ACTION NEEDED' : 'ACTION NEEDED',
                'title'     => $n . ' incident report' . ($n === 1 ? '' : 's') . ' waiting',
                'subtitle'  => $centreName,
                'preheader' => $n . ' open at ' . $centreName . ', oldest ' . $oldest . ' day(s).',
            ]);

            foreach ($recipients as $email) {
                try {
                    Mail::html($html, function ($m) use ($email, $subject, $agencyId) {
                        \App\Support\MailScope::agency($m, $agencyId);
                        $m->from(config('mail.from.address', 'noreply@kiddietrac.com'),
                                 config('mail.from.name', 'KiddieTrac'));
                        $m->to($email)->subject($subject);
                    });
                    $sent++;
                } catch (\Throwable $e) {
                    Log::warning('incident reminder failed', ['to' => $email, 'error' => $e->getMessage()]);
                }
            }

            /* And a push, for the ones that have been sitting. A daily email about
               something two days old has already been ignored once. */
            if ($oldest >= self::OVERDUE_DAYS || $serious > 0) {
                foreach ($this->userIdsFor((int) $centreId, $agencyId) as $uid) {
                    try {
                        app(FcmService::class)->sendToUser(
                            (int) $uid,
                            $serious > 0 ? '🚨 Serious occurrence still open' : '⏰ Incident reports waiting',
                            $n . ' open at ' . $centreName . ', oldest ' . $oldest . ' day'
                                . ($oldest === 1 ? '' : 's') . '. Tap to action them.',
                            '#incidents'
                        );
                    } catch (\Throwable $e) { /* the email is the record; push is a nudge */ }
                }
            }

            $this->info("  {$centreName}: {$n} open → " . $recipients->count() . ' recipient(s)');
        }

        $this->info($dry ? 'Dry run complete.' : "Sent {$sent} reminder(s)." . ($skipped ? " {$skipped} centre(s) had nobody to tell." : ''));

        return self::SUCCESS;
    }

    /** The people who can actually action it: this centre's directors, and the agency's admins. */
    private function recipientsFor(int $centreId, int $agencyId)
    {
        return DB::table('role_assignments as ra')
            ->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.active', true)
            ->whereNull('u.deleted_at')
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('ra.centre_id', $centreId)->where('ra.role', 'centre_director'))
                ->orWhere(fn ($w) => $w->where('ra.agency_id', $agencyId)->where('ra.role', 'agency_admin')))
            ->whereNotNull('u.email')->where('u.email', '!=', '')
            ->pluck('u.email')
            ->map(fn ($e) => mb_strtolower(trim((string) $e)))
            ->unique()->values();
    }

    private function userIdsFor(int $centreId, int $agencyId): array
    {
        return DB::table('role_assignments as ra')
            ->where('ra.active', true)
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('ra.centre_id', $centreId)->where('ra.role', 'centre_director'))
                ->orWhere(fn ($w) => $w->where('ra.agency_id', $agencyId)->where('ra.role', 'agency_admin')))
            ->pluck('ra.user_id')->unique()->values()->all();
    }

    private function body(string $centreName, int $n, int $oldest, int $serious, string $lines, int $shown = 0): string
    {
        $p = fn ($t) => '<p style="margin:0 0 12px;font-size:15px;line-height:1.6;color:#2A3D5F;">' . $t . '</p>';

        $lead = $serious > 0
            ? $p('<strong style="color:#B3261E;">' . $serious . ' of these is a serious occurrence.</strong> '
                . 'Reporting obligations may apply and the clock is running.')
            : '';

        $age = $oldest >= self::OVERDUE_DAYS
            ? $p('The oldest has been waiting <strong>' . $oldest . ' days</strong>.')
            : '';

        return $p('These incident reports at <strong>' . e($centreName) . '</strong> are still open. '
                . 'Each one needs reviewing, the family needs telling, and then it can be closed.')
            . $lead
            . $age
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:100%;margin:16px 0 4px;border-top:1px solid #E4E8EF;">'
            . '<tr>'
                . '<th style="text-align:left;padding:8px 10px 8px 0;font-size:11px;letter-spacing:.6px;text-transform:uppercase;color:#64748B;border-bottom:1px solid #E4E8EF;">Child</th>'
                . '<th style="text-align:left;padding:8px 10px;font-size:11px;letter-spacing:.6px;text-transform:uppercase;color:#64748B;border-bottom:1px solid #E4E8EF;">Status</th>'
                . '<th style="text-align:right;padding:8px 0;font-size:11px;letter-spacing:.6px;text-transform:uppercase;color:#64748B;border-bottom:1px solid #E4E8EF;">Waiting</th>'
            . '</tr>'
            . $lines
            . ($n > $shown
                ? '<tr><td colspan="3" style="padding:10px 0 2px;font-size:13px;color:#64748B;">'
                  . '…and ' . ($n - $shown) . ' more. Open the portal to see them all.</td></tr>'
                : '')
            . '</table>'
            . '<p style="margin:24px 0 0;"><a href="https://app.kiddietrac.com/dashboard.html#incidents" '
            . 'style="display:inline-block;background:#1F6FB2;color:#ffffff;text-decoration:none;font-weight:700;'
            . 'font-size:15px;padding:13px 26px;border-radius:10px;">Action these now</a></p>'
            . '<p style="margin:18px 0 0;font-size:13px;color:#64748B;line-height:1.6;">'
            . 'This arrives every morning while anything is open, and stops as soon as the list is empty.</p>';
    }
}
