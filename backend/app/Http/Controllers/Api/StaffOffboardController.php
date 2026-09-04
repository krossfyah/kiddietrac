<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Off-boarding one member of staff.
 *
 * `AdminController::destroyUser()` already closes an account properly — roles
 * deactivated, rooms released, tokens revoked, sign-in blocked, audited. What it does
 * NOT do is everything that makes a departure orderly: hand the rooms to somebody,
 * close the shift they never clocked out of, notice the tasks still assigned to them.
 * Measured 2026-08-26: 2 of 7 already-deactivated staff still carry an OPEN time punch —
 * clocked in, never clocked out, account closed. That quietly distorts hours and ratio
 * history for as long as it sits there.
 *
 * So this is the staff twin of CentreOffboardController: a read-only plan, then an
 * executor that drives the REAL endpoint for the closure itself rather than
 * reimplementing it. Anthony, 2026-08-26.
 */
final class StaffOffboardController extends Controller
{
    use AuthorizesTenantAccess;

    /** An open punch older than this is a forgotten shift, not somebody still working. */
    private const STALE_PUNCH_HOURS = 20;

    /** Who this flow is for. A guardian is deliberately absent — see context(). */
    private const STAFF_ROLES = ['educator', 'centre_director', 'agency_admin',
                                 'home_visitor', 'auditor', 'sales_rep', 'platform_admin'];

    // ─────────────────────────────────────────────────────────────────────────

    /** What closing this person would involve. Changes NOTHING. */
    public function plan(Request $request, int $userId): JsonResponse
    {
        $ctx = $this->context($request, $userId);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }
        [$user, $agencyId] = $ctx;

        $rooms = $this->roomsFor($userId);
        $openPunches = $this->openPunches($userId);
        $futureShifts = $this->futureShifts($userId);
        $openTasks = $this->openTasks($userId);
        $docs = $this->documentCount($userId);
        $unpaid = $this->unpaidHours($userId);
        $roles = DB::table('role_assignments')->where('user_id', $userId)->where('active', true)
            ->pluck('role')->unique()->values()->all();

        /* Who could take the rooms on: other active staff at the same centres. Offered
           rather than chosen, because a handover is a judgement about people. */
        $centreIds = DB::table('rooms')->whereIn('id', array_column($rooms, 'room_id'))
            ->pluck('centre_id')->unique()->values()->all();
        $candidates = [];
        if ($centreIds) {
            $candidates = DB::table('users as u')
                ->join('role_assignments as ra', 'ra.user_id', '=', 'u.id')
                ->whereIn('ra.centre_id', $centreIds)
                ->whereIn('ra.role', ['educator', 'centre_director'])
                ->where('ra.active', true)
                ->where('u.id', '!=', $userId)
                ->whereNull('u.deleted_at')->where('u.status', 'active')
                ->distinct()
                ->get(['u.id', DB::raw("TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) as name"), 'ra.role'])
                ->unique('id')->values()->all();
        }

