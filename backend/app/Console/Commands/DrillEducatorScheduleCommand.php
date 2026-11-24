<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use App\Support\AgencyTime;
use App\Support\Audience;
use App\Support\DrillSchedule;
use App\Support\Live;
use App\Support\Notify;
use App\Support\StaffTopicNotify;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * drills:educator-schedule — every 5 minutes. EACH EDUCATOR'S FIRE DRILL SCHEDULE (2026-11-09, see DrillSchedule).
 *
 * Three notices, each switched on or off on the Drill log → Settings (agencies.settings.drill_schedule_notify):
 *   remind   the educator, before their drill (lead time: 30 min, 1 hour, the morning of, the afternoon before);
 *   upcoming their centre's directors + the agency admins, the morning of the drill;
 *   missed   the same people, 2 hours after a scheduled drill with no fire drill recorded at that centre since that day began.
 * Every recipient also passes StaffTopicNotify (the per-role "drills" switch). One send per person + occurrence + kind
 * (drill_schedule_sends), and nothing about an occurrence from before the schedule was saved.
 */
final class DrillEducatorScheduleCommand extends Command
{
    protected $signature = 'drills:educator-schedule {--agency=} {--dry : list what would be sent, change nothing} {--now= : pretend it is this agency time, "Y-m-d H:i" (testing)}';
    protected $description = 'Remind educators before their scheduled fire drill; tell directors/admins about upcoming and missed ones';

    private const GRACE_MIN = 120;

