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

            /* One digest for the agency's admins, and one per centre director built from
               THEIR centres only (2026-10-09) — the same email used to go to both, so a
               director read every centre's items. A test send gets the admin version. */
            foreach ($this->audiences((int) $agency->id, $test) as [$scope, $people]) {
            $sections = AdminDigest::gather((int) $agency->id, $from->toDateString(), $to->toDateString(),
                ($scope === null ? [] : ['centres' => $scope]) + ['weekly' => $weekly]);
            if (! $sections && ! $this->option('force') && ! $test) {
                $this->line("  {$agency->name}" . ($scope === null ? '' : ' (centres ' . implode(',', $scope) . ')') . ': nothing outstanding');
                continue;
            }

            $periodLabel = $weekly
                ? 'this week at ' . $agency->name
                : 'today at ' . $agency->name;
            $body = AdminDigestHtml::render($sections, $periodLabel);
            // The same rotating quote the parent and educator summaries carry, so the
            // three emails feel like they come from one product rather than three.
            $body .= EmailTemplate::dailyQuote((int) $to->format('Ymd'));

            $subject = ($weekly ? '📊 Weekly summary — ' : '📋 Daily summary — ') . $agency->name
                . ' · ' . $to->format('D j M');

            $html = EmailTemplate::wrap((int) $agency->id, $body, [
                'eyebrow' => $weekly ? 'WEEKLY SUMMARY' : 'DAILY SUMMARY',
                'title' => $weekly ? 'Your week at a glance' : 'Your day at a glance',
                'subtitle' => $agency->name . ' · ' . $to->format('l, j F Y'),
                'preheader' => self::preheader($sections),
            ]);

            foreach ($people as $r) {
                try {
                    AgencyMailer::forAgency((int) $agency->id)->html($html, function ($m) use ($r, $subject, $test, $agency) {
                        // Engagement mail: withheld from accounts nobody has claimed.
                        try { $m->getHeaders()->addTextHeader('X-KT-Engagement', '1'); }
                        catch (\Throwable $e) {}
                        // Say which agency this belongs to. Without it the mail layer falls
                        // back to resolving a tenant from the recipient's ADDRESS, and an
                        // address that exists in two agencies then resolves to whichever was
                        // found first — which is how one agency's send was logged under
                        // another's audit trail. A sender that knows its agency must say so.
                        try { $m->getHeaders()->addTextHeader('X-KT-Agency-Id', (string) (int) $agency->id); }
                        catch (\Throwable $e) {}
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
        // Below the urgent four, above the fallback: a record nobody has read is
        // real work outstanding, but it is not today's emergency.
        if (! empty($s['immunRecords']['count'])) {
            return $s['immunRecords']['count'] . ' immunisation record(s) waiting to be filled in';
        }
        return 'What needs your attention';
    }

    /**
     * [centre scope or null for agency-wide, recipients] pairs. Anyone holding an
     * agency-wide role gets the agency digest; a person who is ONLY a centre director
     * gets one covering their centres (people with the same set of centres share it).
     *
     * @return array<int,array{0:?array,1:iterable}>
     */
    private function audiences(int $agencyId, ?string $test): array
    {
        $all = collect($this->recipients($agencyId, $test));
        if ($test) {
            return [[null, $all]];
        }
        $wide = DB::table('role_assignments')->where('active', 1)->where('agency_id', $agencyId)
            ->whereIn('role', ['agency_admin', 'platform_admin'])->distinct()->pluck('user_id')->flip();
        $open = array_flip(\App\Support\Live::centreIds($agencyId));
        $out = [[null, $all->filter(fn ($r) => isset($wide[$r->id]))->values()]];
        $groups = [];
        foreach ($all as $r) {
            if (isset($wide[$r->id])) continue;
            $cs = DB::table('role_assignments')->where('user_id', $r->id)->where('active', 1)->where('role', 'centre_director')
                ->whereNotNull('centre_id')->pluck('centre_id')->map(fn ($v) => (int) $v)
                ->filter(fn ($id) => isset($open[$id]))->unique()->sort()->values()->all();
            if (! $cs) continue;   // no open centre: nothing of theirs to report
            $groups[implode(',', $cs)][] = $r;
        }
        foreach ($groups as $key => $people) {
            $out[] = [array_map('intval', explode(',', $key)), $people];
        }

        return array_values(array_filter($out, fn ($a) => count($a[1]) > 0));
    }

    /** @return iterable<object> */
    private function recipients(int $agencyId, ?string $test): iterable
    {
        if ($test) {
            return [(object) ['email' => $test, 'first_name' => 'Test', 'last_name' => 'Recipient']];
        }
        $centreIds = collect(\App\Support\Live::centreIds($agencyId));
        return DB::table('users as u')
            ->join('role_assignments as ra', 'ra.user_id', '=', 'u.id')
            ->where('ra.active', 1)
            ->whereIn('ra.role', ['agency_admin', 'centre_director'])
            ->where(fn ($q) => $q->where('ra.agency_id', $agencyId)->orWhereIn('ra.centre_id', $centreIds))
            ->whereNull('u.deleted_at')->whereNotNull('u.email')
            ->distinct()->get(['u.id', 'u.email', 'u.first_name', 'u.last_name']);
    }
}
