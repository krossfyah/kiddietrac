<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\SafeArrival;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Safe Arrival board for staff (2026-09-29). See App\Support\SafeArrival.
 *
 * Scope: an educator sees the rooms they are assigned to, a centre director their
 * centres, an agency admin or platform admin the whole active agency.
 */
class SafeArrivalController extends Controller
{
    /** GET /safe-arrival/today?centre_id= */
    public function today(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        [$centres, $rooms, $role] = $this->scope($request, $agencyId);
        if ($request->filled('centre_id')) {
            $want = (int) $request->query('centre_id');
            $centres = $centres === null ? [$want] : array_values(array_intersect($centres, [$want]));
        }
        $cfg = SafeArrival::settings($agencyId);
        $tz = \App\Support\AgencyTime::tz($agencyId);
        $rows = SafeArrival::today($agencyId, $centres, $rooms);

        $needGuardians = array_values(array_unique(array_map(fn ($r) => $r['family_id'],
            array_filter($rows, fn ($r) => in_array($r['state'], ['overdue', 'escalated', 'on_the_way', 'dropped_off'], true)))));
        $guardians = [];
        if ($needGuardians) {
            foreach (DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')
                ->whereIn('g.family_id', $needGuardians)->whereNull('u.deleted_at')
                ->get(['g.family_id', 'u.first_name', 'u.last_name', 'u.phone', 'g.relationship']) as $g) {
                $guardians[(int) $g->family_id][] = ['name' => trim($g->first_name . ' ' . $g->last_name),
                    'phone' => $g->phone, 'relationship' => $g->relationship];
            }
        }

        $fmt = fn ($ts) => $ts ? Carbon::parse($ts)->timezone($tz)->format('g:i A') : null;
        // Who took the headcount that counted a child (first names: the board is staff-only).
        $takers = [];
        $takerIds = array_values(array_unique(array_filter(array_merge(array_map(fn ($r) => $r['counted']['by'] ?? null, $rows),
            array_map(fn ($r) => $r['late']['reported_by_id'] ?? null, $rows)))));
        if ($takerIds) {
            $takers = DB::table('users')->whereIn('id', $takerIds)->pluck('first_name', 'id')->all();
        }
        $out = array_values(array_map(function ($r) use ($guardians, $fmt, $tz, $takers) {
            $c = $r['check'];
            return [
                // Running late (2026-11-06): "here by 10:15", who logged it, and when Safe Arrival checks again.
                'late' => ! empty($r['late']) ? [
                    'by' => Carbon::parse($r['late']['by'], $tz)->format('g:i A'), 'by_hm' => $r['late']['by'],
                    'note' => $r['late']['note'], 'logged_by' => $takers[$r['late']['reported_by_id']] ?? null,
                    'source' => $r['late']['source'] ?? 'staff',
                    'check_at' => $fmt($r['late']['due_at']),
                ] : null,
                'counted' => ! empty($r['counted']) ? $fmt($r['counted']['at']) : null,
                'counted_by' => ! empty($r['counted']) ? ($takers[$r['counted']['by']] ?? null) : null,
                'counted_until' => ! empty($r['counted']['until']) ? $fmt($r['counted']['until']) : null,
                // A count that ran out (headcount_hold_minutes): "Counted at 9:04, expired 11:04".
                'count_expired' => ! empty($r['count_expired']) ? ['at' => $fmt($r['count_expired']['at']), 'until' => $fmt($r['count_expired']['until'])] : null,
                'child_id' => $r['child_id'], 'name' => $r['name'],
                'room_name' => $r['room_name'], 'centre_id' => $r['centre_id'], 'centre_name' => $r['centre_name'],
                'expected' => Carbon::parse($r['expected'], $tz)->format('g:i A'),
                'expected_is_default' => $r['expected_is_default'],
                'due' => $fmt($r['due_at']),
                'state' => $r['state'],
                'arrived' => $fmt($r['arrived_at']),
                'absent' => $r['absent'],
                'parents_notified' => $c ? $fmt($c->parents_notified_at) : null,
                'escalated' => $c ? $fmt($c->escalated_at) : null,
                'staff_unreached' => $c ? ($c->staff_unreached ?? null) : null,
                'resolution' => $c ? $c->resolution : null,
                'parent_response' => $c ? ($c->parent_response ?? null) : null,
                'parent_response_at' => $c ? $fmt($c->parent_response_at ?? null) : null,
                'called' => $c ? $fmt($c->call_started_at ?? null) : null,
                'note' => $c ? $c->note : null,
                'resolved' => $c ? $fmt($c->resolved_at) : null,
                'guardians' => $guardians[$r['family_id']] ?? [],
            ];
        }, $rows));

        // Most urgent first: escalated, overdue, then waiting, then settled.
        $rank = ['dropped_off' => 0, 'escalated' => 1, 'overdue' => 2, 'on_the_way' => 3, 'running_late' => 4, 'counted' => 5, 'waiting' => 6, 'resolved' => 7, 'absent' => 8, 'arrived' => 9];
        usort($out, fn ($a, $b) => [$rank[$a['state']] ?? 9, $a['name']] <=> [$rank[$b['state']] ?? 9, $b['name']]);
        $counts = array_count_values(array_column($out, 'state'));

        // An educator's picker: the centres of their own rooms, not every centre (2026-10-05).
        $pick = $centres;
        if ($pick === null && $rooms !== null) {
            $pick = DB::table('rooms')->whereIn('id', $rooms ?: [0])->pluck('centre_id')->map(fn ($x) => (int) $x)->unique()->values()->all();
        }
        $centreList = \App\Support\Live::centres(DB::table('centres'))->where('agency_id', $agencyId)
            ->when($pick !== null && ! $request->filled('centre_id'), fn ($q) => $q->whereIn('id', $pick ?: [0]))
            ->orderBy('name')->get(['id', 'name']);

        return response()->json([
            'date' => \App\Support\AgencyTime::today($agencyId),
            'settings' => $cfg,
            'can_configure' => $role === 'admin',
            'counts' => ['expected' => count($out)] + $counts,
            'rows' => $out,
            'departures' => $this->departureRows($agencyId, $centres, $rooms, $tz),
            'centres' => $centreList,
        ]);
    }

