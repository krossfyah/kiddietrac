<?php

namespace App\Console\Commands;

use App\Support\Visibility;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Every child list a staff member sees holds the right children (2026-11-09, App\Support\Visibility).
 *
 * Anthony: "check into educators as we are noticing that the child count and list is not accurate ... add guards to
 * never allow this to happen again as this is a production related issue". The educator lists each had their own
 * rule and had drifted: the weekly overview and allergy alerts were agency-wide, the awards picker listed withdrawn
 * children, /provider/children dropped part-time children on their days off. Nightly, this fails when:
 *
 *   1. a list below, run as each educator, home visitor and director of every agency, holds a child outside
 *      Visibility::liveChildIds (enrolled, in their rooms) - or planningChildIds for the planning lists;
 *   2. /provider/children (the educator's "my children") misses one of their live children;
 *   3. a room's roster holds a child the person may not see, or a bootstrap room is not one of their rooms;
 *   4. the counts underneath disagree: an enrolled child with no open enrolment, an open enrolment on a child who has
 *      left, a child's primary room that is not one of their open enrolments, or a child enrolled twice in one room.
 *
 * Controllers are called directly, never through the HTTP stack: the stack writes an audit row for every refused
 * request in the person's name, and a check must not leave entries in somebody's history.
 * A NEW STAFF CHILD LIST goes in LISTS. An alert to the platform admins on a failure; --no-alert for trials.
 */
class KtChildScopeCheck extends Command
{
    protected $signature = 'kt:child-scope-check {--agency=} {--quiet-ok} {--no-alert : report only}';

    protected $description = 'Fail when a staff child list holds a child outside the person\'s rooms or a child who has left, or the enrolment counts disagree.';

    /** label => [controller, method, allowed set: live | planning]. */
    private const LISTS = [
        'provider/children'          => [\App\Http\Controllers\Api\EducatorSelfController::class, 'children', 'live'],
        'provider/awards/roster'     => [\App\Http\Controllers\Api\AwardController::class, 'roster', 'live'],
        'provider/walks/eligible'    => [\App\Http\Controllers\Api\WalkController::class, 'eligibleChildren', 'live'],
        'attendance/weekly-overview' => [\App\Http\Controllers\Api\AttendancePatternController::class, 'weeklyOverview', 'planning'],
        'operations/allergy-alerts'  => [\App\Http\Controllers\Api\OperationsController::class, 'allergyAlerts', 'planning'],
    ];

    public function handle(): int
    {
        $bad = [];
        $this->counts($bad);

        // TEST AGENCIES ARE NOT ALERTED ON (2026-11-09, Anthony: "alerts for test agency's should all be turnt off"): asked for by --agency, a test agency is still checked.
        $agencies = DB::table('agencies')->whereNull('deleted_at')
            ->when($this->option('agency'), fn ($q) => $q->where('id', (int) $this->option('agency')),
                fn ($q) => $q->whereNotIn('id', \App\Support\TestAgencies::ids() ?: [0]))->pluck('id');
        $prevRequest = app('request');
        foreach ($agencies as $agencyId) {
            $agencyId = (int) $agencyId;
            $people = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
                ->where('ra.agency_id', $agencyId)->where('ra.active', true)->whereNull('u.deleted_at')
                ->whereIn('ra.role', ['educator', 'home_visitor', 'centre_director'])
                ->distinct()->pluck('u.id');
            foreach ($people as $uid) {
                $user = \App\Models\User::find($uid);
                if (! $user || \App\Support\ChatAudience::isServiceEmail((string) $user->email)) {
                    continue;
                }
                if (DB::table('role_assignments')->where('user_id', $uid)->where('active', true)->where('role', 'platform_admin')->exists()) {
                    continue;   // previews every role; checked through the roles themselves
                }
                $live = Visibility::liveChildIds($agencyId, $user);
                $planning = Visibility::planningChildIds($agencyId, $user);
                $who = "agency {$agencyId}, user {$uid}";

                foreach (self::LISTS as $label => [$class, $method, $set]) {
                    $got = $this->childIds($this->runAs($class, $method, $user, $agencyId, $label));
                    if ($got === null) {
                        continue;
                    }
                    $allowed = $set === 'live' ? $live : $planning;
                    if ($allowed !== null && ($extra = array_diff($got, $allowed))) {
                        $bad[$label . ': children this person does not look after'][] = $who . ' (' . count($extra) . ')';
                    }
                    if ($label === 'provider/children' && ($miss = array_diff($live, $got, $this->suspended($live)))) {
                        $bad[$label . ': missing children this person looks after'][] = $who . ' (' . count($miss) . ')';
                    }
                }

                $boot = $this->runAs(\App\Http\Controllers\Api\RoomController::class, 'bootstrap', $user, $agencyId, 'provider/bootstrap');
                $rooms = Visibility::roomIds($agencyId, $user);
                foreach ((array) ($boot['rooms'] ?? []) as $room) {
                    $rid = (int) ((array) $room)['id'];
                    if (! in_array($rid, $rooms, true)) {
                        $bad['provider/bootstrap: a room that is not theirs'][] = $who . " (room {$rid})";
                        continue;
                    }
                    $roster = $this->runAs(\App\Http\Controllers\Api\RoomController::class, 'roster', $user, $agencyId, 'roster', [$rid]);
                    $kids = array_map(fn ($c) => (int) ((array) $c)['id'], (array) ($roster['roster'] ?? []));
                    if ($extra = array_diff($kids, $live)) {
                        $bad['provider/rooms/{room}/roster: children this person does not look after'][] = $who . " (room {$rid}: " . count($extra) . ')';
                    }
                }
            }
        }
        app()->instance('request', $prevRequest);
        Auth::forgetGuards();

        foreach ($bad as $what => $items) {
            $this->error($what . ': ' . implode(', ', array_slice($items, 0, 12)) . (count($items) > 12 ? ' … +' . (count($items) - 12) : ''));
        }
        if ($bad && ! $this->option('no-alert')) {
            KtIntegrityCheck::alertPlatformAdmins(
                '[KiddieTrac] Staff child lists - ' . count($bad) . ' problem(s)',
                'Every list of children a staff member sees must hold exactly the children they look after (App\Support\Visibility::liveChildIds), and the enrolment counts underneath must agree.',
                $bad
            );
        }
        if ($bad) {
            return self::FAILURE;
        }
        if (! $this->option('quiet-ok')) {
            $this->info('Every staff child list holds only the children that person looks after; enrolment counts agree.');
        }

        return self::SUCCESS;
    }

    /** The counts every list is built on. */
    private function counts(array &$bad): void
    {
        // Children of test agencies (by the family's centre) are left out, like the lists above.
        $test = implode(',', array_map('intval', \App\Support\TestAgencies::ids() ?: [0]));
        $real = " and not exists (select 1 from families tf join centres tc on tc.id=tf.centre_id where tf.id=c.family_id and tc.agency_id in ($test))";
        $q = fn (string $sql) => (int) (DB::selectOne(str_replace('{REAL}', $real, $sql))->n ?? 0);
        $checks = [
            'Enrolled child with no open enrolment' => "select count(*) n from children c where c.deleted_at is null and c.enrollment_status='enrolled' and not exists (select 1 from enrollments e where e.child_id=c.id and e.end_date is null){REAL}",
            'Open enrolment on a child who has left' => "select count(distinct c.id) n from enrollments e join children c on c.id=e.child_id where e.end_date is null and (c.deleted_at is not null or c.enrollment_status='withdrawn'){REAL}",
            'Primary room is not one of the child\'s open enrolments' => "select count(*) n from children c where c.deleted_at is null and c.enrollment_status='enrolled' and c.primary_room_id is not null and not exists (select 1 from enrollments e where e.child_id=c.id and e.end_date is null and e.room_id=c.primary_room_id){REAL}",
            'Child enrolled twice in one room' => "select count(*) n from (select e.child_id from enrollments e join children c on c.id=e.child_id where e.end_date is null{REAL} group by e.child_id, e.room_id having count(*) > 1) x",
        ];
        foreach ($checks as $label => $sql) {
            if ($n = $q($sql)) {
                $bad['Enrolment counts: ' . $label][] = $n . ' child(ren)';
            }
        }

        /* ONE ROOM PER CHILD PER DAY (2026-11-09, Anthony: "Why does it show for Bruni 0/4 when theres only 2 children
           assigned for her"). A move stamped the old placement's end date with the move day, so the child was in care
           in both rooms that day and every count that reads end_date as the last day (dashboard cards, Safe Arrival,
           "scheduled today") counted them twice. Today's placements of one child must not share a weekday. */
        $testIds = \App\Support\TestAgencies::ids() ?: [0];
        $today = \Illuminate\Support\Carbon::now('America/Toronto')->toDateString();
        $rows = DB::table('enrollments as e')->join('children as c', 'c.id', '=', 'e.child_id')
            ->join('rooms as r', 'r.id', '=', 'e.room_id')->join('centres as ce', 'ce.id', '=', 'r.centre_id')
            ->whereNotIn('ce.agency_id', $testIds)->whereNull('c.deleted_at')->where('c.enrollment_status', 'enrolled')
            ->where(fn ($w) => $w->whereNull('e.start_date')->orWhere('e.start_date', '<=', $today))
            ->where(fn ($w) => $w->whereNull('e.end_date')->orWhere('e.end_date', '>=', $today))
            ->get(['e.child_id', 'e.room_id', 'e.schedule'])->groupBy('child_id');
        $all = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        $twice = [];
        foreach ($rows as $cid => $list) {
            if ($list->pluck('room_id')->unique()->count() < 2) {
                continue;
            }
            $seen = [];
            foreach ($list as $e) {
                $days = \App\Support\CareSchedule::daysOf($e->schedule) ?: $all;
                foreach ($days as $d) {
                    if (isset($seen[$d]) && $seen[$d] !== (int) $e->room_id) {
                        $twice[(int) $cid] = true;
                    }
                    $seen[$d] = (int) $e->room_id;
                }
            }
        }
        if ($twice) {
            $bad['Enrolment counts: Child placed in two rooms on the same weekday'][] = count($twice) . ' child(ren): #' . implode(', #', array_keys($twice));
        }
    }

    /** Run one GET controller action as this person. Null when it refuses them or fails. */
    private function runAs(string $class, string $method, $user, int $agencyId, string $label, array $args = []): ?array
    {
        $req = Request::create('/api/v1/' . $label, 'GET');
        $req->headers->set('Accept', 'application/json');
        $req->headers->set('X-Active-Agency-Id', (string) $agencyId);
        $req->setUserResolver(fn () => $user);
        app()->instance('request', $req);
        Auth::setUser($user);
        try {
            $res = app($class)->{$method}($req, ...$args);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return null;
        } catch (\Throwable $e) {
            $this->warn("{$label} as user {$user->id}: " . substr($e->getMessage(), 0, 120));
            return null;
        }
        if (! method_exists($res, 'getStatusCode') || $res->getStatusCode() !== 200) {
            return null;
        }

        return json_decode(json_encode($res->getData(true)), true);
    }

    /** Child ids in a list response: rows under children / roster / data, or any child_id. */
    private function childIds(?array $d): ?array
    {
        if ($d === null) {
            return null;
        }
        $acc = [];
        $walk = function ($v, $key) use (&$walk, &$acc) {
            if (! is_array($v)) {
                return;
            }
            if (isset($v['child_id']) && is_numeric($v['child_id'])) {
                $acc[(int) $v['child_id']] = 1;
            }
            if (isset($v['id']) && is_numeric($v['id']) && preg_match('/child|kid|roster|^data$|^alerts$|^unrecorded$/i', (string) $key)) {
                $acc[(int) $v['id']] = 1;
            }
            foreach ($v as $k => $x) {
                $walk($x, is_int($k) ? $key : $k);
            }
        };
        $walk($d, '');

        return DB::table('children')->whereIn('id', array_keys($acc) ?: [0])->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    private function suspended(array $childIds): array
    {
        return DB::table('children as c')->join('families as f', 'f.id', '=', 'c.family_id')
            ->whereIn('c.id', $childIds ?: [0])->whereNotNull('f.suspended_at')->pluck('c.id')->map(fn ($v) => (int) $v)->all();
    }
}
