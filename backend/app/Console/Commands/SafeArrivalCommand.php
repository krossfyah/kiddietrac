<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AgencyMailer;
use App\Services\CheckEventNotifier;
use App\Services\EmailTemplate;
use App\Services\FcmService;
use App\Support\SafeArrival;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Safe Arrival, every 5 minutes (2026-09-29). See App\Support\SafeArrival.
 *
 *   overdue   -> ask the parents once ("Has Maya arrived? ... tap Not attending today"),
 *                through their own channels (push/in-app, email, SMS as they chose).
 *   no answer -> after escalate_after_minutes, alert the room's educators and the centre's
 *                directors (in-app + push), once.
 *   arrived / absence reported -> the check closes itself.
 *
 * Only agencies that switched it on. A parent message also honours the agency's
 * notification switch and the test-agency kill switch (Suppression) -- staff alerts do
 * not, because a missing child is the centre's business whatever the parents' settings.
 *
 *   php artisan kiddietrac:safe-arrival [--agency=ID] [--dry-run]
 */
class SafeArrivalCommand extends Command
{
    protected $signature = 'kiddietrac:safe-arrival {--agency= : one agency only} {--dry-run : print, send nothing}';

    protected $description = 'Safe Arrival: ask parents about children who have not arrived, then alert staff';

