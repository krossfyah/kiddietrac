<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\HrAccess;
use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use App\Support\HrApprovers;
use App\Support\HrLeave;
use App\Support\HrTalent;
use App\Support\Notify;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * KIDDIETRAC HR PHASE 4, BATCH 6 (2026-11-27): TIME-OFF APPROVERS FOR NAMED PEOPLE, AND COVER WHILE AWAY.
 * The rules live in App\Support\HrApprovers (what a grant lets someone decide); this is the screen's side:
 *   - HR settings › Approvers (agency admins): every grant, add a 'people' grant, set cover for any director, end one.
 *   - My HR › "Cover while I'm away" (a centre director): name another manager for their own dates, end it early.
 * Every change is audited (hr.approver_*, hr.cover_*) and the new approver gets a bell. Nobody is their own approver.
 */
final class HrApproversController extends Controller
{
    use HrAccess;

    private function shape(object $g, array $names, array $types, string $today): array
    {
        $subjects = json_decode((string) ($g->subject_user_ids ?? 'null'), true) ?: [];
        $tids = $g->leave_type_ids !== null ? (json_decode((string) $g->leave_type_ids, true) ?: []) : null;
        $state = $g->revoked_at ? 'ended' : (($g->ends_on && substr((string) $g->ends_on, 0, 10) < $today) ? 'ended'
            : (($g->starts_on && substr((string) $g->starts_on, 0, 10) > $today) ? 'upcoming' : 'active'));

        return ['id' => (int) $g->id, 'kind' => $g->kind, 'state' => $state,
            'approver' => ['id' => (int) $g->approver_user_id, 'name' => $names[(int) $g->approver_user_id] ?? 'Former staff'],
            'covering_for' => $g->covering_for_user_id ? ['id' => (int) $g->covering_for_user_id, 'name' => $names[(int) $g->covering_for_user_id] ?? 'Former staff'] : null,
            'people' => array_map(fn ($u) => ['id' => (int) $u, 'name' => $names[(int) $u] ?? 'Former staff'], $subjects),
            'types' => $tids === null ? null : array_values(array_filter(array_map(fn ($t) => $types[(int) $t] ?? null, $tids))),
            'starts_on' => $g->starts_on ? substr((string) $g->starts_on, 0, 10) : null, 'ends_on' => $g->ends_on ? substr((string) $g->ends_on, 0, 10) : null,
            'note' => $g->note, 'created_at' => $g->created_at, 'ended_at' => $g->revoked_at];
    }

    private function list(int $aid, \Illuminate\Support\Collection $rows): array
    {
        $ids = [];
        foreach ($rows as $g) {
            $ids[] = (int) $g->approver_user_id;
            if ($g->covering_for_user_id) $ids[] = (int) $g->covering_for_user_id;
            foreach (json_decode((string) ($g->subject_user_ids ?? 'null'), true) ?: [] as $u) $ids[] = (int) $u;
        }
        $names = $this->hrNames(array_values(array_unique($ids)));
        $types = DB::table('hr_leave_types')->where('agency_id', $aid)->pluck('name', 'id')->all();
        $today = AgencyTime::today($aid);

        return $rows->map(fn ($g) => $this->shape($g, $names, $types, $today))->values()->all();
    }

    /** Grants worth showing: in force or coming up, and those ended in the last 60 days. */
    private function recent(int $aid)
    {
        $since = Carbon::parse(AgencyTime::today($aid))->subDays(60)->toDateString();

        return DB::table('hr_approver_grants')->where('agency_id', $aid)
            ->where(fn ($w) => $w->where(fn ($a) => $a->whereNull('revoked_at')->where(fn ($e) => $e->whereNull('ends_on')->orWhere('ends_on', '>=', $since)))
                ->orWhere('revoked_at', '>=', $since . ' 00:00:00'))
            ->orderByRaw('revoked_at IS NOT NULL')->orderByDesc('id');
    }

    private function managers(int $aid): array
    {
        $ids = HrTalent::managerIds($aid);
        $dir = DB::table('role_assignments')->whereIn('user_id', $ids ?: [0])->where('active', 1)->where('role', 'centre_director')->pluck('user_id')->map(fn ($v) => (int) $v)->all();

        return DB::table('users')->whereIn('id', $ids ?: [0])->orderBy('first_name')->get(['id', 'first_name', 'last_name'])
            ->map(fn ($u) => ['id' => (int) $u->id, 'name' => $this->hrName($u), 'director' => in_array((int) $u->id, $dir, true)])->values()->all();
    }

