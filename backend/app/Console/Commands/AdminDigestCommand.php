<?php

namespace App\Console\Commands;

use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use App\Support\AdminDigest;
use App\Support\AdminDigestHtml;
use App\Support\AgencyTime;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The director / admin digest: what needs a decision, daily or weekly.
 *
 * Sent only to people who can actually act on it — an agency admin or a centre director.
 * A digest full of approvals to somebody with no authority to approve is noise, and noise
 * is how a useful email becomes one that gets filtered.
 *
 * Nothing is sent when nothing needs doing. A daily "all clear" trains people to ignore
 * the one that matters.
 */
class AdminDigestCommand extends Command
{
    protected $signature = 'kiddietrac:admin-digest
        {--weekly : Cover the last seven days instead of today}
        {--agency= : Restrict to one agency}
        {--test= : Send to this address instead of the real recipients}
        {--force : Send even when there is nothing outstanding}';

    protected $description = 'Email directors and admins what needs their attention';

    public function handle(): int
    {
        $weekly = (bool) $this->option('weekly');
        $test = $this->option('test');

        $agencies = DB::table('agencies')
            ->when($this->option('agency'), fn ($q) => $q->where('id', (int) $this->option('agency')))
            ->get(['id', 'name']);

        $sent = 0;
        foreach ($agencies as $agency) {
            $tz = AgencyTime::tz((int) $agency->id) ?: 'America/Toronto';
            $to = Carbon::now($tz);
            $from = $weekly ? $to->copy()->subDays(6) : $to->copy();

            $sections = AdminDigest::gather((int) $agency->id, $from->toDateString(), $to->toDateString());
            if (! $sections && ! $this->option('force') && ! $test) {
                $this->line("  {$agency->name}: nothing outstanding");
                continue;
            }

            $periodLabel = $weekly
                ? 'this week at ' . $agency->name
                : 'today at ' . $agency->name;
            $body = AdminDigestHtml::render($sections, $periodLabel);

            $subject = ($weekly ? '📊 Weekly summary — ' : '📋 Daily summary — ') . $agency->name
                . ' · ' . $to->format('D j M');

            $html = EmailTemplate::wrap((int) $agency->id, $body, [
                'eyebrow' => $weekly ? 'WEEKLY SUMMARY' : 'DAILY SUMMARY',
                'title' => $weekly ? 'Your week at a glance' : 'Your day at a glance',
                'subtitle' => $agency->name . ' · ' . $to->format('l, j F Y'),
                'preheader' => self::preheader($sections),
            ]);

            foreach ($this->recipients((int) $agency->id, $test) as $r) {
                try {
                    AgencyMailer::forAgency((int) $agency->id)->mailer()->html($html, function ($m) use ($r, $subject, $test) {
                        $m->to($r->email, trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')))->subject($subject);
                        if ($test) {
                            // A test must reach the tester regardless of agency suppression.
                            $m->getHeaders()->addTextHeader('X-KT-Bypass-Suppression', '1');
                        }
                    });
                    $sent++;
                    $this->line('    sent to ' . $r->email);
                } catch (\Throwable $e) {
                    $this->warn('    failed ' . $r->email . ': ' . $e->getMessage());
                }
            }
        }

        $this->info("Sent {$sent} digest(s)");
        return self::SUCCESS;
    }

    /** The inbox snippet says the single most pressing thing, not "your daily summary". */
    private static function preheader(array $s): string
    {
        if (! empty($s['late']['count'])) { return $s['late']['count'] . ' late pick-up(s) awaiting your decision'; }
        if (! empty($s['timeoff']['count'])) { return $s['timeoff']['count'] . ' time-off request(s) to review'; }
        if (! empty($s['tickets']['count'])) { return $s['tickets']['count'] . ' open support ticket(s)'; }
        if (! empty($s['tasks']['count'])) { return $s['tasks']['count'] . ' task(s) still open'; }
        return 'What needs your attention';
    }

    /** @return iterable<object> */
    private function recipients(int $agencyId, ?string $test): iterable
    {
        if ($test) {
            return [(object) ['email' => $test, 'first_name' => 'Test', 'last_name' => 'Recipient']];
        }
        $centreIds = DB::table('centres')->where('agency_id', $agencyId)->pluck('id');
        return DB::table('users as u')
            ->join('role_assignments as ra', 'ra.user_id', '=', 'u.id')
            ->where('ra.active', 1)
            ->whereIn('ra.role', ['agency_admin', 'centre_director'])
            ->where(fn ($q) => $q->where('ra.agency_id', $agencyId)->orWhereIn('ra.centre_id', $centreIds))
            ->whereNull('u.deleted_at')->whereNotNull('u.email')
            ->distinct()->get(['u.email', 'u.first_name', 'u.last_name']);
    }
}