    public function handle(CheckEventNotifier $notifier): int
    {
        $dryOpt = (bool) $this->option('dry-run');
        $agencies = DB::table('agencies')->when($this->option('agency'), fn ($q) => $q->where('id', (int) $this->option('agency')))
            ->pluck('id');
        $now = Carbon::now();

        foreach ($agencies as $agencyId) {
            $agencyId = (int) $agencyId;
            /* TEST AGENCIES: DEMO MODE ONLY (2026-11-24, App\Support\TestAgency). Always a dry run: worked out and
               printed, never called, texted, pushed, emailed or written. */
            $dry = $dryOpt || \App\Support\TestAgency::isAgency($agencyId);
            $cfg = SafeArrival::settings($agencyId);
            if (! $cfg['enabled'] && ! $cfg['departure_enabled']) {
                continue;
            }
            $date = \App\Support\AgencyTime::today($agencyId);
            $tz = \App\Support\AgencyTime::tz($agencyId);
            $quietParents = \App\Support\Suppression::isAgency($agencyId);

            if ($cfg['departure_enabled']) {
                $this->departures($notifier, $agencyId, $cfg, $date, $tz, $now, $dry, $quietParents);
            }
            if (! $cfg['enabled']) {
                $this->endOfDay($agencyId, $cfg, $date, $tz, $now, $dry);
                continue;
            }

            $this->closedToday($agencyId, $date, $dry);
            $list = SafeArrival::today($agencyId, null, null, $date);
            $this->noLongerExpected($agencyId, $date, $list, $dry);

            foreach ($list as $row) {
                $c = $row['check'];

                // Marked absent by Safe Arrival, then signed in after all: the absence is taken back (2026-11-24).
                if ($row['state'] === 'arrived' && ! empty($row['absent'])
                    && \App\Support\SafeArrivalAbsentMail::clearIfArrived($agencyId, (int) $row['child_id'], $date, $row['arrived_at'] ?? null, $dry)) {
                    $this->line(($dry ? '[dry] ' : '') . "{$row['name']} signed in after being marked absent: absence removed");
                }
                // Close an open check the moment the child arrives or is reported absent.
                if ($c && ! $c->resolved_at && in_array($row['state'], ['arrived', 'absent'], true)) {
                    if (! $dry) {
                        DB::table('safe_arrival_checks')->where('id', $c->id)->update([
                            'status' => $row['state'], 'resolution' => $row['state'],
                            'arrived_at' => $row['arrived_at'], 'resolved_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                    $this->line(($dry ? '[dry] ' : '') . "closed {$row['name']}: {$row['state']}");
                    $this->allClearArrival($agencyId, $row, $c, $tz, $date, $dry);
                    continue;
                }
                // Counted in a headcount today, not signed in (headcount_mode = present, 2026-11-03): nobody is
                // messaged while that stands. An open check stays open, so if a later headcount leaves the child
                // unticked, this picks up where it left off; a sign-in closes it as above.
                if ($row['state'] === 'counted') {
                    if ($dry && $row['due_at'] <= $now) {
                        $this->line('[dry] counted in a headcount at ' . Carbon::parse($row['counted']['at'], 'UTC')->timezone($tz)->format('g:i A')
                            . ", not signed in: {$row['name']} - nothing sent");
                    }
                    continue;
                }
                if (! in_array($row['state'], ['overdue', 'escalated', 'on_the_way'], true)) {
                    continue;
                }

                // 1. Ask the parents, once.
                if (! $c) {
                    $sent = $quietParents ? 0 : $this->askParents($notifier, $agencyId, $row, $tz, $dry);
                    if (! $dry) {
                        DB::table('safe_arrival_checks')->insertOrIgnore([
                            'agency_id' => $agencyId, 'centre_id' => $row['centre_id'], 'room_id' => $row['room_id'],
                            'child_id' => $row['child_id'], 'check_date' => $date, 'expected_time' => $row['expected'] . ':00',
                            'due_at' => $row['due_at'], 'status' => 'notified',
                            'parents_notified_at' => now(), 'parents_notified' => $sent,
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                    $this->line(($dry ? '[dry] ' : '') . "asked parents of {$row['name']} ({$sent})" . ($quietParents ? ' [agency notifications off]' : ''));
                    continue;
                }

                // 1b. Still no answer: phone them (SafeArrivalCall), if the agency turned it on.
                if ($cfg['call_parents'] && ! $quietParents && ! $c->call_started_at && ! ($c->parent_response ?? null)
                    && $c->parents_notified_at
                    && $now->greaterThanOrEqualTo(Carbon::parse($c->parents_notified_at)->addMinutes($cfg['call_after_minutes']))) {
                    $rang = $dry ? false : \App\Support\SafeArrivalCall::callNext($c);
                    $this->line(($dry ? '[dry] would call' : ($rang ? 'calling' : 'could not call')) . " parents of {$row['name']}");
                }

                // 2. No answer: alert the room and the centre, once. A parent who answered
                //    "on the way" moves the clock to their answer; if the child still has
                //    not arrived that long after, the staff are alerted after all.
                $onWay = ($c->parent_response ?? null) === 'on_the_way';
                $from = $onWay ? $c->parent_response_at : $c->parents_notified_at;
                $alerted = $onWay ? ($c->escalated_at && Carbon::parse($c->escalated_at)->gte(Carbon::parse($c->parent_response_at))) : (bool) $c->escalated_at;
                // Running late (staff logged "here by …"): the wait starts again when that time ran out, the same as "on the way".
                $lateDue = $row['late']['due_at'] ?? null;
                if ($lateDue) {
                    if (! $from || Carbon::parse($from)->lt($lateDue)) {
                        $from = $lateDue;
                    }
                    $alerted = $c->escalated_at && Carbon::parse($c->escalated_at)->gte($lateDue);
                }
                if (! $alerted && $from
                    && $now->greaterThanOrEqualTo(Carbon::parse($from)->addMinutes($cfg['escalate_after_minutes']))) {
                    [$n, $unreached, $upNow] = $this->alertStaff($agencyId, $cfg, $row, $tz, $dry, (int) $c->parents_notified);
                    if (! $dry) {
                        $upd = ['status' => 'escalated', 'escalated_at' => now(), 'staff_alerted' => $n, 'updated_at' => now()];
                        if (\Illuminate\Support\Facades\Schema::hasColumn('safe_arrival_checks', 'staff_unreached')) {
                            $upd['staff_unreached'] = $unreached ? mb_substr(implode(', ', $unreached), 0, 500) : null;
                        }
                        if (\Illuminate\Support\Facades\Schema::hasColumn('safe_arrival_checks', 'up_alerted_at')) {
                            // Included now, or still to come (a second escalation after "on the way" starts the wait again).
                            $upd['up_alerted_at'] = $upNow ? now() : null;
                        }
                        DB::table('safe_arrival_checks')->where('id', $c->id)->update($upd);
                    }
                    $this->line(($dry ? '[dry] ' : '') . "escalated {$row['name']} to {$n} staff" . ($upNow ? '' : ' (educators; directors/admins later if still open)')
                        . ($unreached ? ' (no phone alert reached: ' . implode(', ', $unreached) . ')' : ''));
                    continue;
                }

                // 3. Directors/admins LATER (up_timing = after, 2026-10-30): the educators were alerted and the child
                //    is still not here up_after_minutes on. Today's open checks only, escalated within the last few
                //    hours - switching the setting on in the afternoon must not alert about this morning's cases.
                if ($cfg['up_timing'] === 'after' && $c->escalated_at && empty($c->up_alerted_at)
                    && \Illuminate\Support\Facades\Schema::hasColumn('safe_arrival_checks', 'up_alerted_at')
                    && $now->greaterThanOrEqualTo(Carbon::parse($c->escalated_at)->addMinutes($cfg['up_after_minutes']))
                    && Carbon::parse($c->escalated_at)->greaterThanOrEqualTo($now->copy()->subMinutes($cfg['up_after_minutes'] + 120))) {
                    $at = Carbon::parse($row['expected'], $tz)->format('g:i A');
                    $title = "⚠️ Safe Arrival: {$row['name']} still not here";
                    $body = "{$row['name']} ({$row['room_name']}) was expected around {$at} and still hasn't been signed in, and no absence has been reported. "
                        . 'The room was alerted at ' . Carbon::parse($c->escalated_at)->timezone($tz)->format('g:i A') . ' and nobody has closed it since. Please check where the child is.';
                    [$n, $unreached] = SafeArrival::alertTiered($agencyId, $cfg, (int) $row['centre_id'], (int) $row['room_id'], false, true,
                        $title, $body, (int) $row['child_id'], $dry);
                    if (! $dry) {
                        $upd = ['up_alerted_at' => now(), 'up_alerted' => $n, 'staff_alerted' => (int) $c->staff_alerted + $n, 'updated_at' => now()];
                        if ($unreached) {
                            $upd['staff_unreached'] = mb_substr(trim(($c->staff_unreached ? $c->staff_unreached . ', ' : '') . implode(', ', $unreached)), 0, 500);
                        }
                        DB::table('safe_arrival_checks')->where('id', $c->id)->update($upd);
                    }
                    $this->line(($dry ? '[dry] ' : '') . "still open: alerted {$n} director(s)/admin(s) about {$row['name']}");
                }

                // 4. Still not here this long after the LAST staff alert (absent_notice = after_alert, 2026-11-24): marked
                //    absent and the family emailed, staff BCC'd. Not when a parent answered (on the way) or staff logged
                //    running late - those reset the clock. With directors/admins alerted later, that alert is the last one.
                if ($cfg['absent_notice'] === 'after_alert' && $c && ! $c->resolved_at && in_array($row['state'], ['overdue', 'escalated'], true)
                    && ! ($c->parent_response ?? null) && empty($row['late']) && $alerted) {
                    $last = ($c->up_alerted_at ?? null) ?: ($cfg['up_timing'] === 'after' ? null : $c->escalated_at);
                    // Within an hour of its time only: turning this on (or a deploy) in the afternoon must not mail
                    // families about this morning. Anything older is left to the end-of-day close, as before.
                    if ($last && $now->greaterThanOrEqualTo(Carbon::parse($last)->addMinutes($cfg['absent_after_minutes']))
                        && $now->lessThan(Carbon::parse($last)->addMinutes($cfg['absent_after_minutes'] + 60))) {
                        $this->line(\App\Support\SafeArrivalAbsentMail::markAbsent($agencyId, $cfg, $c, $tz, $dry, $quietParents,
                            $cfg['absent_after_minutes'] . ' min after the last alert'));
                    }
                }
            }

            $this->endOfDay($agencyId, $cfg, $date, $tz, $now, $dry);
        }

        return self::SUCCESS;
    }

    /**
     * END OF DAY CLOSE (2026-11-10, Anthony chose "Close at end of day"). A child who never came and whom nobody
     * accounted for left the check open for good: it dropped off the Today board at midnight, but every staff alert for
     * it stayed unread on the bell and the phone badge, day after day (18 such checks since Oct 27 at iLearn).
     *
     * Now an arrival check still open at the centre's closing time (close_time, else departure_default_time) is closed
     * as 'unresolved'; one from an earlier day is closed at once. Its staff alerts are marked read - silently, it is not
     * an all-clear - and the directors/admins get ONE summary per centre naming the children, pointing at History,
     * which shows the day as "Not resolved by end of day". A departure check from an earlier day that is still open is
     * closed the same way (no summary: the auto sign-off already re-alerted).
     * Checks older than yesterday (the backlog before this shipped) close without a summary.
     */
    private function endOfDay(int $agencyId, array $cfg, string $date, string $tz, Carbon $now, bool $dry): void
    {
        $open = DB::table('safe_arrival_checks as k')->leftJoin('centres as ce', 'ce.id', '=', 'k.centre_id')
            ->where('k.agency_id', $agencyId)->whereNull('k.resolved_at')->where('k.check_date', '<=', $date)
            ->orderBy('k.check_date')->get(['k.*', 'ce.close_time as centre_close', 'ce.name as centre_name']);
        $yesterday = Carbon::parse($date, $tz)->subDay()->toDateString();
        $summary = [];   // centre_id => [date => [names]]
        foreach ($open as $c) {
            if ((string) $c->check_date === $date) {
                $close = substr((string) ($c->centre_close ?: $cfg['departure_default_time']), 0, 5);
                if ($now->lt(Carbon::parse($date . ' ' . $close, $tz))) {
                    continue;
                }
            }
            // absent_notice = closing (2026-11-24): an alerted child still not here at closing is marked absent, family emailed.
            if ((string) $c->check_date === $date && ($cfg['absent_notice'] ?? '') === 'closing' && $c->escalated_at && empty($c->parent_response)) {
                $this->line(\App\Support\SafeArrivalAbsentMail::markAbsent($agencyId, $cfg, $c, $tz, $dry, \App\Support\Suppression::isAgency($agencyId), 'at closing time'));
                continue;
            }
            $child = \App\Models\Child::find($c->child_id);
            $name = $child ? trim(($child->preferred_name ?: $child->first_name) . ' ' . $child->last_name) : 'A child';
            $room = $c->room_id ? DB::table('rooms')->where('id', $c->room_id)->value('name') : null;
            $rows = \App\Support\SafeArrivalAllClear::alertRows('arrival', $c);
            $unread = count(array_filter($rows, fn ($r) => $r->read_at === null));
            if (! $dry) {
                DB::table('safe_arrival_checks')->where('id', $c->id)->whereNull('resolved_at')->update([
                    'status' => 'closed', 'resolution' => 'unresolved', 'resolved_at' => now(), 'updated_at' => now(),
                ]);
                \App\Support\SafeArrivalAllClear::markRead($rows);
                try {
                    \App\Support\Audit::write([
                        'user_id' => null, 'agency_id' => $agencyId, 'action' => 'safe_arrival.unresolved',
                        'entity_type' => 'child', 'entity_id' => (int) $c->child_id,
                        'payload' => json_encode(['summary' => "Safe Arrival for {$name} on {$c->check_date} closed at end of day: nobody signed them in or recorded an absence; {$unread} alert(s) marked read",
                            'check_id' => (int) $c->id], JSON_UNESCAPED_UNICODE),
                        'created_at' => now(),
                    ]);
                } catch (\Throwable $e) {
                }
            }
            $this->line(($dry ? '[dry] ' : '') . "end of day: closed {$name} ({$c->check_date}) as not resolved, {$unread} alert(s) marked read");
            if ((string) $c->check_date >= $yesterday && ($c->escalated_at || $c->up_alerted_at)) {
                $summary[(int) $c->centre_id][(string) $c->check_date][] = $name . ($room ? " ({$room})" : '');
            }
        }

        foreach (DB::table('safe_departure_checks')->where('agency_id', $agencyId)->whereNull('resolved_at')
            ->where('check_date', '<', $date)->get() as $c) {
            $rows = \App\Support\SafeArrivalAllClear::alertRows('departure', $c);
            if (! $dry) {
                DB::table('safe_departure_checks')->where('id', $c->id)->whereNull('resolved_at')->update([
                    'status' => 'closed', 'resolution' => 'unresolved', 'resolved_at' => now(), 'updated_at' => now(),
                ]);
                \App\Support\SafeArrivalAllClear::markRead($rows);
            }
            $this->line(($dry ? '[dry] ' : '') . "end of day: closed departure check #{$c->id} ({$c->check_date}) as not resolved");
        }

        foreach ($summary as $centreId => $byDate) {
            $ids = SafeArrival::recipients($agencyId, $centreId, null, $cfg, false, true);
            foreach ($byDate as $day => $names) {
                $n = count($names);
                $when = $day === $date ? 'today' : Carbon::parse($day, $tz)->format('l, M j');
                $title = "🛡️ Safe Arrival: {$n} not resolved {$when}";
                $body = ($n === 1 ? 'This child was' : 'These children were') . " never signed in and no absence was recorded {$when}: "
                    . implode(', ', $names) . '. Their alerts are cleared. Open Safe Arrival → History to see what was sent.';
                if ($dry) {
                    $this->line("[dry] end-of-day summary to " . count($ids) . " director(s)/admin(s): {$title}");
                    continue;
                }
                if ($ids) {
                    SafeArrival::alertUsers($agencyId, $ids, $title, $body, 0, '#safe-arrival', ['email' => 'fallback', 'kind' => 'arrival']);
                }
                $this->line("end-of-day summary to " . count($ids) . " director(s)/admin(s): {$title}");
            }
        }
    }

    /**
     * CLOSED ON THE DAY (2026-11-06, Anthony: "if educator closes on the day of without prior notice, close any safe
     * arrival notices due to this unexpected closure"). today() already leaves out a centre that is closed, so nothing
     * NEW starts there - but a check opened before the closure was entered was simply never looked at again: it stayed
     * open, its staff alerts stayed unread, and SafeArrivalNextCallCommand kept ringing the next guardian. Any closure
     * counts, however it was entered (the Closures screen, a sole provider's same-day time off, a holiday sync). Each
     * open check is closed as 'centre_closed'; staff who were alerted get the all-clear and their alert marked read.
     * Parents are not messaged here - the closure's own announcement is theirs.
     */
    private function closedToday(int $agencyId, string $date, bool $dry): void
    {
        $open = DB::table('safe_arrival_checks')->where('agency_id', $agencyId)->where('check_date', $date)
            ->whereNull('resolved_at')->get();
        $closed = [];
        foreach ($open as $c) {
            $cid = (int) $c->centre_id;
            if (! array_key_exists($cid, $closed)) $closed[$cid] = \App\Support\Closures::isClosed($cid, $date);
            if (! $closed[$cid]) continue;
            $child = \App\Models\Child::find($c->child_id);
            $name = $child ? trim(($child->preferred_name ?: $child->first_name) . ' ' . $child->last_name) : 'A child';
            $room = $c->room_id ? DB::table('rooms')->where('id', $c->room_id)->value('name') : null;
            $centre = (string) DB::table('centres')->where('id', $cid)->value('name');
            if (! $dry) {
                DB::table('safe_arrival_checks')->where('id', $c->id)->update([
                    'status' => 'closed', 'resolution' => 'centre_closed', 'resolved_at' => now(), 'updated_at' => now(),
                ]);
            }
            $this->line(($dry ? '[dry] ' : '') . "closed {$name}: {$centre} is closed today");
            $this->allClear($agencyId, 'arrival', $c, "✅ Safe Arrival: {$name} - centre closed today",
                $name . ($room ? " ({$room})" : '') . ' is not expected: ' . ($centre !== '' ? $centre : 'the centre') . ' is closed today.'
                . ' All clear - no further action needed.', $dry);
        }
    }

    /**
     * NO LONGER EXPECTED (2026-11-11, SafeArrival::stillExpected). A check opened this morning whose child has since
     * left today's list - withdrawn (as when an educator's account is closed and her children go with it), enrolment
     * ended, family closed, taken off today's schedule - closes as 'not_expected'. The staff who were alerted get the
     * all-clear and their alert is marked read; parents are not messaged. A closed centre is closedToday()'s.
     */
    private function noLongerExpected(int $agencyId, string $date, array $list, bool $dry): void
    {
        $open = DB::table('safe_arrival_checks')->where('agency_id', $agencyId)->where('check_date', $date)
            ->whereNull('resolved_at')->get();
        foreach ($open as $c) {
            if (isset($list[(int) $c->child_id]) || \App\Support\Closures::isClosed((int) $c->centre_id, $date)) {
                continue;
            }
            $child = \App\Models\Child::withTrashed()->find($c->child_id);
            $name = $child ? trim(($child->preferred_name ?: $child->first_name) . ' ' . $child->last_name) : 'A child';
            $room = $c->room_id ? DB::table('rooms')->where('id', $c->room_id)->value('name') : null;
            $why = SafeArrival::notExpectedReason((int) $c->child_id);
            if (! $dry) {
                DB::table('safe_arrival_checks')->where('id', $c->id)->whereNull('resolved_at')->update([
                    'status' => 'closed', 'resolution' => 'not_expected', 'resolved_at' => now(), 'updated_at' => now(),
                ]);
                try {
                    \App\Support\Audit::write([
                        'user_id' => null, 'agency_id' => $agencyId, 'action' => 'safe_arrival.not_expected',
                        'entity_type' => 'child', 'entity_id' => (int) $c->child_id,
                        'payload' => json_encode(['summary' => "Safe Arrival for {$name} on {$c->check_date} closed: {$name} {$why}", 'check_id' => (int) $c->id], JSON_UNESCAPED_UNICODE),
                        'created_at' => now(),
                    ]);
                } catch (\Throwable $e) {
                }
            }
            $this->line(($dry ? '[dry] ' : '') . "closed {$name}: {$why}");
            $this->allClear($agencyId, 'arrival', $c, "✅ Safe Arrival: {$name} - no longer expected",
                $name . ($room ? " ({$room})" : '') . " {$why}, so they are not expected today. All clear - no further action needed.", $dry);
        }
    }

    /**
     * ALL CLEAR (2026-11-02, Anthony): a check that closes AFTER staff were alerted tells everyone who got the alert and
     * marks their alert read. Parents are not messaged. Nothing if no staff alert was ever raised.
     */
    private function allClearArrival(int $agencyId, array $row, object $c, string $tz, string $date, bool $dry): void
    {
        if ($row['state'] === 'arrived') {
            [$t, $b] = \App\Support\SafeArrivalAllClear::arrivedText($row['name'], $row['room_name'] ?: null,
                (string) \App\Support\AgencyTime::fmt($row['arrived_at'], $tz));
        } else {
            $by = DB::table('child_absences')->where('child_id', $row['child_id'])->whereDate('absent_on', $date)->value('reported_by_id');
            [$t, $b] = \App\Support\SafeArrivalAllClear::absentText($row['name'], $row['room_name'] ?: null, $by ? (int) $by : null);
        }
        $this->allClear($agencyId, 'arrival', $c, $t, $b, $dry);
    }

    private function allClear(int $agencyId, string $kind, object $c, string $title, string $body, bool $dry): void
    {
        if ($dry) {
            $p = \App\Support\SafeArrivalAllClear::plan($kind, $c);
            if (! $p['alerted']) {
                $this->line('  [dry] no all-clear: staff were never alerted');
                return;
            }
            $unread = count(array_filter($p['rows'], fn ($r) => $r->read_at === null));
            $this->line('  [dry] would mark ' . $unread . ' alert(s) read and send all-clear to ' . count($p['to'])
                . ' (' . \App\Support\SafeArrivalAllClear::names($p['to']) . '): ' . $title);
            return;
        }
        $r = \App\Support\SafeArrivalAllClear::send($agencyId, $kind, $c, $title, $body);
        if ($r['alerted']) {
            $this->line("  all-clear to " . count($r['to']) . " staff, {$r['read']} alert(s) marked read");
        }
    }

    private function askParents(CheckEventNotifier $notifier, int $agencyId, array $row, string $tz, bool $dry): int
    {
        $name = $row['first_name'];
        $at = Carbon::parse($row['expected'], $tz)->format('g:i A');
        $title = "🛡️ Has {$name} arrived?";
        $body = "{$name} was expected at {$row['centre_name']} around {$at} and hasn't been signed in. "
            . "If they're not coming in today, please tell us in the app (Not attending today). "
            . "If they're on the way, no need to reply.";

        $guardians = DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')
            ->where('g.family_id', $row['family_id'])->whereNull('u.deleted_at')
            ->get(['u.id', 'u.email', 'u.phone', 'u.first_name', 'u.last_name']);
        $n = 0;
        foreach ($guardians as $g) {
            $prefs = $notifier->prefsFor((int) $g->id);
            if ($dry) { $n++; continue; }
            try {
                if ($prefs['push']) {
                    \App\Support\Notify::write([
                        'user_id' => $g->id, 'type' => 'safe_arrival', 'title' => $title, 'body' => $body,
                        'data' => json_encode(['link' => '#today', 'child_id' => $row['child_id']]), 'created_at' => now(),
                    ]);
                    app(FcmService::class)->sendToUser((int) $g->id, $title, $body, '#today');
                }
                if ($prefs['email'] && $g->email) {
                    $this->email($agencyId, (string) $g->email, trim($g->first_name . ' ' . $g->last_name), $title, $body);
                }
                if ($prefs['sms'] && $g->phone) {
                    // 'checkin_reminder' is the kind of text this is: the agency's and the
                    // person's own switches for it apply inside sendOne.
                    app(\App\Http\Controllers\Api\SmsController::class)
                        ->sendOne($agencyId, (int) $g->id, (string) $g->phone, $body, 'checkin_reminder');
                }
                $n++;
            } catch (\Throwable $e) {
                Log::warning('Safe Arrival parent message failed', ['child' => $row['child_id'], 'user' => $g->id, 'error' => $e->getMessage()]);
            }
        }

        return $n;
    }

    /**
     * @return array{0:int,1:string[],2:bool} how many staff were alerted, the names of those no phone
     *         notification reached, and whether the directors/admins were included
     */
    private function alertStaff(int $agencyId, array $cfg, array $row, string $tz, bool $dry, int $parentsAsked): array
    {
        // The people standing in that room, and the directors/admins the agency chose (notify_directors /
        // notify_admins, 2026-10-22) - now, or later if the agency chose "only if still open" (up_timing, 2026-10-30).
        // A room with no educator to tell goes straight up either way.
        $edu = SafeArrival::recipients($agencyId, (int) $row['centre_id'], (int) $row['room_id'], $cfg, true, false);
        $upNow = $cfg['up_timing'] !== 'after' || ! $edu;

        $at = Carbon::parse($row['expected'], $tz)->format('g:i A');
        $title = "⚠️ Safe Arrival: {$row['name']} not here";
        $onWay = (($row['check']->parent_response ?? null) === 'on_the_way');
        $body = "{$row['name']} ({$row['room_name']}) was expected around {$at}, hasn't been signed in, "
            . "and no absence has been reported. "
            . ($onWay ? "A parent said on the Safe Arrival call that they were on the way, and the child still isn't here. Please call them."
               : ($parentsAsked > 0 ? "The parents were asked and haven't answered. Please call them."
                                    : "No message could be sent to the parents. Please call them."));
        // Educators: push, email to anyone it does not reach. Directors/admins: the channels the agency chose.
        [$n, $unreached] = SafeArrival::alertTiered($agencyId, $cfg, (int) $row['centre_id'], (int) $row['room_id'], true, $upNow,
            $title, $body, (int) $row['child_id'], $dry);

        return [$n, $unreached, $upNow];
    }

    /**
     * END OF DAY — Safe Departure (2026-10-22). See App\Support\SafeDeparture.
     *   due, not yet alerted       -> the room's educators (straight up if the room has none)
     *   educators silent N minutes -> the directors/admins the agency chose
     *   still open N minutes later -> the parents
     *   signed out by a person     -> closed as checked_out
     */
    private function departures(CheckEventNotifier $notifier, int $agencyId, array $cfg, string $date, string $tz, Carbon $now, bool $dry, bool $quietParents): void
    {
        foreach (\App\Support\SafeDeparture::today($agencyId, null, null, $date) as $row) {
            $c = $row['check'];
            $at = Carbon::parse($row['expected'], $tz)->format('g:i A');
            $what = $row['expected_source'] === 'pickup' ? "the usual pickup at {$at}" : "closing at {$at}";

            if ($row['state'] === 'checked_out') {
                if (! $dry) {
                    DB::table('safe_departure_checks')->where('id', $c->id)->update([
                        'status' => 'checked_out', 'resolution' => 'checked_out',
                        'checked_out_at' => $row['last_event']->occurred_at ?? now(), 'resolved_at' => now(), 'updated_at' => now(),
                    ]);
                }
                $this->line(($dry ? '[dry] ' : '') . "departure closed {$row['name']}: signed out");
                // ALL CLEAR (2026-11-02): the staff who were alerted hear that the child went home.
                $outAt = \App\Support\AgencyTime::fmt($row['last_event']->occurred_at ?? now(), $tz);
                [$t, $b] = \App\Support\SafeArrivalAllClear::signedOutText($row['name'], $row['room_name'] ?: null, (string) $outAt);
                $this->allClear($agencyId, 'departure', $c, $t, $b, $dry);
                continue;
            }

            // 1. The room's educators — the commonest answer is a forgotten sign-out.
            if ($row['state'] === 'overdue' && ! $c) {
                $ids = SafeArrival::recipients($agencyId, $row['centre_id'], $row['room_id'], $cfg, true, false);
                $upNow = ! $ids;   // nobody in the room: straight to directors/admins
                if ($upNow) {
                    $ids = SafeArrival::recipients($agencyId, $row['centre_id'], $row['room_id'], $cfg, false, true);
                }
                $title = "🕔 Still signed in: {$row['name']}";
                $body = "{$row['name']}" . ($row['room_name'] ? " ({$row['room_name']})" : '') . " is still signed in at {$row['centre_name']}, past {$what}. "
                    . "If {$row['first_name']} has gone home, sign them out with the time they left. "
                    . "If {$row['first_name']} is still with you, open Safe Arrival and mark them as still here.";
                [$n, $unreached] = $dry ? [count($ids), []]
                    : SafeArrival::alertUsers($agencyId, $ids, $title, $body, $row['child_id'], '#safe-arrival', ($upNow ? SafeArrival::upOpts($cfg) : []) + ['kind' => 'departure']);
                if (! $dry) {
                    DB::table('safe_departure_checks')->insertOrIgnore([
                        'agency_id' => $agencyId, 'centre_id' => $row['centre_id'], 'room_id' => $row['room_id'],
                        'child_id' => $row['child_id'], 'check_date' => $date, 'checked_in_at' => $row['checked_in_at'],
                        'expected_time' => $row['expected'] . ':00', 'due_at' => $row['due_at'],
                        'status' => $upNow ? 'escalated' : 'educator',
                        'educator_alerted_at' => now(), 'educators_alerted' => $upNow ? 0 : $n,
                        'escalated_at' => $upNow ? now() : null, 'staff_alerted' => $upNow ? $n : 0,
                        'staff_unreached' => $unreached ? mb_substr(implode(', ', $unreached), 0, 500) : null,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                $this->line(($dry ? '[dry] ' : '') . "departure: {$row['name']} still in — alerted {$n} " . ($upNow ? 'directors/admins (no room staff)' : 'educator(s)'));
                continue;
            }
            if (! $c || $c->resolved_at || in_array($row['state'], ['unconfirmed', 'waiting', 'resolved', 'running_late'], true)) {
                continue;
            }
            // A late pickup that has now passed restarts the clock from its time, so the next rung is not instant.
            $lateFrom = ! empty($row['late']) ? Carbon::parse($row['late']['due_at']) : null;

            // 2. The educators have not answered: directors/admins.
            if (! $c->escalated_at && $c->educator_alerted_at
                && $now->greaterThanOrEqualTo(Carbon::parse($c->educator_alerted_at)->max($lateFrom ?? Carbon::parse($c->educator_alerted_at))->addMinutes($cfg['departure_escalate_after_minutes']))) {
                $ids = SafeArrival::recipients($agencyId, $row['centre_id'], $row['room_id'], $cfg, false, true);
                $late = (int) max(0, Carbon::parse($row['due_at'])->subMinutes($cfg['departure_grace_minutes'])->diffInMinutes($now));
                $title = "⚠️ Safe Arrival: {$row['name']} still signed in";
                $body = "{$row['name']}" . ($row['room_name'] ? " ({$row['room_name']}, {$row['centre_name']})" : " ({$row['centre_name']})")
                    . " is still signed in {$late} minutes after {$what}, and nobody has confirmed where {$row['first_name']} is. "
                    . 'The educator was alerted at ' . Carbon::parse($c->educator_alerted_at)->timezone($tz)->format('g:i A') . '. '
                    . "Please check where {$row['first_name']} is.";
                [$n, $unreached] = $dry ? [count($ids), []]
                    : SafeArrival::alertUsers($agencyId, $ids, $title, $body, $row['child_id'], '#safe-arrival', SafeArrival::upOpts($cfg) + ['kind' => 'departure']);
                if (! $dry) {
                    DB::table('safe_departure_checks')->where('id', $c->id)->update([
                        'status' => 'escalated', 'escalated_at' => now(), 'staff_alerted' => $n,
                        'staff_unreached' => $unreached ? mb_substr(implode(', ', $unreached), 0, 500) : $c->staff_unreached,
                        'updated_at' => now(),
                    ]);
                }
                $this->line(($dry ? '[dry] ' : '') . "departure: escalated {$row['name']} to {$n} director(s)/admin(s)");
                continue;
            }

            // 3. Still open: ask the parents (Anthony: only if staff do not resolve).
            if ($c->escalated_at && ! $c->parents_notified_at
                && $now->greaterThanOrEqualTo(Carbon::parse($c->escalated_at)->max($lateFrom ?? Carbon::parse($c->escalated_at))->addMinutes($cfg['departure_parents_after_minutes']))) {
                $sent = $quietParents ? 0 : $this->askParentsPickup($notifier, $agencyId, $row, $what, $dry);
                if (! $dry) {
                    DB::table('safe_departure_checks')->where('id', $c->id)->update([
                        'status' => 'parents', 'parents_notified_at' => now(), 'parents_notified' => $sent, 'updated_at' => now(),
                    ]);
                }
                $this->line(($dry ? '[dry] ' : '') . "departure: asked parents of {$row['name']} ({$sent})" . ($quietParents ? ' [agency notifications off]' : ''));
            }
        }
    }

    private function askParentsPickup(CheckEventNotifier $notifier, int $agencyId, array $row, string $what, bool $dry): int
    {
        $name = $row['first_name'];
        $title = "🛡️ Has {$name} been picked up?";
        $body = "Our records still show {$name} signed in at {$row['centre_name']}, past {$what}. "
            . "If {$name} is with you, please let the centre know so they can update the record. "
            . 'If not, please contact the centre right away.';

        $guardians = DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')
            ->where('g.family_id', $row['family_id'])->whereNull('u.deleted_at')
            ->get(['u.id', 'u.email', 'u.phone', 'u.first_name', 'u.last_name']);
        $n = 0;
        foreach ($guardians as $g) {
            $prefs = $notifier->prefsFor((int) $g->id);
            if ($dry) { $n++; continue; }
            try {
                if ($prefs['push']) {
                    \App\Support\Notify::write([
                        'user_id' => $g->id, 'type' => 'safe_arrival', 'title' => $title, 'body' => $body,
                        'data' => json_encode(['link' => '#today', 'child_id' => $row['child_id']]), 'created_at' => now(),
                    ]);
                    app(FcmService::class)->sendToUser((int) $g->id, $title, $body, '#today');
                }
                if ($prefs['email'] && $g->email) {
                    $this->email($agencyId, (string) $g->email, trim($g->first_name . ' ' . $g->last_name), $title, $body, false);
                }
                if ($prefs['sms'] && $g->phone) {
                    app(\App\Http\Controllers\Api\SmsController::class)
                        ->sendOne($agencyId, (int) $g->id, (string) $g->phone, $body, 'checkin_reminder');
                }
                $n++;
            } catch (\Throwable $e) {
                Log::warning('Safe Departure parent message failed', ['child' => $row['child_id'], 'user' => $g->id, 'error' => $e->getMessage()]);
            }
        }

        return $n;
    }

    private function email(int $agencyId, string $to, string $toName, string $title, string $body, bool $arrival = true): void
    {
        $toName = (string) (strtok(trim($toName), ' ') ?: '');   // first name only in email (standing rule)
        $html = EmailTemplate::wrap($agencyId,
            '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;">' . e($body) . '</p>'
            . ($arrival ? '<p style="margin:14px 0 0;font-size:13.5px;color:#475569;line-height:1.6;">Open KiddieTrac and tap '
            . '<strong>“Not attending today”</strong> to let the team know. That also stops these messages.</p>' : ''),
            ['eyebrow_icon' => \App\Support\SafeArrival::EMAIL_ICON, 'eyebrow' => 'SAFE ARRIVAL', 'title' => $title, 'subtitle' => 'Attendance', 'preheader' => $body]);
        dispatch(function () use ($agencyId, $to, $toName, $html, $title) {
            AgencyMailer::forAgency($agencyId)->html($html, function ($m) use ($to, $toName, $title) {
                $m->to($to, $toName ?: null)->from('noreply@kiddietrac.com', 'KiddieTrac')->subject($title);
            });
        })->onQueue('mail');
    }
}
