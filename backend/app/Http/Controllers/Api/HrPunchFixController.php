<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\HrAccess;
use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use App\Support\CentreScope;
use App\Support\HrLeave;
use App\Support\HrOpenShifts;
use App\Support\HrTime;
use App\Support\Live;
use App\Support\Notify;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KiddieTrac HR Phase 4, batch 8 (2026-11-27): "Fix my clock-in". A staff member asks for one of their own clock-ins to be
 * corrected (wrong time, forgot to clock out) or for a missing one to be added, with a reason. A director of the centre
 * or an agency admin approves or declines it - never the person themselves. Approving writes the clock-in through the
 * same rules as the Timesheets screen's own correction (no overlap, under 24 hours, out after in) and stamps it as
 * corrected; both asking and approving are refused while the day is inside a timesheet that was approved and locked
 * (HrTime::editRefusal). Times are typed as the centre's wall clock and stored in UTC, like every clock-in.
 */
class HrPunchFixController extends Controller
{
    use HrAccess;

    public const COLS = ['id', 'agency_id', 'centre_id', 'user_id', 'punch_id', 'kind', 'was_in', 'was_out', 'want_in', 'want_out', 'reason', 'status',
        'decided_by_id', 'decided_at', 'decision_comment', 'applied_punch_id', 'created_at'];

    private function fixAgency(Request $request): int
    {
        $aid = $this->hrAgency($request);
        abort_unless(Schema::hasTable('hr_punch_corrections'), 503, 'Clock-in fixes are being set up. Please try again in a minute.');

        return $aid;
    }

    private function local(?string $utc, string $tz): ?array
    {
        if (! $utc) return null;
        $c = Carbon::parse($utc, 'UTC')->setTimezone($tz);

        return ['date' => $c->toDateString(), 'time' => $c->format('H:i'), 'label' => $c->format('D, M j, g:i A')];
    }

    private function shapeAll(int $aid, $rows): array
    {
        $rows = collect($rows);
        $names = $this->hrNames($rows->pluck('user_id')->merge($rows->pluck('decided_by_id'))->filter()->map(fn ($v) => (int) $v)->unique()->all());
        $centres = DB::table('centres')->where('agency_id', $aid)->pluck('name', 'id')->all();

        return $rows->map(function ($r) use ($names, $centres) {
            $tz = AgencyTime::tzForCentre((int) $r->centre_id);

            return ['id' => (int) $r->id, 'user_id' => (int) $r->user_id, 'person' => $names[(int) $r->user_id] ?? '', 'centre_id' => (int) $r->centre_id,
                'centre' => $centres[(int) $r->centre_id] ?? '', 'kind' => $r->kind, 'punch_id' => $r->punch_id ? (int) $r->punch_id : null,
                'was_in' => $this->local($r->was_in, $tz), 'was_out' => $this->local($r->was_out, $tz), 'want_in' => $this->local($r->want_in, $tz),
                'want_out' => $this->local($r->want_out, $tz), 'reason' => $r->reason, 'status' => $r->status, 'decided_by' => $names[(int) $r->decided_by_id] ?? '',
                'decided_at' => $r->decided_at ? (string) $r->decided_at : null, 'decision_comment' => $r->decision_comment, 'created_at' => (string) $r->created_at,
                'locked' => HrTime::editRefusal((int) $r->centre_id, array_filter([$r->was_in, $r->want_in])) !== null && $r->status === 'pending'];
        })->values()->all();
    }