    /** GET hr/approvers — HR settings › Approvers (agency admins). */
    public function index(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedAdmin($request);
        $staff = HrLeave::staffIds($aid);

        return response()->json(['grants' => $this->list($aid, $this->recent($aid)->get()), 'managers' => $this->managers($aid),
            'people' => DB::table('users')->whereIn('id', $staff ?: [0])->orderBy('first_name')->get(['id', 'first_name', 'last_name'])
                ->map(fn ($u) => ['id' => (int) $u->id, 'name' => $this->hrName($u)])->values(),
            'types' => DB::table('hr_leave_types')->where('agency_id', $aid)->whereNull('archived_at')->orderBy('sort')->orderBy('name')->get(['id', 'name'])
                ->map(fn ($t) => ['id' => (int) $t->id, 'name' => $t->name])->values(),
            'today' => AgencyTime::today($aid), 'max_cover_days' => HrApprovers::MAX_COVER_DAYS]);
    }

    /** POST hr/approvers {kind: people|cover, approver_user_id, subject_user_ids[] | covering_for_user_id, starts_on, ends_on, leave_type_ids[], note} (admins). */
    public function create(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedAdmin($request);
        $d = $request->validate(['kind' => 'required|in:people,cover', 'approver_user_id' => 'required|integer',
            'subject_user_ids' => 'nullable|array|max:200', 'subject_user_ids.*' => 'integer', 'leave_type_ids' => 'nullable|array|max:50', 'leave_type_ids.*' => 'integer',
            'covering_for_user_id' => 'nullable|integer', 'starts_on' => 'nullable|date_format:Y-m-d', 'ends_on' => 'nullable|date_format:Y-m-d', 'note' => 'nullable|string|max:300']);
        if ($d['kind'] === 'cover') {
            return $this->saveCover($request, $aid, (int) ($d['covering_for_user_id'] ?? 0), $d);
        }
        $approver = (int) $d['approver_user_id'];
        abort_unless(in_array($approver, HrTalent::managerIds($aid), true), 422, 'Choose a director or admin of your agency as the approver.');
        $staff = HrLeave::staffIds($aid);
        $subjects = array_values(array_unique(array_map('intval', $d['subject_user_ids'] ?? [])));
        abort_unless($subjects, 422, 'Choose whose time off they approve.');
        foreach ($subjects as $s) abort_unless(in_array($s, $staff, true), 404, 'That person was not found.');
        abort_if(in_array($approver, $subjects, true), 422, 'Nobody approves their own time off. Take them off the list of people.');
        $types = null;
        if (! empty($d['leave_type_ids'])) {
            $types = array_values(array_unique(array_map('intval', $d['leave_type_ids'])));
            abort_unless(DB::table('hr_leave_types')->where('agency_id', $aid)->whereIn('id', $types)->count() === count($types), 422, 'Choose leave types of your agency.');
        }
        $id = (int) DB::table('hr_approver_grants')->insertGetId(['agency_id' => $aid, 'kind' => 'people', 'approver_user_id' => $approver,
            'subject_user_ids' => json_encode($subjects), 'leave_type_ids' => $types === null ? null : json_encode($types), 'note' => isset($d['note']) ? trim($d['note']) : null,
            'created_by_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->hrNote($aid, 'hr.approver_granted', 'user', $approver, ['grant_id' => $id, 'people' => $subjects, 'leave_type_ids' => $types]);
        $this->bell($aid, $approver, 'You now approve time off for ' . count($subjects) . ' ' . (count($subjects) === 1 ? 'person' : 'people'),
            'Their requests show under Time off › Requests, and you get the approval emails.');

        return response()->json(['id' => $id], 201);
    }

    /** One cover row after every check; used by admins (any director) and by a director for themselves. */
    private function saveCover(Request $request, int $aid, int $absent, array $d): JsonResponse
    {
        $approver = (int) $d['approver_user_id'];
        $managers = HrTalent::managerIds($aid);
        abort_unless($absent && in_array($absent, $managers, true) && HrApprovers::directs($absent, $aid), 422, 'Cover is for a centre director who will be away.');
        abort_unless(in_array($approver, $managers, true), 422, 'Choose a director or admin of your agency to cover.');
        abort_if($approver === $absent, 422, 'Choose someone else to cover for you.');
        $today = AgencyTime::today($aid);
        $from = $d['starts_on'] ?? null;
        $to = $d['ends_on'] ?? null;
        abort_unless($from && $to, 422, 'Choose the first and last day of the cover.');
        abort_if($to < $from, 422, 'The last day is before the first day.');
        abort_if($to < $today, 422, 'Those dates have already passed.');
        abort_if(Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1 > HrApprovers::MAX_COVER_DAYS, 422, 'Cover can be up to ' . HrApprovers::MAX_COVER_DAYS . ' days at a time.');
        $clash = DB::table('hr_approver_grants')->where('agency_id', $aid)->where('kind', 'cover')->where('covering_for_user_id', $absent)->whereNull('revoked_at')
            ->where('starts_on', '<=', $to)->where('ends_on', '>=', $from)->exists();
        abort_if($clash, 422, 'There is already cover for some of those days. End it first.');
        $id = (int) DB::table('hr_approver_grants')->insertGetId(['agency_id' => $aid, 'kind' => 'cover', 'approver_user_id' => $approver,
            'covering_for_user_id' => $absent, 'starts_on' => $from, 'ends_on' => $to, 'note' => isset($d['note']) ? trim((string) $d['note']) : null,
            'created_by_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->hrNote($aid, 'hr.cover_set', 'user', $absent, ['grant_id' => $id, 'cover_user_id' => $approver, 'from' => $from, 'to' => $to]);
        $who = DB::table('users')->where('id', $absent)->value('first_name') ?: 'A director';
        $this->bell($aid, $approver, 'You are covering ' . $who . '\'s approvals', 'From ' . Carbon::parse($from)->format('M j') . ' to ' . Carbon::parse($to)->format('M j')
            . ': their time-off requests show under Time off › Requests and you get the emails. It ends by itself.');

        return response()->json(['id' => $id], 201);
    }

    /** POST hr/approvers/{id}/end — an admin ends any grant; a director ends a cover for themselves (or one they hold). */
    public function end(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedManager($request);
        $g = DB::table('hr_approver_grants')->where('id', $id)->where('agency_id', $aid)->whereNull('revoked_at')->first();
        abort_unless($g, 404, 'That was not found or has already ended.');
        $me = (int) $request->user()->id;
        abort_unless($this->hrIsAdmin($request) || ($g->kind === 'cover' && ($me === (int) $g->covering_for_user_id || $me === (int) $g->approver_user_id)),
            403, 'Only an agency admin can change this.');
        DB::table('hr_approver_grants')->where('id', $id)->update(['revoked_at' => now(), 'revoked_by_id' => $me, 'updated_at' => now()]);
        $this->hrNote($aid, $g->kind === 'cover' ? 'hr.cover_ended' : 'hr.approver_grant_ended', 'user', (int) ($g->covering_for_user_id ?: $g->approver_user_id),
            ['grant_id' => $id, 'approver_user_id' => (int) $g->approver_user_id]);
        if ((int) $g->approver_user_id !== $me) {
            $this->bell($aid, (int) $g->approver_user_id, $g->kind === 'cover' ? 'Your cover has ended' : 'An approval permission was removed',
                $g->kind === 'cover' ? 'You no longer get those time-off approvals.' : 'You no longer approve time off for those people.');
        }

        return response()->json(['ok' => true]);
    }

    /** GET hr/me/cover — a director's own cover (as the one away, and any they hold for others). */
    public function mine(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedManager($request);
        $me = (int) $request->user()->id;
        $rows = $this->recent($aid)->where(fn ($w) => $w->where('covering_for_user_id', $me)->orWhere('approver_user_id', $me))->get();

        return response()->json(['grants' => $this->list($aid, $rows), 'can_set' => (bool) HrApprovers::directs($me, $aid),
            'managers' => array_values(array_filter($this->managers($aid), fn ($m) => $m['id'] !== $me)), 'today' => AgencyTime::today($aid),
            'me' => $me, 'max_cover_days' => HrApprovers::MAX_COVER_DAYS]);
    }

    /** POST hr/me/cover {approver_user_id, starts_on, ends_on, note} — a director names cover for their own absence. */
    public function setMine(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedManager($request);
        $d = $request->validate(['approver_user_id' => 'required|integer', 'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'required|date_format:Y-m-d', 'note' => 'nullable|string|max:300']);

        return $this->saveCover($request, $aid, (int) $request->user()->id, $d);
    }

    private function bell(int $aid, int $uid, string $title, string $body): void
    {
        try {
            Notify::write(['user_id' => $uid, 'agency_id' => $aid, 'type' => 'hr', 'title' => $title, 'body' => $body,
                'data' => json_encode(['link' => '#time-off', 'kind' => 'own']), 'created_at' => now()]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