        return response()->json([
            'user' => [
                'id' => (int) $user->id,
                'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
                'email' => $user->email,
                'status' => $user->status,
                'roles' => $roles,
            ],
            'rooms' => $rooms,
            'open_punches' => $openPunches,
            'future_shifts' => $futureShifts,
            'open_tasks' => $openTasks,
            'documents' => $docs,
            'unpaid' => $unpaid,
            'candidates' => $candidates,
            'summary' => [
                'rooms' => count($rooms),
                'rooms_they_alone_cover' => count(array_filter($rooms, fn ($r) => (int) $r['other_educators'] === 0)),
                'open_punches' => count($openPunches),
                'future_shifts' => count($futureShifts),
                'open_tasks' => count($openTasks),
                'documents' => $docs,
                'unpaid_hours' => $unpaid['hours'],
                'unpaid_since' => $unpaid['since'],
            ],
            'steps' => [
                'Decide who takes each room on.',
                'Close any shift they never clocked out of.',
                'Reassign or close their outstanding tasks and upcoming shifts.',
                'Settle their final pay for any hours not yet on a payslip.',
                'Close the account — sign-in ends and their rooms are released.',
                'Their record is retained; find them under Users → Show deactivated.',
            ],
        ]);
    }

    /**
     * Carry it out. Without `confirm` this previews and writes nothing.
     */
    public function execute(Request $request, int $userId): JsonResponse
    {
        $ctx = $this->context($request, $userId);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }
        [$user, $agencyId] = $ctx;

        $data = $request->validate([
            'last_day' => ['nullable', 'date'],
            'reassign_to' => ['nullable', 'integer'],
            'close_punches' => ['nullable', 'boolean'],
            'move_tasks' => ['nullable', 'boolean'],
            'cancel_shifts' => ['nullable', 'boolean'],
            'send_notice' => ['nullable', 'boolean'],
            'confirm' => ['nullable', 'boolean'],
        ]);

        $rooms = $this->roomsFor($userId);
        $openPunches = $this->openPunches($userId);
        // The AGENCY's date. now()->toDateString() is UTC, which from 8pm Toronto is
        // already tomorrow - so an evening off-boarding was dated a day late.
        $lastDay = $data['last_day'] ?? \App\Support\AgencyTime::today($agencyId);
        $reassignTo = $data['reassign_to'] ?? null;

        /* A replacement must be somebody who can actually stand in that room. Checked
           here rather than trusted from the browser. */
        if ($reassignTo) {
            $ok = DB::table('role_assignments')->where('user_id', $reassignTo)
                ->where('agency_id', $agencyId)->where('active', true)
                ->whereIn('role', ['educator', 'centre_director'])->exists();
            if (! $ok) {
                return response()->json(['message' => 'That person cannot take over these rooms.'], 422);
            }
            if ((int) $reassignTo === $userId) {
                return response()->json(['message' => 'They cannot hand over to themselves.'], 422);
            }
        }

        if (empty($data['confirm'])) {
            return response()->json([
                'preview' => true,
                'will_reassign_rooms' => $reassignTo ? count($rooms) : 0,
                'will_close_punches' => ! empty($data['close_punches']) ? count($openPunches) : 0,
                'will_move_tasks' => ! empty($data['move_tasks']) ? count($this->openTasks($userId)) : 0,
                'will_cancel_shifts' => ! empty($data['cancel_shifts']) ? count($this->futureShifts($userId)) : 0,
                'will_send_notice' => ! empty($data['send_notice']),
                'unpaid_hours' => $this->unpaidHours($userId)['hours'],
                'rooms_left_uncovered' => $reassignTo ? 0 : count(array_filter($rooms, fn ($r) => (int) $r['other_educators'] === 0)),
                'last_day' => $lastDay,
                'message' => 'Nothing has changed yet.',
            ]);
        }

        $report = ['reassigned' => 0, 'punches_closed' => 0, 'tasks_moved' => 0,
                   'shifts_cancelled' => 0, 'notice_sent' => false, 'errors' => []];
        $unpaid = $this->unpaidHours($userId);

        // ── 1. Hand the rooms over, BEFORE the account closes ────────────────
        /* destroyUser() DELETES educator_rooms, so a handover afterwards would have
           nothing left to read. Order matters here. */
        if ($reassignTo && $rooms) {
            foreach ($rooms as $r) {
                try {
                    $exists = DB::table('educator_rooms')
                        ->where('user_id', $reassignTo)->where('room_id', $r['room_id'])->exists();
                    if (! $exists) {
                        DB::table('educator_rooms')->insert([
                            'user_id' => $reassignTo,
                            'room_id' => $r['room_id'],
                            'created_at' => now(),
                        ]);
                    }
                    $report['reassigned']++;
                } catch (\Throwable $e) {
                    $report['errors'][] = ['stage' => 'reassign', 'room_id' => $r['room_id'], 'message' => $e->getMessage()];
                }
            }
        }

        // ── 2. Close the shift they never clocked out of ─────────────────────
        if (! empty($data['close_punches']) && $openPunches) {
            foreach ($openPunches as $pn) {
                try {
                    /* Closed at the punch's own day-end, not "now" — stamping a
                       three-week-old shift with today's time would invent hours that
                       were never worked. */
                    $out = Carbon::parse($pn['punched_in_at'])->addHours(8);
                    if ($out->isFuture()) {
                        $out = now();
                    }
                    DB::table('time_punches')->where('id', $pn['id'])->update([
                        'punched_out_at' => $out,
                        'updated_at' => now(),
                    ]);
                    $report['punches_closed']++;
                } catch (\Throwable $e) {
                    $report['errors'][] = ['stage' => 'punch', 'id' => $pn['id'], 'message' => $e->getMessage()];
                }
            }
        }

        // ── 2b. Their outstanding work ───────────────────────────────────────
        /* Tasks and shifts move BEFORE the closure for the same reason the rooms do:
           afterwards there is a deactivated assignee and nobody looking. */
        if (! empty($data['move_tasks'])) {
            foreach ($this->openTasks($userId) as $t) {
                try {
                    DB::table('tasks')->where('id', $t['id'])->update([
                        'assigned_to' => $reassignTo,   // null = unassigned, back to the pool
                        'updated_at' => now(),
                    ]);
                    $report['tasks_moved']++;
                } catch (\Throwable $e) {
                    $report['errors'][] = ['stage' => 'task', 'id' => $t['id'], 'message' => $e->getMessage()];
                }
            }
        }

        if (! empty($data['cancel_shifts'])) {
            foreach ($this->futureShifts($userId) as $sh) {
                try {
                    if ($reassignTo) {
                        DB::table('shifts')->where('id', $sh['id'])->update(['user_id' => $reassignTo]);
                    } else {
                        /* Cancelled, not deleted — a rota that silently loses a shift
                           leaves a room short with no record of why. */
                        DB::table('shifts')->where('id', $sh['id'])->update(['status' => 'cancelled']);
                    }
                    $report['shifts_cancelled']++;
                } catch (\Throwable $e) {
                    $report['errors'][] = ['stage' => 'shift', 'id' => $sh['id'], 'message' => $e->getMessage()];
                }
            }
        }

        // ── 2c. The goodbye, sent while the account still exists ─────────────
        if (! empty($data['send_notice'])) {
            $report['notice_sent'] = $this->sendExitNotice($user, $agencyId, $lastDay, $unpaid);
        }

        // ── 3. Close the account, through the REAL endpoint ──────────────────
        /* Not reimplemented: destroyUser() revokes tokens, deactivates roles, releases
           rooms, blocks sign-in and audits. Driving it means those guards cannot drift
           away from this flow. */
        $actor = $request->user();
        try {
            $sub = Request::create('/staff-offboard', 'DELETE');
            $sub->setUserResolver(fn () => $actor);
            $sub->headers->set('X-Active-Agency-Id', (string) $agencyId);
            $resp = app(AdminController::class)->destroyUser($sub, $userId);
            $payload = json_decode($resp->getContent(), true) ?: [];
            if ($resp->getStatusCode() >= 300) {
                $report['errors'][] = ['stage' => 'close', 'message' => $payload['message'] ?? 'Could not close the account'];
            }
        } catch (\Throwable $e) {
            $report['errors'][] = ['stage' => 'close', 'message' => $e->getMessage()];
        }

        try {
            \App\Support\Audit::write([
                'user_id' => $actor->id,
                'agency_id' => \App\Support\AuditScope::resolve((int) $actor->id),
                'action' => 'staff.offboarded',
                'entity_type' => 'user',
                'entity_id' => $userId,
                'payload' => json_encode([
                    'last_day' => $lastDay,
                    'reassigned_to' => $reassignTo,
                    'rooms_reassigned' => $report['reassigned'],
                    'punches_closed' => $report['punches_closed'],
                    'tasks_moved' => $report['tasks_moved'],
                    'shifts_cancelled' => $report['shifts_cancelled'],
                    'notice_sent' => $report['notice_sent'],
                    'unpaid_hours_at_departure' => $unpaid['hours'],
                ]),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) { /* auditing must never break the departure */ }

        return response()->json([
            'ok' => empty($report['errors']),
            'report' => $report,
            'message' => 'Account closed. Their record is retained — find them under Users → Show deactivated.',
        ]);
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    /** Resolve + authorise, or return the refusal. */
    private function context(Request $request, int $userId)
    {
        $agencyId = (int) $request->header('X-Active-Agency-Id');
        if (! $agencyId) {
            return response()->json(['message' => 'No agency access'], 403);
        }
        $user = DB::table('users')->where('id', $userId)->first();
        if (! $user) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if ((int) $userId === (int) $request->user()->id) {
            return response()->json(['message' => 'You cannot off-board your own account.'], 422);
        }
        $inAgency = DB::table('role_assignments')->where('user_id', $userId)
            ->where('agency_id', $agencyId)->exists();
        if (! $inAgency) {
            return response()->json(['message' => 'That person is not in your agency.'], 403);
        }

        /* STAFF ONLY. A parent leaves as part of a FAMILY — de-enrolment handles their
           children, the outstanding balance and the goodbye notice. Off-boarding a
           guardian here would close their login while leaving the family enrolled, which
           is a broken half-state nobody asked for. Refused with a pointer, not a
           silent no-op. (Anthony, 2026-08-26) */
        $roles = DB::table('role_assignments')->where('user_id', $userId)
            ->where('agency_id', $agencyId)->where('active', true)
            ->pluck('role')->unique()->all();
        $staff = array_intersect($roles, self::STAFF_ROLES);
        if (! $staff) {
            return response()->json([
                'message' => 'This is a parent account. De-enrol the family instead — that closes their access and handles their children and balance.',
                'use_instead' => 'family_de_enrolment',
            ], 422);
        }

        return [$user, $agencyId];
    }

    private function roomsFor(int $userId): array
    {
        if (! Schema::hasTable('educator_rooms')) {
            return [];
        }
        return DB::table('educator_rooms as er')
            ->leftJoin('rooms as r', 'r.id', '=', 'er.room_id')
            ->leftJoin('centres as ce', 'ce.id', '=', 'r.centre_id')
            ->where('er.user_id', $userId)
            ->get(['er.room_id', 'r.name', 'ce.name as centre_name'])
            ->map(function ($r) use ($userId) {
                // Is anybody else covering this room? A room they alone hold is the one
                // that leaves children unstaffed on Monday.
                $others = DB::table('educator_rooms as e2')
                    ->join('users as u', 'u.id', '=', 'e2.user_id')
                    ->where('e2.room_id', $r->room_id)->where('e2.user_id', '!=', $userId)
                    ->whereNull('u.deleted_at')->where('u.status', 'active')
                    ->count();
                return [
                    'room_id' => (int) $r->room_id,
                    'name' => $r->name ?: ('Room #' . $r->room_id),
                    'centre_name' => $r->centre_name,
                    'other_educators' => $others,
                ];
            })->values()->all();
    }

    private function openPunches(int $userId): array
    {
        if (! Schema::hasTable('time_punches')) {
            return [];
        }
        return DB::table('time_punches')->where('user_id', $userId)->whereNull('punched_out_at')
            ->orderBy('punched_in_at')
            ->get(['id', 'punched_in_at'])
            ->map(fn ($p) => [
                'id' => (int) $p->id,
                'punched_in_at' => $p->punched_in_at,
                'stale' => Carbon::parse($p->punched_in_at)->diffInHours(now()) > self::STALE_PUNCH_HOURS,
            ])->values()->all();
    }

    private function futureShifts(int $userId): array
    {
        if (! Schema::hasTable('shifts')) {
            return [];
        }
        try {
            return DB::table('shifts')->where('user_id', $userId)
                ->where('starts_at', '>', now())->orderBy('starts_at')->limit(50)
                ->get(['id', 'starts_at', 'ends_at', 'room_id'])
                ->map(fn ($s) => ['id' => (int) $s->id, 'starts_at' => $s->starts_at, 'room_id' => $s->room_id])
                ->values()->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function openTasks(int $userId): array
    {
        if (! Schema::hasTable('tasks')) {
            return [];
        }
        try {
            /* The column is `assigned_to`. My first version read `assigned_to_id`,
               which does not exist — the try/catch swallowed it and every plan reported
               ZERO open tasks. A silent empty list is worse than an error here: it says
               "nothing outstanding" about somebody who is leaving. */
            $q = DB::table('tasks')->where('assigned_to', $userId);
            if (Schema::hasColumn('tasks', 'status')) {
                $q->whereNotIn('status', ['done', 'complete', 'completed', 'cancelled']);
            }
            return $q->orderByDesc('id')->limit(50)
                ->get(['id', 'title'])
                ->map(fn ($t) => ['id' => (int) $t->id, 'title' => $t->title])
                ->values()->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Hours worked that are not yet on a payslip.
     *
     * There is no pay_periods table — payroll is computed from time_punches, and
     * `payroll_documents` records what has already been issued. So "unpaid" is the
     * closed punches since the end of their most recent payroll document. Reported,
     * never actioned: this flow must not invent a pay run, only make sure nobody
     * leaves with hours nobody noticed.
     */
    private function unpaidHours(int $userId): array
    {
        try {
            if (! Schema::hasTable('time_punches')) {
                return ['hours' => 0.0, 'since' => null];
            }
            $since = null;
            if (Schema::hasTable('payroll_documents')) {
                $since = DB::table('payroll_documents')->where('user_id', $userId)
                    ->whereNotNull('period_end')->max('period_end');
            }
            $q = DB::table('time_punches')->where('user_id', $userId)
                ->whereNotNull('punched_out_at');
            if ($since) {
                $q->where('punched_in_at', '>', $since);
            }
            $mins = 0;
            foreach ($q->get(['punched_in_at', 'punched_out_at']) as $tp) {
                // abs(): Carbon 3 diffInMinutes is signed, which silently zeroed the
                // timesheet totals once before.
                $mins += abs(Carbon::parse($tp->punched_out_at)->diffInMinutes(Carbon::parse($tp->punched_in_at)));
            }
            return ['hours' => round($mins / 60, 1), 'since' => $since];
        } catch (\Throwable $e) {
            return ['hours' => 0.0, 'since' => null];
        }
    }

    /**
     * A short, human goodbye — sent BEFORE the account closes, because the mail gate
     * refuses a deactivated recipient (the same ordering the family notice uses).
     *
     * Says the two things a departing colleague actually needs: when their access ends,
     * and what happens about the hours they have worked.
     */
    private function sendExitNotice($user, int $agencyId, string $lastDay, array $unpaid): bool
    {
        try {
            if (empty($user->email)) {
                return false;
            }
            $agency = DB::table('agencies')->where('id', $agencyId)->first();
            $agencyName = $agency->name ?? 'the agency';
            $first = trim((string) ($user->first_name ?? '')) ?: 'Hello';
            $when = Carbon::parse($lastDay)->format('l, j F Y');

            $body = '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;">' . e($first) . ',</p>'
                . '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;">'
                . 'Your last working day with ' . e($agencyName) . ' is recorded as <strong>'
                . e($when) . '</strong>. Your access to the portal ends today, so this is the '
                . 'last message you will receive through it.</p>';

            if (($unpaid['hours'] ?? 0) > 0) {
                $body .= '<div style="background:#F0F9FF;border:1px solid #BAE6FD;border-radius:12px;padding:13px 15px;margin:14px 0;">'
                    . '<div style="font-weight:800;font-size:14px;color:#075985;margin-bottom:4px;">Your final pay</div>'
                    . '<div style="font-size:13.5px;color:#0C4A6E;line-height:1.55;">'
                    . 'Our records show <strong>' . e((string) $unpaid['hours']) . ' hours</strong> worked since your '
                    . 'last payslip. These will be settled in the normal pay run. If that does not match '
                    . 'your own record, reply to this email and we will look at it with you.</div></div>';
            }

            $body .= '<p style="margin:14px 0 0;font-size:14px;line-height:1.6;color:#475569;">'
                . 'Your employment records, certifications and any documents on file are kept for the '
                . 'period ' . e($agencyName) . ' is required to keep them. If you need a copy of anything, '
                . 'just ask.</p>'
                . '<p style="margin:16px 0 0;font-size:15px;line-height:1.6;color:#0F172A;">'
                . 'Thank you for the work you did here, and for the care you gave the children. '
                . 'We wish you well.</p>';

            $html = \App\Services\EmailTemplate::wrap($agencyId, $body, [
                'eyebrow' => 'LEAVING',
                'title' => 'Thank you, and all the best',
                'subtitle' => $agencyName,
                'preheader' => 'Your last day with ' . $agencyName . ' and your final pay.',
            ]);

            \App\Services\AgencyMailer::forAgency($agencyId)->mailer()
                ->html($html, function ($m) use ($user, $agencyName) {
                    $m->to($user->email)->subject('Leaving ' . $agencyName . ' — your access and final pay');
                    // The account is deactivated moments after this, and a closed account
                    // is exactly what the mail gate blocks.
                    $m->getHeaders()->addTextHeader('X-KT-Account-Notice', '1');
                });

            return true;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Staff exit notice failed', [
                'user' => $user->id ?? null, 'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function documentCount(int $userId): int
    {
        try {
            return DB::table('documents')->where('scope_type', 'user')->where('scope_id', $userId)->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