    /** The staff member's centres in this agency (open ones), for a missing clock-in. */
    private function myCentres(int $aid, int $me): array
    {
        $open = Live::centreIds($aid);
        $ids = DB::table('role_assignments')->where('user_id', $me)->where('active', 1)->whereIn('role', HrLeave::STAFF_ROLES)
            ->whereIn('centre_id', $open ?: [0])->pluck('centre_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        $p = (int) DB::table('hr_employee_files')->where('agency_id', $aid)->where('user_id', $me)->value('primary_centre_id');
        if ($p && in_array($p, $open, true) && ! in_array($p, $ids, true)) $ids[] = $p;

        return $ids;
    }

    /** GET /hr/me/punch-fixes — my clock-ins of the last 31 days and my requests. */
    public function mine(Request $request): JsonResponse
    {
        $aid = $this->fixAgency($request);
        $me = (int) $request->user()->id;
        if (! in_array($me, HrLeave::staffIds($aid), true)) return response()->json(['is_staff' => false]);
        $centreIds = DB::table('centres')->where('agency_id', $aid)->pluck('id')->all();
        $since = Carbon::now('UTC')->subDays(31)->toDateTimeString();
        $punches = DB::table('time_punches')->where('user_id', $me)->whereIn('centre_id', $centreIds ?: [0])->where('punched_in_at', '>=', $since)
            ->orderByDesc('punched_in_at')->limit(80)->get(['id', 'centre_id', 'punched_in_at', 'punched_out_at', 'source']);
        $names = DB::table('centres')->whereIn('id', $centreIds ?: [0])->pluck('name', 'id')->all();
        $pending = DB::table('hr_punch_corrections')->where('user_id', $me)->where('status', 'pending')->pluck('punch_id')->filter()->map(fn ($v) => (int) $v)->all();
        $mine = $this->myCentres($aid, $me);

        return response()->json(['is_staff' => true, 'today' => AgencyTime::today($aid),
            'punches' => $punches->map(function ($p) use ($names, $pending) {
                $tz = AgencyTime::tzForCentre((int) $p->centre_id);
                $min = $p->punched_out_at ? HrTime::punchMinutes($p->punched_in_at, $p->punched_out_at) : null;

                return ['id' => (int) $p->id, 'centre_id' => (int) $p->centre_id, 'centre' => $names[(int) $p->centre_id] ?? '', 'in' => $this->local($p->punched_in_at, $tz),
                    'out' => $this->local($p->punched_out_at, $tz), 'minutes' => $min, 'auto' => $p->source === 'auto', 'pending' => in_array((int) $p->id, $pending, true),
                    'locked' => HrTime::editRefusal((int) $p->centre_id, [$p->punched_in_at]) !== null];
            })->all(),
            'requests' => $this->shapeAll($aid, DB::table('hr_punch_corrections')->where('agency_id', $aid)->where('user_id', $me)->orderByDesc('id')->limit(40)->get(self::COLS)),
            'centres' => DB::table('centres')->whereIn('id', $mine ?: [0])->orderBy('name')->get(['id', 'name'])]);
    }

    /** "YYYY-MM-DD" + "HH:MM" at a centre -> UTC "Y-m-d H:i:s". */
    private function utc(int $centreId, string $date, string $time): string
    {
        return Carbon::parse($date . ' ' . $time, AgencyTime::tzForCentre($centreId))->utc()->toDateTimeString();
    }

    /** Does [in, out) overlap another of this person's clock-ins? (same rule as the Timesheets screen) */
    private function clash(int $user, string $in, ?string $out, int $exceptId = 0): bool
    {
        $end = $out ?: Carbon::now('UTC')->toDateTimeString();

        return DB::table('time_punches')->where('user_id', $user)->when($exceptId, fn ($q) => $q->where('id', '<>', $exceptId))
            ->where('punched_in_at', '<', $end)->where(fn ($x) => $x->whereNull('punched_out_at')->orWhere('punched_out_at', '>', $in))->exists();
    }

    /** The checks a fix must pass, at asking and again at approving. Null = fine. */
    private function refusal(int $centreId, int $userId, ?object $punch, string $in, ?string $out): ?string
    {
        if ($no = HrTime::editRefusal($centreId, array_filter([$punch->punched_in_at ?? null, $in]))) return $no;
        $ti = Carbon::parse($in, 'UTC')->getTimestamp();
        $to = $out ? Carbon::parse($out, 'UTC')->getTimestamp() : null;
        if ($to !== null && $to <= $ti) return 'The clock-out must be after the clock-in.';
        if ($to !== null && $to - $ti > 86400) return 'That is longer than 24 hours. Check the date and times.';
        if ($ti > time() || ($to !== null && $to > time() + 60)) return 'A clock-in fix cannot be in the future.';
        if ($this->clash($userId, $in, $out, (int) ($punch->id ?? 0))) return 'Those times overlap another clock-in of yours.';

        return null;
    }

    /**
     * POST /hr/punch-fixes {punch_id?, centre_id?, date, in, out?, out_date?, reason}
     * punch_id = fix that clock-in (it must be yours); without it = a clock-in that is missing (centre_id = where).
     */
    public function store(Request $request): JsonResponse
    {
        $aid = $this->fixAgency($request);
        $me = (int) $request->user()->id;
        abort_unless(in_array($me, HrLeave::staffIds($aid), true), 403, 'Only the agency\'s staff can ask for a clock-in fix.');
        $d = $request->validate(['punch_id' => 'nullable|integer', 'centre_id' => 'nullable|integer', 'date' => 'required|date_format:Y-m-d',
            'in' => 'required|date_format:H:i', 'out' => 'nullable|date_format:H:i', 'out_date' => 'nullable|date_format:Y-m-d',
            'reason' => 'required|string|min:3|max:500'], ['reason.required' => 'Say what went wrong, so your director can check it.',
            'in.required' => 'Enter the time you started.']);
        $punch = null;
        if (! empty($d['punch_id'])) {
            $punch = DB::table('time_punches')->where('id', $d['punch_id'])->where('user_id', $me)->first();
            abort_unless($punch && (int) DB::table('centres')->where('id', $punch->centre_id)->value('agency_id') === $aid, 404, 'That clock-in was not found.');
            $cid = (int) $punch->centre_id;
            abort_if(DB::table('hr_punch_corrections')->where('punch_id', $punch->id)->where('status', 'pending')->exists(), 422, 'You already asked for this clock-in to be fixed. Withdraw that request to change it.');
        } else {
            $cid = (int) ($d['centre_id'] ?? 0);
            abort_unless($cid && in_array($cid, $this->myCentres($aid, $me), true), 422, 'Choose the centre you worked at.');
        }
        $in = $this->utc($cid, $d['date'], $d['in']);
        $out = null;
        if (! empty($d['out'])) {
            $od = $d['out_date'] ?? $d['date'];
            if (empty($d['out_date']) && $d['out'] <= $d['in']) $od = date('Y-m-d', strtotime($d['date'] . ' +1 day'));   // past midnight
            $out = $this->utc($cid, $od, $d['out']);
        }
        abort_if(! $punch && ! $out, 422, 'For a missing clock-in, enter when you finished too.');
        if ($no = $this->refusal($cid, $me, $punch, $in, $out)) return response()->json(['message' => $no], 422);
        if ($punch && $punch->punched_in_at === $in && (string) $punch->punched_out_at === (string) $out) return response()->json(['message' => 'Those are the times already recorded.'], 422);
        $id = (int) DB::table('hr_punch_corrections')->insertGetId(['agency_id' => $aid, 'centre_id' => $cid, 'user_id' => $me, 'punch_id' => $punch->id ?? null,
            'kind' => $punch ? 'fix' : 'missing', 'was_in' => $punch->punched_in_at ?? null, 'was_out' => $punch->punched_out_at ?? null, 'want_in' => $in,
            'want_out' => $out, 'reason' => trim($d['reason']), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        $this->hrNote($aid, 'hr.punch_fix_requested', 'hr_punch_correction', $id, ['punch_id' => $punch->id ?? null, 'want_in' => $in, 'want_out' => $out]);
        $u = DB::table('users')->where('id', $me)->first(['first_name', 'preferred_name']);
        $first = trim((string) (($u->preferred_name ?? '') ?: ($u->first_name ?? ''))) ?: 'A team member';
        $tz = AgencyTime::tzForCentre($cid);
        HrOpenShifts::bellManagers($aid, $cid, [$me], $first . ' asked for a clock-in fix', Carbon::parse($in, 'UTC')->setTimezone($tz)->format('D, M j, g:i A')
            . ($out ? ' – ' . Carbon::parse($out, 'UTC')->setTimezone($tz)->format('g:i A') : '') . ': ' . trim($d['reason']), '#timesheets?tab=punchfix');

        return response()->json(['id' => $id], 201);
    }

    /** POST /hr/punch-fixes/{id}/withdraw — the person takes back a request still waiting. */
    public function withdraw(Request $request, int $id): JsonResponse
    {
        $aid = $this->fixAgency($request);
        $n = DB::table('hr_punch_corrections')->where('id', $id)->where('agency_id', $aid)->where('user_id', $request->user()->id)->where('status', 'pending')
            ->update(['status' => 'withdrawn', 'updated_at' => now()]);
        abort_unless($n, 404, 'That request was not found, or it has already been decided.');
        $this->hrNote($aid, 'hr.punch_fix_withdrawn', 'hr_punch_correction', $id);

        return response()->json(['ok' => true]);
    }

    private function managedQuery(Request $request, int $aid)
    {
        $this->hrNeedManager($request);
        $q = DB::table('hr_punch_corrections')->where('agency_id', $aid);
        if (! $this->hrIsAdmin($request)) $q->whereIn('centre_id', CentreScope::ids($request, $aid) ?: [0]);

        return $q;
    }

    /** GET /hr/punch-fixes?status=pending|decided — the queue (director: own centres, admin: agency). */
    public function index(Request $request): JsonResponse
    {
        $aid = $this->fixAgency($request);
        $st = $request->query('status', 'pending') === 'decided' ? 'decided' : 'pending';
        $rows = $this->managedQuery($request, $aid)->when($st === 'pending', fn ($q) => $q->where('status', 'pending'), fn ($q) => $q->whereIn('status', ['approved', 'declined', 'withdrawn']))
            ->orderBy($st === 'pending' ? 'created_at' : 'decided_at', $st === 'pending' ? 'asc' : 'desc')->limit(200)->get(self::COLS);

        return response()->json(['status' => $st, 'rows' => $this->shapeAll($aid, $rows), 'me' => (int) $request->user()->id,
            'pending' => $this->managedQuery($request, $aid)->where('status', 'pending')->count()]);
    }

    /** POST /hr/punch-fixes/{id}/decide {decision: approve|decline, comment} — never your own; refused once locked. */
    public function decide(Request $request, int $id): JsonResponse
    {
        $aid = $this->fixAgency($request);
        $r = $this->managedQuery($request, $aid)->where('id', $id)->first(self::COLS);
        abort_unless($r, 404, 'That request was not found.');
        $me = (int) $request->user()->id;
        abort_if((int) $r->user_id === $me, 403, 'Someone else approves a fix to your own clock-ins.');
        abort_unless($r->status === 'pending', 422, 'This request has already been decided.');
        $d = $request->validate(['decision' => 'required|in:approve,decline', 'comment' => 'required_if:decision,decline|nullable|string|max:500'],
            ['comment.required_if' => 'Say why the fix is declined.']);
        $applied = null;
        if ($d['decision'] === 'approve') {
            $err = null;
            DB::transaction(function () use ($r, $me, $d, &$applied, &$err) {
                $cur = DB::table('hr_punch_corrections')->where('id', $r->id)->lockForUpdate()->first(['status']);
                if (! $cur || $cur->status !== 'pending') { $err = 'This request has already been decided.'; return; }
                $punch = $r->punch_id ? DB::table('time_punches')->where('id', $r->punch_id)->where('user_id', $r->user_id)->lockForUpdate()->first() : null;
                if ($r->punch_id && ! $punch) { $err = 'That clock-in no longer exists. Decline this request and add the hours on the Timesheets screen.'; return; }
                if ($no = $this->refusal((int) $r->centre_id, (int) $r->user_id, $punch, (string) $r->want_in, $r->want_out ? (string) $r->want_out : null)) { $err = $no; return; }
                $actor = DB::table('users')->where('id', $me)->first(['first_name', 'last_name']);
                $by = trim(($actor->first_name ?? '') . ' ' . ($actor->last_name ?? '')) ?: 'a director';
                $stamp = 'Fixed on request #' . $r->id . ' (approved by ' . $by . '): ' . $r->reason;
                if ($punch) {
                    $notes = trim((string) ($punch->notes ?? ''));
                    DB::table('time_punches')->where('id', $punch->id)->update(['punched_in_at' => $r->want_in, 'punched_out_at' => $r->want_out,
                        'notes' => mb_substr($notes === '' ? $stamp : $notes . ' | ' . $stamp, 0, 300), 'corrected_by_user_id' => $me, 'corrected_at' => now(),
                        'correction_source' => 'hr_request', 'correction_note' => mb_substr($stamp, 0, 300)]);
                    $applied = (int) $punch->id;
                } else {
                    $applied = (int) DB::table('time_punches')->insertGetId(['user_id' => $r->user_id, 'centre_id' => $r->centre_id, 'punched_in_at' => $r->want_in,
                        'punched_out_at' => $r->want_out, 'source' => 'web', 'notes' => mb_substr('Added on request #' . $r->id . ' (approved by ' . $by . '): ' . $r->reason, 0, 300),
                        'corrected_by_user_id' => $me, 'corrected_at' => now(), 'correction_source' => 'hr_request', 'created_at' => now()]);
                }
                DB::table('hr_punch_corrections')->where('id', $r->id)->update(['status' => 'approved', 'decided_by_id' => $me, 'decided_at' => now(),
                    'decision_comment' => $d['comment'] ?? null, 'applied_punch_id' => $applied, 'updated_at' => now()]);
            });
            if ($err) return response()->json(['message' => $err], 422);
            $st = 'approved';
        } else {
            DB::table('hr_punch_corrections')->where('id', $id)->where('status', 'pending')->update(['status' => 'declined', 'decided_by_id' => $me, 'decided_at' => now(),
                'decision_comment' => $d['comment'], 'updated_at' => now()]);
            $st = 'declined';
        }
        $this->hrNote($aid, 'hr.punch_fix_' . $st, 'hr_punch_correction', $id, ['user_id' => (int) $r->user_id, 'punch_id' => $applied ?: ($r->punch_id ? (int) $r->punch_id : null),
            'was_in' => $r->was_in, 'was_out' => $r->was_out, 'want_in' => $r->want_in, 'want_out' => $r->want_out]);
        try {
            $tz = AgencyTime::tzForCentre((int) $r->centre_id);
            Notify::write(['user_id' => (int) $r->user_id, 'agency_id' => $aid, 'type' => 'hr',
                'title' => ($st === 'approved' ? 'Clock-in fixed: ' : 'Clock-in fix declined: ') . Carbon::parse($r->want_in, 'UTC')->setTimezone($tz)->format('D, M j'),
                'body' => $st === 'approved' ? 'Your hours now show the corrected times.' : (string) ($d['comment'] ?? ''),
                'data' => json_encode(['link' => '#my-hr', 'kind' => 'own']), 'created_at' => now()]);
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(['ok' => true, 'status' => $st, 'punch_id' => $applied]);
    }
}