    /**
     * GET /safe-arrival/history?from=&to=&centre_id=&kind=all|arrival|departure&q=&alerted=1
     *
     * PAST CHECKS (2026-11-03). Anthony: "for safe arrival i need to be able to see past history on the safe
     * arrival page" - and, the same evening, he could not tell from the board that iLearn's admins had been
     * contacted (the board only ever named the people NO phone alert reached). So each past check comes back
     * with its whole escalation path: when the parents were asked and called, what they answered, and by
     * name who was alerted - room educators apart from directors/admins - read from the alert bells themselves
     * (notifications type safe_arrival carry child_id + sa_kind).
     *
     * History, so withdrawn children and closed centres stay in it (kt:leak-sweep HISTORICAL). Scope as the
     * board: admins the agency, directors their centres, educators their rooms. Up to 92 days per request.
     */
    public function history(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        [$centres, $rooms, $role] = $this->scope($request, $agencyId);
        if ($role === 'admin') {
            // The past includes centres that have since closed.
            $centres = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->map(fn ($x) => (int) $x)->all() ?: [0];
        }
        $tz = \App\Support\AgencyTime::tz($agencyId);
        $today = \App\Support\AgencyTime::today($agencyId);
        $d = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
            'centre_id' => ['nullable', 'integer'], 'kind' => ['nullable', 'in:all,arrival,departure'],
            'q' => ['nullable', 'string', 'max:80'], 'alerted' => ['nullable', 'boolean'],
        ]);
        $to = $d['to'] ?? $today;
        $from = $d['from'] ?? Carbon::parse($to)->subDays(13)->toDateString();
        if ($from > $to) [$from, $to] = [$to, $from];
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 92) $from = Carbon::parse($to)->subDays(92)->toDateString();
        $kind = $d['kind'] ?? 'all';
        if (! empty($d['centre_id'])) {
            $want = (int) $d['centre_id'];
            $centres = $centres === null ? [$want] : array_values(array_intersect($centres, [$want]));
            if (! $centres) $centres = [0];
        }
        $q = trim((string) ($d['q'] ?? ''));
        $onlyAlerted = ! empty($d['alerted']);

        $base = function (string $table) use ($agencyId, $centres, $rooms, $from, $to, $q) {
            return DB::table($table . ' as k')->join('children as ch', 'ch.id', '=', 'k.child_id')
                ->leftJoin('rooms as r', 'r.id', '=', 'k.room_id')->leftJoin('centres as ce', 'ce.id', '=', 'k.centre_id')
                ->leftJoin('users as rb', 'rb.id', '=', 'k.resolved_by_id')
                ->where('k.agency_id', $agencyId)->whereBetween('k.check_date', [$from, $to])
                ->when($centres !== null, fn ($w) => $w->whereIn('k.centre_id', $centres))
                ->when($rooms !== null, fn ($w) => $w->whereIn('k.room_id', $rooms ?: [0]))
                ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('ch.first_name', 'like', '%' . $q . '%')
                    ->orWhere('ch.last_name', 'like', '%' . $q . '%')->orWhere('ch.preferred_name', 'like', '%' . $q . '%')
                    ->orWhereRaw("CONCAT(ch.first_name, ' ', ch.last_name) LIKE ?", ['%' . $q . '%'])))
                ->select('k.*', 'ch.first_name', 'ch.last_name', 'ch.preferred_name', 'r.name as room_name', 'ce.name as centre_name',
                    'rb.first_name as rb_first', 'rb.last_name as rb_last');
        };
        $arr = $kind === 'departure' ? collect() : $base('safe_arrival_checks')
            ->when($onlyAlerted, fn ($w) => $w->where(fn ($x) => $x->whereNotNull('k.escalated_at')->orWhereNotNull('k.up_alerted_at')))
            ->orderByDesc('k.check_date')->orderByDesc('k.id')->limit(1000)->get();
        $dep = $kind === 'arrival' || ! \Illuminate\Support\Facades\Schema::hasTable('safe_departure_checks') ? collect() : $base('safe_departure_checks')
            ->when($onlyAlerted, fn ($w) => $w->where(fn ($x) => $x->whereNotNull('k.educator_alerted_at')->orWhereNotNull('k.escalated_at')))
            ->orderByDesc('k.check_date')->orderByDesc('k.id')->limit(1000)->get();

        // Who was alerted, from the bells: [child_id][date][arrival|departure] => user ids. All-clear rows (✅) are not alerts.
        $childIds = $arr->pluck('child_id')->merge($dep->pluck('child_id'))->map(fn ($x) => (int) $x)->unique()->values()->all();
        $alerted = [];
        if ($childIds) {
            $winFrom = Carbon::parse($from . ' 00:00:00', $tz)->utc();
            $winTo = Carbon::parse($to . ' 23:59:59', $tz)->utc();
            foreach (DB::table('notifications')->where('type', 'safe_arrival')->whereBetween('created_at', [$winFrom, $winTo])
                ->where('title', 'not like', '✅%')
                ->get(['user_id', 'data', 'created_at']) as $n) {
                $j = json_decode((string) $n->data, true) ?: [];
                $cid = (int) ($j['child_id'] ?? 0);
                if (! $cid || ! in_array($cid, $childIds, true)) continue;
                $day = Carbon::parse($n->created_at, 'UTC')->timezone($tz)->toDateString();
                $alerted[$cid][$day][($j['sa_kind'] ?? 'arrival') === 'departure' ? 'departure' : 'arrival'][(int) $n->user_id] = true;
            }
        }
        $uids = [];
        foreach ($alerted as $byDay) foreach ($byDay as $byKind) foreach ($byKind as $set) $uids += $set;
        $names = $uids ? DB::table('users')->whereIn('id', array_keys($uids))->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn ($u) => [(int) $u->id => trim($u->first_name . ' ' . $u->last_name)])->all() : [];
        $mgrRole = [];
        if ($uids) {
            foreach (DB::table('role_assignments')->whereIn('user_id', array_keys($uids))->where('active', 1)
                ->whereIn('role', ['agency_admin', 'platform_admin', 'centre_director'])->get(['user_id', 'role', 'agency_id']) as $ra) {
                if ($ra->role === 'centre_director') { $mgrRole[(int) $ra->user_id] = $mgrRole[(int) $ra->user_id] ?? 'Director'; }
                elseif ($ra->role === 'platform_admin' || (int) $ra->agency_id === $agencyId) { $mgrRole[(int) $ra->user_id] = 'Admin'; }
            }
        }
        // The child's own guardians get a safe_arrival bell too (the parent ask): they are the parents, not staff.
        $guardianOf = [];
        if ($childIds) {
            foreach (DB::table('children as ch')->join('guardians as g', 'g.family_id', '=', 'ch.family_id')
                ->whereIn('ch.id', $childIds)->get(['ch.id', 'g.user_id']) as $g) $guardianOf[(int) $g->id][(int) $g->user_id] = true;
        }
        $who = function (int $childId, string $date, string $k) use ($alerted, $names, $mgrRole, $guardianOf) {
            $edu = []; $mgr = []; $par = [];
            foreach (array_keys($alerted[$childId][$date][$k] ?? []) as $uid) {
                $nm = $names[$uid] ?? null;
                if (! $nm) continue;
                if (isset($guardianOf[$childId][$uid])) $par[] = $nm;
                elseif (isset($mgrRole[$uid])) $mgr[] = $nm . ' (' . $mgrRole[$uid] . ')';
                else $edu[] = $nm;
            }
            sort($edu); sort($mgr); sort($par);

            return ['educators' => array_values(array_unique($edu)), 'managers' => array_values(array_unique($mgr)), 'parents' => array_values(array_unique($par))];
        };

        $t = fn ($ts) => $ts ? Carbon::parse($ts)->timezone($tz)->format('g:i A') : null;
        $name = fn ($r) => $r->preferred_name ?: trim($r->first_name . ' ' . $r->last_name);
        $by = fn ($r) => $r->resolved_by_id ? trim(($r->rb_first ?? '') . ' ' . ($r->rb_last ?? '')) : null;
        $unreached = fn ($s) => $s ? implode(', ', array_values(array_unique(array_map('trim', explode(',', (string) $s))))) : null;
        $answer = ['absent' => 'absent today', 'on_the_way' => 'on the way', 'dropped_off' => 'already dropped off'];
        $resLabel = ['arrived' => 'Signed in', 'absent' => 'Absent', 'parent_contacted' => 'Parent contacted', 'other' => 'Other',
            'checked_out' => 'Signed out', 'picked_up' => 'Picked up', 'still_here' => 'Still here (late pickup)', 'unconfirmed' => 'Signed out automatically, pickup not confirmed',
            'centre_closed' => 'Centre closed that day', 'unresolved' => 'Not resolved by end of day',
            'not_expected' => 'No longer expected (withdrawn or taken off the day)'];

        $rows = [];
        foreach ($arr as $r) {
            $w = $who((int) $r->child_id, (string) $r->check_date, 'arrival');
            $upSame = $r->up_alerted_at && $r->escalated_at && Carbon::parse($r->up_alerted_at)->diffInSeconds(Carbon::parse($r->escalated_at)) < 60;
            $steps = array_values(array_filter([
                $r->parents_notified_at ? [$t($r->parents_notified_at), 'Parents asked' . ((int) $r->parents_notified > 1 ? ' (' . (int) $r->parents_notified . ')' : '')] : null,
                ($r->call_started_at ?? null) ? [$t($r->call_started_at), 'Parents phoned' . ((int) ($r->calls_placed ?? 0) > 1 ? ' (' . (int) $r->calls_placed . ' calls)' : '')] : null,
                ($r->parent_response_at ?? null) ? [$t($r->parent_response_at), 'Parent answered: ' . ($answer[$r->parent_response] ?? $r->parent_response)] : null,
                $r->escalated_at ? [$t($r->escalated_at), $upSame ? 'Room educators, directors and admins alerted' : 'Room educators alerted'] : null,
                ($r->up_alerted_at && ! $upSame) ? [$t($r->up_alerted_at), 'Directors/admins alerted'] : null,
                $r->arrived_at ? [$t($r->arrived_at), 'Signed in'] : null,
                ($r->resolved_at && $r->resolution !== 'arrived') ? [$t($r->resolved_at), 'Closed: ' . ($resLabel[$r->resolution] ?? $r->resolution) . ($by($r) ? ' by ' . $by($r) : '')] : null,
            ]));
            $rows[] = [
                'kind' => 'arrival', 'id' => (int) $r->id, 'date' => (string) $r->check_date, 'child_id' => (int) $r->child_id, 'name' => $name($r),
                'room_name' => $r->room_name, 'centre_name' => $r->centre_name,
                'expected' => $r->expected_time ? Carbon::parse($r->check_date . ' ' . $r->expected_time, $tz)->format('g:i A') : null,
                'status' => (string) $r->status, 'outcome' => $resLabel[$r->resolution] ?? (in_array($r->status, ['escalated', 'open', 'notified'], true) && ! $r->resolved_at
                    ? ((string) $r->check_date === $today ? 'Still open' : 'Never closed') : ucfirst(str_replace('_', ' ', (string) $r->status))),
                'alerted' => (bool) ($r->escalated_at || $r->up_alerted_at), 'steps' => $steps,
                'educators' => $w['educators'], 'managers' => $w['managers'], 'parents' => $w['parents'], 'unreached' => $unreached($r->staff_unreached ?? null),
                'note' => $r->note, 'sort' => $r->check_date . ' ' . ($r->due_at ?? $r->created_at),
            ];
        }
        foreach ($dep as $r) {
            $w = $who((int) $r->child_id, (string) $r->check_date, 'departure');
            $steps = array_values(array_filter([
                ($r->educator_alerted_at ?? null) ? [$t($r->educator_alerted_at), 'Room educators asked'] : null,
                $r->escalated_at ? [$t($r->escalated_at), 'Directors/admins alerted'] : null,
                $r->parents_notified_at ? [$t($r->parents_notified_at), 'Parents asked'] : null,
                ($r->auto_signed_out_at ?? null) ? [$t($r->auto_signed_out_at), 'Signed out automatically, pickup not confirmed'] : null,
                ($r->checked_out_at ?? null) ? [$t($r->checked_out_at), 'Signed out'] : null,
                ($r->resolved_at && $r->resolution !== 'checked_out') ? [$t($r->resolved_at), 'Closed: ' . ($resLabel[$r->resolution] ?? $r->resolution) . ($by($r) ? ' by ' . $by($r) : '')] : null,
            ]));
            $rows[] = [
                'kind' => 'departure', 'id' => (int) $r->id, 'date' => (string) $r->check_date, 'child_id' => (int) $r->child_id, 'name' => $name($r),
                'room_name' => $r->room_name, 'centre_name' => $r->centre_name,
                'expected' => $r->expected_time ? Carbon::parse($r->check_date . ' ' . $r->expected_time, $tz)->format('g:i A') : null,
                'status' => (string) $r->status, 'outcome' => $resLabel[$r->resolution] ?? ($resLabel[$r->status] ?? ucfirst(str_replace('_', ' ', (string) $r->status))),
                'alerted' => (bool) (($r->educator_alerted_at ?? null) || $r->escalated_at), 'steps' => $steps,
                'educators' => $w['educators'], 'managers' => $w['managers'], 'parents' => $w['parents'], 'unreached' => $unreached($r->staff_unreached ?? null),
                'note' => $r->note, 'sort' => $r->check_date . ' ' . ($r->due_at ?? $r->created_at),
            ];
        }
        usort($rows, fn ($a, $b) => strcmp($b['sort'], $a['sort']));
        $rows = array_map(function ($x) { unset($x['sort']); return $x; }, array_slice($rows, 0, 1500));

        return response()->json(['from' => $from, 'to' => $to, 'today' => $today, 'rows' => $rows,
            'counts' => ['checks' => count($rows), 'alerted' => count(array_filter($rows, fn ($x) => $x['alerted']))]]);
    }

    /** End of day (Safe Departure, 2026-10-22): children still signed in, and today's checks. */
    private function departureRows(int $agencyId, ?array $centres, ?array $rooms, string $tz): array
    {
        $rows = \App\Support\SafeDeparture::today($agencyId, $centres, $rooms);
        if (! $rows) {
            return [];
        }
        $fams = array_values(array_unique(array_map(fn ($r) => $r['family_id'], $rows)));
        $guardians = [];
        foreach (DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')
            ->whereIn('g.family_id', $fams)->whereNull('u.deleted_at')
            ->get(['g.family_id', 'u.first_name', 'u.last_name', 'u.phone', 'g.relationship']) as $g) {
            $guardians[(int) $g->family_id][] = ['name' => trim($g->first_name . ' ' . $g->last_name),
                'phone' => $g->phone, 'relationship' => $g->relationship];
        }
        $fmt = fn ($ts) => $ts ? Carbon::parse($ts)->timezone($tz)->format('g:i A') : null;
        $takerIds = array_values(array_unique(array_filter(array_map(fn ($r) => $r['late']['reported_by_id'] ?? null, $rows))));
        $takers = $takerIds ? DB::table('users')->whereIn('id', $takerIds)->pluck('first_name', 'id')->all() : [];
        $out = array_values(array_map(function ($r) use ($guardians, $fmt, $tz, $takers) {
            $c = $r['check'];
            $e = $r['last_event'];
            return [
                // Late pickup (2026-11-06): "picked up by 6:40", who said so, and when the end-of-day check runs again.
                'late' => ! empty($r['late']) ? [
                    'by' => Carbon::parse($r['late']['by'], $tz)->format('g:i A'), 'by_hm' => $r['late']['by'],
                    'note' => $r['late']['note'], 'logged_by' => $takers[$r['late']['reported_by_id']] ?? null,
                    'source' => $r['late']['source'], 'check_at' => $fmt($r['late']['due_at']),
                ] : null,
                'child_id' => $r['child_id'], 'name' => $r['name'],
                'room_name' => $r['room_name'], 'centre_id' => $r['centre_id'], 'centre_name' => $r['centre_name'],
                'expected' => Carbon::parse($r['expected'], $tz)->format('g:i A'),
                'expected_source' => $r['expected_source'],
                'due' => $fmt($r['due_at']),
                'state' => $r['state'],
                'checked_in' => $fmt($r['checked_in_at']),
                'signed_out' => ($e && ! $r['still_in']) ? $fmt($e->occurred_at) : null,
                'educator_alerted' => $c ? $fmt($c->educator_alerted_at) : null,
                'escalated' => $c ? $fmt($c->escalated_at) : null,
                'parents_notified' => $c ? $fmt($c->parents_notified_at) : null,
                'auto_signed_out' => $c ? $fmt($c->auto_signed_out_at) : null,
                'staff_unreached' => $c ? $c->staff_unreached : null,
                'resolution' => $c ? $c->resolution : null,
                'note' => $c ? $c->note : null,
                'resolved' => $c ? $fmt($c->resolved_at) : null,
                'guardians' => $guardians[$r['family_id']] ?? [],
            ];
        }, $rows));
        $rank = ['unconfirmed' => 0, 'parents' => 1, 'escalated' => 2, 'overdue' => 3, 'running_late' => 4, 'waiting' => 5, 'checked_out' => 6, 'resolved' => 7];
        usort($out, fn ($a, $b) => [$rank[$a['state']] ?? 9, $a['name']] <=> [$rank[$b['state']] ?? 9, $b['name']]);

        return $out;
    }

    /**
     * POST /safe-arrival/departure/{childId}/resolve
     *   {resolution: picked_up|still_here|other, time?: "HH:MM", note?}
     *
     * picked_up signs the child out at that time (or, if the automatic sign-off already did,
     * corrects its time and marks it confirmed). still_here: the child is with staff — a late
     * pickup; the child stays signed in. other: what happened, in a note.
     */
    public function resolveDeparture(Request $request, int $childId): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        [$centres, $rooms] = $this->scope($request, $agencyId);
        $data = $request->validate([
            'resolution' => ['required', 'in:picked_up,still_here,other'],
            'time' => ['nullable', 'regex:/^\d{2}:\d{2}$/'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        if ($data['resolution'] === 'other' && empty(trim((string) ($data['note'] ?? '')))) {
            return response()->json(['message' => 'Add a short note saying what happened.', 'errors' => ['note' => ['Required.']]], 422);
        }
        $rows = \App\Support\SafeDeparture::today($agencyId, $centres, $rooms);
        $row = $rows[$childId] ?? null;
        abort_unless($row, 404, 'That child is not on today\'s end-of-day list.');
        $tz = \App\Support\AgencyTime::tz($agencyId);
        $date = \App\Support\AgencyTime::today($agencyId);
        $uid = (int) $request->user()->id;
        $who = trim(($request->user()->first_name ?? '') . ' ' . ($request->user()->last_name ?? ''));
        $e = $row['last_event'];
        $outAt = null;

        if ($data['resolution'] === 'picked_up') {
            $outAt = $data['time'] ? Carbon::parse($date . ' ' . $data['time'], $tz) : Carbon::now($tz);
            $inAt = $row['checked_in_at'] ? Carbon::parse($row['checked_in_at']) : null;
            if ($outAt->copy()->utc()->gt(Carbon::now()->addMinutes(1))) {
                return response()->json(['message' => 'The pickup time cannot be in the future.', 'errors' => ['time' => ['Future.']]], 422);
            }
            if ($inAt && $outAt->copy()->utc()->lt($inAt)) {
                return response()->json(['message' => 'The pickup time is before ' . $row['first_name'] . ' was signed in ('
                    . $inAt->copy()->timezone($tz)->format('g:i A') . ').', 'errors' => ['time' => ['Too early.']]], 422);
            }
            $note = 'Pickup confirmed in Safe Arrival by ' . ($who ?: 'staff') . '.';
            if ($row['still_in']) {
                DB::table('check_events')->insert([
                    'child_id' => $childId, 'room_id' => $row['room_id'], 'event_type' => 'check_out',
                    'by_user_id' => $uid, 'recorded_by_id' => $uid,
                    // Entered after the fact: says so on the row, as AttendanceCorrection does.
                    'backdated' => $outAt->copy()->utc()->lt(Carbon::now()->subMinutes(5)),
                    'occurred_at' => $outAt->copy()->utc(), 'notes' => $note, 'created_at' => now(),
                ]);
            } elseif ($e && $e->is_automatic) {
                /* The automatic sign-off guessed; a person now says when it really happened.
                   Same trail as the fix-time links (AutoTimeFix): the row stays automatic and
                   gains the correction — the time it replaced, who, from where. */
                $auto = Carbon::parse($e->occurred_at, 'UTC');
                if ($outAt->copy()->utc()->gt($auto)) {
                    return response()->json(['message' => 'That time is after the automatic sign-off (' . $auto->copy()->timezone($tz)->format('g:i A')
                        . '). Choose a time at or before it.', 'errors' => ['time' => ['Too late.']]], 422);
                }
                DB::table('check_events')->where('id', $e->id)->update([
                    'occurred_at' => $outAt->copy()->utc(),
                    'correction_source' => 'safe_arrival', 'original_auto_at' => $e->occurred_at,
                    'corrected_by_user_id' => $uid, 'corrected_at' => now(),
                    'correction_note' => isset($data['note']) && $data['note'] !== '' ? mb_substr($data['note'], 0, 300) : null,
                    'notes' => $note . ' (Automatic sign-off time was ' . $auto->copy()->timezone($tz)->format('g:i A') . '.)',
                ]);
            }
        }

        $vals = [
            'status' => 'resolved', 'resolution' => $data['resolution'], 'note' => $data['note'] ?? null,
            'checked_out_at' => $outAt ? $outAt->copy()->utc() : null,
            'resolved_at' => now(), 'resolved_by_id' => $uid, 'updated_at' => now(),
        ];
        $c = $row['check'];
        if ($c) {
            DB::table('safe_departure_checks')->where('id', $c->id)->update($vals);
        } else {
            DB::table('safe_departure_checks')->insert($vals + [
                'agency_id' => $agencyId, 'centre_id' => $row['centre_id'], 'room_id' => $row['room_id'],
                'child_id' => $childId, 'check_date' => $date, 'checked_in_at' => $row['checked_in_at'],
                'expected_time' => $row['expected'] . ':00', 'due_at' => $row['due_at'], 'created_at' => now(),
            ]);
        }
        $words = ['picked_up' => 'picked up' . ($outAt ? ' at ' . $outAt->format('g:i A') : ''), 'still_here' => 'still here (late pickup)', 'other' => 'other'];
        try {
            \App\Support\Audit::write([
                'user_id' => $uid, 'agency_id' => $agencyId, 'action' => 'safe_departure.resolved',
                'entity_type' => 'child', 'entity_id' => $childId,
                'payload' => json_encode([
                    'summary' => 'End-of-day check for ' . $row['name'] . ': ' . $words[$data['resolution']]
                        . (! empty($data['note']) ? ' - ' . $data['note'] : ''),
                    'child' => $row['name'], 'date' => $date, 'resolution' => $data['resolution'],
                    'time' => $outAt ? $outAt->format('H:i') : null, 'note' => $data['note'] ?? null,
                ]),
                'created_at' => now(),
            ]);
        } catch (\Throwable $x) {
        }
        // ALL CLEAR (2026-11-02): as resolve() - only when this closes a check that had alerted staff.
        if ($c && ! $c->resolved_at) {
            [$t, $b] = \App\Support\SafeArrivalAllClear::departureResolvedText($row['name'], $row['room_name'] ?: null, $data['resolution'],
                $outAt ? $outAt->format('g:i A') : null, trim((string) ($request->user()->first_name ?? '')), $data['note'] ?? null);
            \App\Support\SafeArrivalAllClear::send($agencyId, 'departure', $c, $t, $b, $uid);
        }

        return $this->today($request);
    }

    /**
     * POST /safe-arrival/{childId}/late {time: "HH:MM", note?, kind?: arrival|departure}
     *
     * RUNNING LATE (2026-11-06). A family told the centre the child will be in late (arrival) or picked up late
     * (departure); staff log the time. Safe Arrival / the end-of-day check waits for that time plus its grace before
     * asking the parents or alerting anyone, and the call chain stops ringing meanwhile. If nothing has happened by
     * then, the normal steps run. Logging it again moves the time. Same scope as the board: an educator's own rooms.
     * Parents log the same notice from their Today screen (ParentRunningLateController); both go through SafeArrival::logLate.
     */
    public function late(Request $request, int $childId): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        [$centres, $rooms] = $this->scope($request, $agencyId);
        $data = $request->validate([
            'time' => ['required', 'date_format:H:i'],
            'note' => ['nullable', 'string', 'max:500'],
            'kind' => ['sometimes', 'in:arrival,departure'],
        ]);
        [$err, $row] = SafeArrival::logLate($agencyId, $childId, $data['kind'] ?? 'arrival', $data['time'], $data['note'] ?? null,
            (int) $request->user()->id, 'staff', $centres, $rooms);
        abort_unless($row, 404, "That child is not on today's Safe Arrival list.");
        if ($err) {
            return response()->json(['message' => $err] + ($err === 'Choose a time later than now.' ? ['errors' => ['time' => ['That time has passed.']]] : []), 422);
        }

        return $this->today($request);
    }

    /** DELETE /safe-arrival/{childId}/late?kind= - take back today's notice (the checks run at the usual time). */
    public function cancelLate(Request $request, int $childId): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        [$centres, $rooms] = $this->scope($request, $agencyId);
        $kind = $request->query('kind') === 'departure' ? 'departure' : 'arrival';
        [, $row] = SafeArrival::cancelLate($agencyId, $childId, $kind, (int) $request->user()->id, 'staff', $centres, $rooms);
        abort_unless($row, 404, "That child is not on today's Safe Arrival list.");

        return $this->today($request);
    }

    /** POST /safe-arrival/{childId}/resolve {resolution: absent|parent_contacted|other, note?, reason?} */
    public function resolve(Request $request, int $childId): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        [$centres, $rooms] = $this->scope($request, $agencyId);
        $data = $request->validate([
            'resolution' => ['required', 'in:absent,parent_contacted,other'],
            'note' => ['nullable', 'string', 'max:1000'],
            // The same reasons a parent's "Not attending today" offers.
            'reason' => ['nullable', 'in:sick,appointment,holiday,family,other'],
        ]);
        if ($data['resolution'] !== 'absent' && empty(trim((string) ($data['note'] ?? '')))) {
            return response()->json(['message' => 'Add a short note saying what happened, e.g. "Spoke to mum - on the way".',
                'errors' => ['note' => ['Required.']]], 422);
        }
        $rows = SafeArrival::today($agencyId, $centres, $rooms);
        $row = $rows[$childId] ?? null;
        abort_unless($row, 404, 'That child is not on today\'s Safe Arrival list.');
        $date = \App\Support\AgencyTime::today($agencyId);
        $uid = (int) $request->user()->id;

        if ($data['resolution'] === 'absent') {
            // A real absence, the same record a parent's "Not attending today" makes, so
            // the reminders, the roster and the parent all see it.
            DB::table('child_absences')->updateOrInsert(
                ['child_id' => $childId, 'absent_on' => $date],
                ['reason' => $data['reason'] ?: 'other', 'note' => trim('Recorded by staff (Safe Arrival). ' . ($data['note'] ?? '')),
                    'reported_by_id' => $uid, 'created_at' => now()]
            );
        }
        $vals = [
            'status' => $data['resolution'] === 'absent' ? 'absent' : 'resolved',
            'resolution' => $data['resolution'], 'note' => $data['note'] ?? null,
            'resolved_at' => now(), 'resolved_by_id' => $uid, 'updated_at' => now(),
        ];
        $existing = DB::table('safe_arrival_checks')->where('child_id', $childId)->where('check_date', $date)->first();
        if ($existing) {
            DB::table('safe_arrival_checks')->where('id', $existing->id)->update($vals);
        } else {
            DB::table('safe_arrival_checks')->insert($vals + [
                'agency_id' => $agencyId, 'centre_id' => $row['centre_id'], 'room_id' => $row['room_id'],
                'child_id' => $childId, 'check_date' => $date, 'expected_time' => $row['expected'] . ':00',
                'due_at' => $row['due_at'], 'created_at' => now(),
            ]);
        }
        try {
            \App\Support\Audit::write([
                'user_id' => $uid, 'agency_id' => $agencyId, 'action' => 'safe_arrival.resolved',
                'entity_type' => 'child', 'entity_id' => $childId,
                'payload' => json_encode([
                    'summary' => 'Safe Arrival for ' . $row['name'] . ': ' . str_replace('_', ' ', $data['resolution'])
                        . (! empty($data['note']) ? ' - ' . $data['note'] : ''),
                    'child' => $row['name'], 'date' => $date, 'resolution' => $data['resolution'],
                    'reason' => $data['reason'] ?? null, 'note' => $data['note'] ?? null,
                ]),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
        }
        // ALL CLEAR (2026-11-02): closing an open check that had alerted staff tells them, and reads their alerts.
        // Not for whoever closed it (their alert is still marked read). Never the parents.
        if ($existing && ! $existing->resolved_at) {
            [$t, $b] = \App\Support\SafeArrivalAllClear::resolvedText($row['name'], $row['room_name'] ?: null, $data['resolution'],
                trim((string) ($request->user()->first_name ?? '')), $data['note'] ?? null);
            \App\Support\SafeArrivalAllClear::send($agencyId, 'arrival', $existing, $t, $b, $uid);
        }

        return $this->today($request);
    }

    /** GET /safe-arrival/settings */
    public function settings(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $this->scope($request, $agencyId);

        return response()->json(['settings' => SafeArrival::settings($agencyId) + ['call' => \App\Support\SafeArrivalCall::config($agencyId)],
            'call_defaults' => \App\Support\SafeArrivalCall::DEFAULTS]);
    }

    /** PUT /safe-arrival/settings -- agency admins only. */
    public function saveSettings(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        [, , $role] = $this->scope($request, $agencyId);
        abort_unless($role === 'admin', 403, 'Only an agency admin can change Safe Arrival settings.');
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'grace_minutes' => ['sometimes', 'integer', 'min:0', 'max:180'],
            'default_time' => ['sometimes', 'regex:/^\d{2}:\d{2}$/'],
            'escalate_after_minutes' => ['sometimes', 'integer', 'min:5', 'max:240'],
            'call_parents' => ['sometimes', 'boolean'],
            'call_after_minutes' => ['sometimes', 'integer', 'min:5', 'max:120'],
            'start_next_day' => ['sometimes', 'boolean'],
            'notify_directors' => ['sometimes', 'boolean'],
            'notify_admins' => ['sometimes', 'boolean'],
            'up_timing' => ['sometimes', 'in:with_educators,after'],
            'up_after_minutes' => ['sometimes', 'integer', 'min:5', 'max:120'],
            'up_email' => ['sometimes', 'in:fallback,always,off'],
            'up_sms' => ['sometimes', 'boolean'],
            'departure_enabled' => ['sometimes', 'boolean'],
            'departure_grace_minutes' => ['sometimes', 'integer', 'min:0', 'max:240'],
            'departure_default_time' => ['sometimes', 'regex:/^\d{2}:\d{2}$/'],
            'departure_escalate_after_minutes' => ['sometimes', 'integer', 'min:5', 'max:240'],
            'departure_parents_after_minutes' => ['sometimes', 'integer', 'min:5', 'max:240'],
            'headcount_mode' => ['sometimes', 'in:off,present'],
            'headcount_hold_minutes' => ['sometimes', 'integer', 'in:0,60,120,180'],
            'late_notify_educators' => ['sometimes', 'boolean'],
            'late_notify_directors' => ['sometimes', 'boolean'],
            'late_notify_admins' => ['sometimes', 'boolean'],
            'call' => ['sometimes', 'array'],
        ] + $this->callRules());
        $before = SafeArrival::settings($agencyId) + ['call' => \App\Support\SafeArrivalCall::config($agencyId)];
        $after = SafeArrival::saveSettings($agencyId, \Illuminate\Support\Arr::except($data, ['call']));
        if (isset($data['call'])) {
            \App\Support\SafeArrivalCall::save($agencyId, $data['call']);
        }
        $after['call'] = \App\Support\SafeArrivalCall::config($agencyId);
        try {
            \App\Support\Audit::write([
                'user_id' => (int) $request->user()->id, 'agency_id' => $agencyId, 'action' => 'safe_arrival.settings',
                'entity_type' => 'agency', 'entity_id' => $agencyId,
                'payload' => json_encode(['summary' => 'Safe Arrival ' . ($after['enabled'] ? 'on' : 'off')
                    . ': ' . $after['grace_minutes'] . ' min grace, escalate after ' . $after['escalate_after_minutes']
                    . ' min, default time ' . $after['default_time']
                    . '; end of day ' . ($after['departure_enabled'] ? 'on' : 'off')
                    . '; alerts go to educators' . ($after['notify_directors'] ? ', directors' : '') . ($after['notify_admins'] ? ', admins' : '')
                    . '; headcounts ' . ($after['headcount_mode'] === 'present'
                        ? 'count as arrived for ' . ($after['headcount_hold_minutes'] ? ($after['headcount_hold_minutes'] / 60) . ' h' : 'the rest of the day')
                        : 'ignored'),
                    'before' => $before, 'after' => $after]),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
        }

        return response()->json(['settings' => $after]);
    }

    private function callRules(): array
    {
        $r = [];
        foreach (\App\Support\SafeArrivalCall::TEXT_KEYS as $key) {
            $r['call.' . $key] = ['sometimes', 'nullable', 'string', 'max:' . \App\Support\SafeArrivalCall::MAX_TEXT];
        }

        return $r + [
            'call.offer_late' => ['sometimes', 'boolean'], 'call.offer_dropped' => ['sometimes', 'boolean'],
            'call.tries' => ['sometimes', 'integer', 'min:1', 'max:3'], 'call.wait_secs' => ['sometimes', 'integer', 'min:5', 'max:30'],
            'call.who' => ['sometimes', 'in:all,primary'], 'call.gap_secs' => ['sometimes', 'integer', 'min:0', 'max:300'],
        ];
    }

    /**
     * POST /safe-arrival/test-call {call?: draft options} -- agency admins only.
     * Rings the admin's OWN profile phone with the draft wording, so it can be heard before
     * it is saved. A test: the answer is spoken back and nothing is recorded on any child.
     */
    public function testCall(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        [, , $role] = $this->scope($request, $agencyId);
        abort_unless($role === 'admin', 403, 'Only an agency admin can place a test call.');
        $data = $request->validate(['call' => ['sometimes', 'array']] + $this->callRules());
        $u = $request->user();
        $phone = trim((string) ($u->phone ?? ''));
        if ($phone === '' || \App\Support\Telnyx::e164($phone) === '') {
            return response()->json(['message' => 'Add your mobile number to your profile first — the test rings your own phone.'], 422);
        }
        $who = \App\Http\Controllers\Api\VoiceController::testRecipient($agencyId, (int) $u->id, \App\Support\Telnyx::e164($phone));
        if ($who['voice_opted_out']) {
            return response()->json(['message' => 'Your number has opted out of phone calls, so it cannot be rung.'], 422);
        }
        if (! \App\Support\Telnyx::voiceConfig($agencyId)) {
            return response()->json(['message' => 'Voice calls are not set up for this agency yet (Settings → Carrier settings → Voice calls).'], 422);
        }
        $draft = \App\Support\SafeArrivalCall::config($agencyId, $data['call'] ?? []);
        $script = \App\Support\SafeArrivalCall::question($draft, \App\Support\SafeArrivalCall::testVars($agencyId));
        $ok = app(\App\Http\Controllers\Api\VoiceController::class)->callOne($agencyId, (int) $u->id, $phone, $script,
            \App\Support\SafeArrivalCall::CATEGORY, (int) $u->id, true, true, ['type' => 'safe_arrival_test', 'id' => (int) $u->id]);
        $row = DB::table('voice_calls')->where('ref_type', 'safe_arrival_test')->where('to_user_id', $u->id)->orderByDesc('id')->first(['id', 'status', 'error']);
        if ($row) {
            \Illuminate\Support\Facades\Cache::put('sa-test-cfg-' . $row->id, $data['call'] ?? [], now()->addMinutes(15));
        }
        if (! $ok) {
            return response()->json(['message' => 'The call could not be placed' . ($row && $row->error ? ': ' . $row->error : '.')], 422);
        }

        return response()->json(['ok' => true, 'message' => 'Calling ' . $phone . ' now. Answer and press a key to hear the reply.']);
    }

    /** [centreIds|null, roomIds|null, 'admin'|'director'|'educator'] */
    private function scope(Request $request, int $agencyId): array
    {
        $uid = (int) $request->user()->id;
        $ra = DB::table('role_assignments')->where('user_id', $uid)->where('active', 1)->get(['role', 'agency_id', 'centre_id']);
        if ($ra->contains(fn ($r) => $r->role === 'platform_admin'
            || ($r->role === 'agency_admin' && (int) $r->agency_id === $agencyId))) {
            /* The agency's OPEN centres, not "everything" (null): a closed provider's
               children and centre name were on the admin's Safe Arrival (2026-10-09). */
            return [\App\Support\Live::centreIds($agencyId) ?: [0], null, 'admin'];
        }
        $centres = \App\Support\Live::onlyOpenCentres($ra->filter(fn ($r) => $r->role === 'centre_director' && (int) $r->agency_id === $agencyId && $r->centre_id)
            ->pluck('centre_id')->map(fn ($x) => (int) $x)->unique()->values()->all());
        if ($centres) {
            return [$centres, null, 'director'];
        }
        if ($ra->contains(fn ($r) => $r->role === 'educator' && (int) $r->agency_id === $agencyId)) {
            $rooms = DB::table('educator_rooms')->where('user_id', $uid)->pluck('room_id')->map(fn ($x) => (int) $x)->all();

            return [null, $rooms ?: [0], 'educator'];
        }
        abort(403, 'Safe Arrival is for centre staff.');
    }

    private function resolveAgencyId(Request $request): int
    {
        $activeId = (int) $request->header('X-Active-Agency-Id');
        if ($activeId && DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)
                ->where(function ($q) use ($activeId) { $q->where('role', 'platform_admin')->orWhere('agency_id', $activeId); })->exists()) {
            return $activeId;
        }
        if (DB::table('role_assignments')->where('user_id', $request->user()->id)->where('role', 'platform_admin')->where('active', true)->exists()) {
            abort(400, 'Select an agency first.');
        }
        $first = DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)->value('agency_id');
        abort_unless($first, 400);

        return (int) $first;
    }
}
