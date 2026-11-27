<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Audience;
use App\Support\FirePlans;
use App\Support\Notify;
use App\Support\TestAgency;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * fire-plans:remind - the yearly fire plan review (Anthony, 2026-11-28). Daily.
 *
 * When the CURRENT plan of a place (the newest approved version for a centre or a room) reaches 12 months since it
 * was last reviewed, its author and that centre's directors are reminded: once, then every 7 days until a new
 * version is approved (that version becomes current and the old one drops out). A place whose newer version is
 * already waiting for review is skipped: the review queue and the submissions feed already show it.
 *
 * Bell (type fire_plan: the person's "Fire plans" switch and the agency's drills switch, both in Notify::write) and
 * email (FirePlans::email, which checks the same switches). Test agencies are skipped outright and send nothing.
 * Drills are core (every plan), so this job is core too: no add-on gate.
 *
 * --dry lists who would hear what and changes nothing. --agency limits the run; --date pretends today is that date.
 */
final class FirePlanRemindCommand extends Command
{
    protected $signature = 'fire-plans:remind {--agency=} {--dry : list what would be sent, change nothing} {--date= : pretend today is this date (testing)}';
    protected $description = 'Remind the author and the centre directors when a fire plan is 12 months past its last review (once, then weekly)';

    public function handle(): int
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('fire_plans', 'review_reminded_at')) return self::SUCCESS;
        $dry = (bool) $this->option('dry');
        $q = DB::table('agencies')->whereNull('deleted_at');
        if ($this->option('agency')) $q->where('id', (int) $this->option('agency'));
        $count = 0;

        foreach ($q->pluck('id') as $agencyId) {
            $agencyId = (int) $agencyId;
            if (TestAgency::isAgency($agencyId) && ! $dry) continue;   // test agencies: nothing at all
            $tz = \App\Support\AgencyTime::tz($agencyId) ?: 'America/Toronto';
            $now = $this->option('date') ? Carbon::parse((string) $this->option('date') . ' 09:00', $tz) : Carbon::now($tz);
            $weekAgo = $now->copy()->subDays(7)->utc();

            foreach (FirePlans::reviewDue($agencyId, $now) as $plan) {
                if ($plan->review_reminded_at && Carbon::parse($plan->review_reminded_at, 'UTC')->gt($weekAgo)) continue;
                $place = FirePlans::placeLabel((int) $plan->centre_id, $plan->room_id !== null ? (int) $plan->room_id : null);
                $rev = FirePlans::reviewedOn($plan);
                $since = $rev ? Carbon::parse($rev)->format('M j, Y') : 'not on record';
                $people = array_values(array_unique(array_filter(array_merge([FirePlans::authorId($plan)], $this->directors((int) $plan->centre_id)))));
                $people = array_values(array_filter($people, fn ($uid) => $this->reachable($agencyId, (int) $uid)));
                $count++;
                if ($dry) {
                    $this->line("agency {$agencyId} plan {$plan->id} ({$place}, v{$plan->version}, reviewed {$since})"
                        . ($plan->review_reminded_at ? ' last reminded ' . $plan->review_reminded_at : ' first reminder')
                        . ' -> users ' . (implode(',', $people) ?: 'nobody') . (TestAgency::isAgency($agencyId) ? '  [test agency: the real run skips it]' : ''));
                    continue;
                }
                foreach ($people as $uid) {
                    Notify::write(['user_id' => (int) $uid, 'agency_id' => $agencyId, 'type' => 'fire_plan',
                        'title' => "\u{1F5FA}\u{FE0F} Fire plan due for review - " . $place,
                        'body' => 'Version ' . (int) $plan->version . ' was last reviewed ' . $since . '. Review it with your team and send a new version.',
                        'data' => json_encode(['link' => '#drills?tab=fireplan&plan=' . (int) $plan->id])]);
                    $html = '<p style="margin:0 0 10px;font-size:15px;color:#334155">The fire plan for <b>' . htmlspecialchars($place, ENT_QUOTES, 'UTF-8') . '</b> (version ' . (int) $plan->version
                        . ') was last reviewed ' . htmlspecialchars($since, ENT_QUOTES, 'UTF-8') . '. Plans are reviewed with staff at least once a year.</p>'
                        . '<p style="margin:0 0 10px;font-size:15px;color:#334155">Walk the routes, check the exits, extinguishers and meeting place, then open the plan, choose <b>Edit (new version)</b> and send it for review. This reminder repeats weekly until a new version is approved.</p>'
                        . FirePlans::button((int) $plan->id);
                    FirePlans::email($agencyId, (int) $uid, 'Fire plan due for its yearly review: ' . $place, 'Fire plan review due', $place, $html);
                }
                DB::table('fire_plans')->where('id', $plan->id)->update(['review_reminded_at' => now()]);
            }
        }
        if ($dry) $this->line($count . ' plan(s) due a reminder.');

        return self::SUCCESS;
    }

    /** Active, reachable directors of this centre. */
    private function directors(int $centreId): array
    {
        $q = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.role', 'centre_director')->where('ra.active', true)->where('ra.centre_id', $centreId)->whereNull('u.deleted_at');
        Audience::excludeService(Audience::excludeOff($q));

        return $q->pluck('u.id')->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    /** Still staff of this agency (an author who left is not reminded), and never a platform admin. */
    private function reachable(int $agencyId, int $uid): bool
    {
        $roles = DB::table('role_assignments as ra')->leftJoin('centres as c', 'c.id', '=', 'ra.centre_id')->where('ra.user_id', $uid)->where('ra.active', 1)
            ->whereRaw('COALESCE(ra.agency_id, c.agency_id) = ?', [$agencyId])->pluck('ra.role')->all();
        if (DB::table('role_assignments')->where('user_id', $uid)->where('role', 'platform_admin')->where('active', 1)->exists()) return false;

        return (bool) array_intersect($roles, ['agency_admin', 'centre_director', 'educator', 'home_visitor']);
    }
}