    public function handle(): int
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('drill_staff_dates', 'sched_repeat')) return self::SUCCESS;
        $q = DB::table('agencies')->whereNull('deleted_at');
        if ($this->option('agency')) $q->where('id', (int) $this->option('agency'));
        $dry = (bool) $this->option('dry');

        foreach ($q->pluck('id') as $agencyId) {
            $agencyId = (int) $agencyId;
            $set = DrillSchedule::notifySettings($agencyId);
            if (! $set['educator_reminder'] && ! $set['admin_upcoming'] && ! $set['admin_missed']) continue;
            $tz = AgencyTime::tz($agencyId);
            $now = $this->option('now') ? Carbon::createFromFormat('Y-m-d H:i', (string) $this->option('now'), $tz) : Carbon::now($tz);
            $live = Live::centreIds($agencyId);
            if (! $live) continue;

            $rows = DB::table('drill_staff_dates as d')->join('users as u', 'u.id', '=', 'd.user_id')
                ->where('d.agency_id', $agencyId)->whereNotNull('d.sched_repeat')->whereNull('u.deleted_at')
                ->get(['d.*', 'u.first_name', 'u.email']);
            foreach ($rows as $r) {
                if (! DrillSchedule::has($r)) continue;
                // Still on staff at a live centre of this agency; their first such centre is where the drill is.
                $centres = DB::table('role_assignments')->where('user_id', $r->user_id)->where('active', true)
                    ->whereIn('role', ['educator', 'centre_director'])->whereIn('centre_id', $live)->pluck('centre_id')->map(fn ($c) => (int) $c)->unique()->values()->all();
                if (! $centres) continue;
                $since = Carbon::parse((string) $r->updated_at, 'UTC')->setTimezone($tz);
                $who = trim((string) $r->first_name) ?: 'An educator';
                $centre = (string) DB::table('centres')->where('id', $centres[0])->value('name');
                if (isset(\App\Support\DrillCadence::off($centres[0])['fire'])) continue;   // fire drill not required there

                // The coming drill: the educator's reminder and the admins' morning-of notice.
                $next = DrillSchedule::next($r, $now, $tz);
                if ($next && $next->gt($since)) {
                    if ($set['educator_reminder'] && DrillSchedule::remindAt($next, $set['educator_lead'])->lte($now)) {
                        $this->send($dry, $agencyId, $r, $next, 'remind', [(int) $r->user_id], 'drill_scheduled',
                            'Fire drill ' . $this->when($next, $now), 'Your fire drill at ' . $centre . ' is ' . $this->when($next, $now) . '. Record it in the Drill log when it is done.',
                            'Fire drill ' . $this->when($next, $now) . ' at ' . $centre,
                            '<p style="margin:0 0 10px;font-size:15px;color:#334155">Hi ' . e($who) . ', your fire drill at <b>' . e($centre) . '</b> is scheduled for <b>' . e($next->format('l, F j \a\t g:i A')) . '</b>.</p>'
                            . '<p style="margin:0;font-size:14px;color:#334155">When it is done, record it in KiddieTrac → Drill log so it is kept for licensing.</p>');
                    }
                    if ($set['admin_upcoming'] && $next->isSameDay($now) && DrillSchedule::remindAt($next, 'morning')->lte($now)) {
                        $this->send($dry, $agencyId, $r, $next, 'upcoming', $this->managers($agencyId, $centres, (int) $r->user_id), 'drill_scheduled',
                            'Fire drill today — ' . $centre, $who . ' has a fire drill scheduled at ' . $next->format('g:i A') . ' today (' . $centre . ').',
                            'Fire drill today at ' . $centre . ' (' . $who . ', ' . $next->format('g:i A') . ')',
                            '<p style="margin:0;font-size:15px;color:#334155"><b>' . e($who) . '</b> has a fire drill scheduled at <b>' . e($centre) . '</b> today at <b>' . e($next->format('g:i A')) . '</b>.</p>');
                    }
                }

                // The last drill: was it recorded?
                if ($set['admin_missed']) {
                    $prev = DrillSchedule::next($r, $now->copy()->subHours(48), $tz);
                    if ($prev && $prev->gt($since) && $prev->copy()->addMinutes(self::GRACE_MIN)->lte($now)) {
                        $done = DB::table('drills')->whereIn('centre_id', $centres)->where('type', 'fire')->whereNull('deleted_at')
                            ->where('drilled_at', '>=', $prev->copy()->startOfDay()->utc())->exists();
                        if (! $done) {
                            $this->send($dry, $agencyId, $r, $prev, 'missed', $this->managers($agencyId, $centres, (int) $r->user_id), 'drill_missed',
                                'Fire drill not recorded — ' . $centre, $who . '\'s fire drill was scheduled for ' . $prev->format('D, M j \a\t g:i A') . ' and no fire drill has been recorded at ' . $centre . ' since.',
                                'Fire drill not recorded at ' . $centre . ' (' . $who . ', ' . $prev->format('M j') . ')',
                                '<p style="margin:0 0 10px;font-size:15px;color:#334155"><b>' . e($who) . '</b>\'s fire drill at <b>' . e($centre) . '</b> was scheduled for <b>' . e($prev->format('l, F j \a\t g:i A')) . '</b>, and no fire drill has been recorded there since.</p>'
                                . '<p style="margin:0;font-size:14px;color:#334155">If it was done, record it in KiddieTrac → Drill log. If not, please reschedule it.</p>');
                        }
                    }
                }
            }
        }

        return self::SUCCESS;
    }

    private function when(Carbon $at, Carbon $now): string
    {
        if ($at->isSameDay($now)) return 'today at ' . $at->format('g:i A');
        if ($at->isSameDay($now->copy()->addDay())) return 'tomorrow at ' . $at->format('g:i A');

        return 'on ' . $at->format('D, M j \a\t g:i A');
    }

    /** Directors of these centres and the agency admins, minus the educator, who may hear about drills. */
    private function managers(int $agencyId, array $centres, int $exclude): array
    {
        $q = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')->where('ra.active', true)->whereNull('u.deleted_at')
            ->where(fn ($w) => $w->where(fn ($x) => $x->where('ra.role', 'centre_director')->whereIn('ra.centre_id', $centres))
                ->orWhere(fn ($x) => $x->where('ra.role', 'agency_admin')->where('ra.agency_id', $agencyId)));
        Audience::excludeService(Audience::excludeOff($q));

        return $q->pluck('u.id')->map(fn ($v) => (int) $v)->unique()->reject(fn ($v) => $v === $exclude)->values()->all();
    }

    private function send(bool $dry, int $agencyId, object $r, Carbon $occ, string $kind, array $to, string $type, string $title, string $body, string $subject, string $inner): void
    {
        $key = $occ->format('Y-m-d H:i');
        $to = StaffTopicNotify::filter($agencyId, 'drills', $to);
        if (DB::table('drill_schedule_sends')->where(['agency_id' => $agencyId, 'user_id' => (int) $r->user_id, 'occurrence' => $key, 'kind' => $kind])->exists()) return;
        if ($dry) {
            $this->line("agency {$agencyId} · {$kind} · staff {$r->user_id} · {$key} → users [" . implode(',', $to) . "] · {$title}");
            return;
        }
        // Claim first: a second run that overlaps finds the row and sends nothing.
        try {
            DB::table('drill_schedule_sends')->insert(['agency_id' => $agencyId, 'user_id' => (int) $r->user_id, 'occurrence' => $key, 'kind' => $kind, 'created_at' => now()]);
        } catch (\Illuminate\Database\QueryException $e) { return; }
        if (! $to) return;
        $html = EmailTemplate::wrap($agencyId, $inner . '<p style="margin:14px 0 0;font-size:12px;color:#94A3B8">An agency admin can change these notices on the Drill log → Settings.</p>',
            ['eyebrow' => 'SAFETY', 'title' => $kind === 'missed' ? 'Fire drill not recorded' : 'Fire drill', 'subtitle' => $occ->format('D, M j · g:i A')]);
        foreach ($to as $uid) {
            Notify::write(['user_id' => (int) $uid, 'type' => $type, 'title' => $title, 'body' => $body, 'data' => json_encode(['link' => '#drills'])]);
            $email = (string) DB::table('users')->where('id', $uid)->value('email');
            if (! filter_var($email, FILTER_VALIDATE_EMAIL) || \App\Support\ChatAudience::isServiceEmail($email)) continue;
            try { AgencyMailer::forAgency($agencyId)->html($html, fn ($m) => $m->to($email)->subject($subject)); }
            catch (\Throwable $ex) { $this->error('mail failed: ' . $ex->getMessage()); }
        }
    }
}
