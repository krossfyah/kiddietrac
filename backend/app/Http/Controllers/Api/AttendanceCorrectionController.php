<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Putting right an attendance record an educator missed.
 *
 * Every other path stamps occurred_at = now(), which is correct when somebody is
 * standing at the door and wrong the following afternoon. A director who noticed a
 * missing check-in could only check the child in at the moment they noticed, which
 * quietly writes a false arrival time into the record that ratios and billing are
 * calculated from — worse than the gap it was fixing.
 *
 * Three rules shape this:
 *
 *  1. IT IS ALWAYS VISIBLE AS A CORRECTION. The row is flagged backdated, carries the
 *     person who typed it, and is audited with both times. An attendance record that
 *     can be edited invisibly is not evidence of anything.
 *
 *  2. IT CANNOT PRODUCE AN IMPOSSIBLE DAY. A check-out before its check-in, two
 *     arrivals with no departure between them, a time in the future — all refused.
 *     The point is to repair the record, and a repair that leaves it incoherent has
 *     not repaired anything.
 *
 *  3. NOBODY IS NOTIFIED. The live paths tell parents their child has arrived. Sending
 *     that at half past four for something that happened at nine would alarm a family
 *     about a day that is already over.
 */
final class AttendanceCorrectionController extends Controller
{
    use AuthorizesTenantAccess;

    /** How far back a correction may reach. Beyond this, the record is history. */
    private const MAX_DAYS_BACK = 30;

    /** GET /director/attendance/day?child_id=&date= — the day as it stands. */
    public function day(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_id' => ['required', 'integer'],
            'date' => ['required', 'date'],
        ]);
        $this->assertStaff($request);
        $this->assertChild((int) $request->user()->id, (int) $data['child_id']);

        $tz = $this->tzFor((int) $data['child_id']);
        $day = Carbon::parse($data['date'], $tz);

        $rows = DB::table('check_events as ce')
            ->leftJoin('users as u', 'u.id', '=', 'ce.recorded_by_id')
            ->where('ce.child_id', $data['child_id'])
            ->whereBetween('ce.occurred_at', [
                $day->copy()->startOfDay()->utc(), $day->copy()->endOfDay()->utc(),
            ])
            ->orderBy('ce.occurred_at')
            ->get([
                'ce.id', 'ce.event_type', 'ce.occurred_at', 'ce.notes', 'ce.room_id',
                'ce.backdated', 'ce.created_at',
                'u.first_name', 'u.last_name',
            ])
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'event_type' => $r->event_type,
                'occurred_at' => $r->occurred_at,
                'local_time' => AgencyTime::fmt($r->occurred_at, $tz),
                'room_id' => $r->room_id ? (int) $r->room_id : null,
                'notes' => $r->notes,
                'backdated' => (bool) ($r->backdated ?? false),
                'entered_at' => $r->created_at,
                'recorded_by' => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: null,
            ])->values();

        return response()->json([
            'date' => $day->toDateString(),
            'timezone' => $tz,
            'events' => $rows,
            'max_days_back' => self::MAX_DAYS_BACK,
        ]);
    }

    /**
     * POST /director/attendance/manual
     *
     * Adds a check-in, a check-out, or both in one go — the common case being a whole
     * day nobody recorded.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_id' => ['required', 'integer'],
            'date' => ['required', 'date'],
            'check_in' => ['nullable', 'date_format:H:i'],
            'check_out' => ['nullable', 'date_format:H:i'],
            'reason' => ['required', 'string', 'min:3', 'max:300'],
        ]);
        $this->assertStaff($request);
        $this->assertChild((int) $request->user()->id, (int) $data['child_id']);

        if (empty($data['check_in']) && empty($data['check_out'])) {
            return response()->json(['message' => 'Give an arrival time, a departure time, or both.'], 422);
        }

        $childId = (int) $data['child_id'];
        $tz = $this->tzFor($childId);
        $day = Carbon::parse($data['date'], $tz)->startOfDay();

        $mk = fn (?string $hm) => $hm
            ? Carbon::parse($data['date'] . ' ' . $hm, $tz)
            : null;
        $in = $mk($data['check_in'] ?? null);
        $out = $mk($data['check_out'] ?? null);

        // ── the day itself has to be a real one ────────────────────────────────
        $now = Carbon::now($tz);
        if ($day->greaterThan($now->copy()->startOfDay())) {
            return response()->json(['message' => 'That date is in the future.'], 422);
        }
        if ($day->lessThan($now->copy()->subDays(self::MAX_DAYS_BACK)->startOfDay())) {
            return response()->json([
                'message' => 'Attendance can only be corrected within the last ' . self::MAX_DAYS_BACK . ' days.',
            ], 422);
        }
        foreach (['arrival' => $in, 'departure' => $out] as $label => $t) {
            if ($t && $t->greaterThan($now)) {
                return response()->json(['message' => 'That ' . $label . ' time has not happened yet.'], 422);
            }
        }
        if ($in && $out && $out->lessThanOrEqualTo($in)) {
            return response()->json(['message' => 'The departure has to be after the arrival.'], 422);
        }

        // ── and it has to fit around what is already recorded ──────────────────
        $existing = DB::table('check_events')
            ->where('child_id', $childId)
            ->whereBetween('occurred_at', [$day->copy()->utc(), $day->copy()->endOfDay()->utc()])
            ->orderBy('occurred_at')
            ->get(['id', 'event_type', 'occurred_at']);

        if ($in && $existing->firstWhere('event_type', 'check_in')) {
            return response()->json([
                'message' => 'An arrival is already recorded for that day. Remove it first if it is wrong.',
            ], 422);
        }
        if ($out && $existing->firstWhere('event_type', 'check_out')) {
            return response()->json([
                'message' => 'A departure is already recorded for that day. Remove it first if it is wrong.',
            ], 422);
        }
        // Adding only a departure, against an arrival already on file.
        if ($out && ! $in) {
            $priorIn = $existing->firstWhere('event_type', 'check_in');
            if (! $priorIn) {
                return response()->json([
                    'message' => 'There is no arrival recorded for that day to close off.',
                ], 422);
            }
            if ($out->utc()->lessThanOrEqualTo(Carbon::parse($priorIn->occurred_at))) {
                return response()->json([
                    'message' => 'The departure has to be after the recorded arrival of '
                        . AgencyTime::fmt($priorIn->occurred_at, $tz) . '.',
                ], 422);
            }
        }

        $roomId = $this->roomFor($childId);
        if (! $roomId) {
            return response()->json(['message' => 'That child has no room to record attendance against.'], 422);
        }

        $userId = (int) $request->user()->id;
        $made = [];
        DB::transaction(function () use ($in, $out, $childId, $roomId, $userId, $data, &$made) {
            foreach ([['check_in', $in], ['check_out', $out]] as [$type, $at]) {
                if (! $at) {
                    continue;
                }
                $id = DB::table('check_events')->insertGetId([
                    'child_id' => $childId,
                    'room_id' => $roomId,
                    'event_type' => $type,
                    'occurred_at' => $at->copy()->utc(),
                    'by_user_id' => $userId,
                    'recorded_by_id' => $userId,
                    // Says on the row itself that a person typed this in later.
                    'backdated' => true,
                    'notes' => 'Added later by staff: ' . trim($data['reason']),
                    'created_at' => now(),
                ]);
                $made[] = ['id' => $id, 'event_type' => $type, 'occurred_at' => $at->toDateTimeString()];
            }
        });

        /* Deliberately no CheckEventNotifier here. The live paths tell parents their
           child has arrived or gone; firing that for a day that already ended would
           read as something happening now. */

        $this->audit($request, $childId, $data, $made, $tz);
        $this->recheckRatio($childId, $roomId, $day);
        $this->notifyOversight($request, $childId, 'added', [
            'For date' => Carbon::parse($data['date'], $tz)->format('l j F Y'),
            'Arrival' => $data['check_in'] ?? '—',
            'Departure' => $data['check_out'] ?? '—',
            'Reason given' => $data['reason'],
        ]);

        return response()->json([
            'message' => count($made) === 2
                ? 'Arrival and departure recorded.'
                : 'Recorded.',
            'events' => $made,
        ], 201);
    }

    /**
     * DELETE /director/attendance/{event}
     *
     * Removing a wrong entry — the other half of being able to correct a day. Kept
     * narrow: only the event itself goes, and the audit row keeps what it said.
     */
    public function destroy(Request $request, int $eventId): JsonResponse
    {
        $this->assertStaff($request);

        $row = DB::table('check_events')->where('id', $eventId)->first();
        if (! $row) {
            return response()->json(['message' => 'That entry no longer exists.'], 404);
        }
        $this->assertChild((int) $request->user()->id, (int) $row->child_id);

        $tz = $this->tzFor((int) $row->child_id);
        DB::table('check_events')->where('id', $eventId)->delete();

        try {
            \App\Support\Audit::write([
                'user_id' => $request->user()->id,
                'agency_id' => $this->agencyForChild((int) $row->child_id),
                'action' => 'attendance.entry_removed',
                'entity_type' => 'child',
                'entity_id' => (int) $row->child_id,
                'payload' => json_encode([
                    'child_name' => $this->childName((int) $row->child_id),
                    'removed' => [
                        'event_type' => $row->event_type,
                        'occurred_at' => $row->occurred_at,
                        'local_time' => AgencyTime::fmt($row->occurred_at, $tz),
                        'was_backdated' => (bool) ($row->backdated ?? false),
                    ],
                ]),
                'ip_address' => substr((string) $request->ip(), 0, 45),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('attendance removal audit failed', ['event' => $eventId, 'e' => $e->getMessage()]);
        }

        $this->notifyOversight($request, (int) $row->child_id, 'removed', [
            'Entry' => $row->event_type === 'check_in' ? 'Arrival' : 'Departure',
            'Was recorded as' => AgencyTime::fmt($row->occurred_at, $tz)
                . ' on ' . Carbon::parse($row->occurred_at)->setTimezone($tz)->format('l j F Y'),
            'Had been entered late' => ($row->backdated ?? false) ? 'yes' : 'no',
        ]);

        return response()->json(['message' => 'Entry removed.']);
    }

    /**
     * Tell the agency's admins and the centre's directors that a record changed.
     *
     * Attendance is the record ratios and billing are argued from, so a change to it
     * is not a private act between one director and the database. Everyone with
     * oversight hears, by name, what was changed and why — the audit log answers this
     * too, but only for somebody already looking.
     *
     * The person who made the change is left off: they know, and a mailbox that
     * confirms your own actions is a mailbox people stop reading. Best-effort
     * throughout — a correctly recorded correction must never fail because a mail
     * server was slow.
     */
    private function notifyOversight(Request $request, int $childId, string $verb, array $details): void
    {
        try {
            $agencyId = $this->agencyForChild($childId);
            if (! $agencyId) {
                return;
            }

            $centreIds = DB::table('centres')->where('agency_id', $agencyId)
                ->whereNull('deleted_at')->pluck('id');

            $actorId = (int) $request->user()->id;
            $to = DB::table('role_assignments as ra')
                ->join('users as u', 'u.id', '=', 'ra.user_id')
                ->where('ra.active', 1)
                ->where(function ($q) use ($agencyId, $centreIds) {
                    $q->where(function ($x) use ($agencyId) {
                        $x->where('ra.role', 'agency_admin')->where('ra.agency_id', $agencyId);
                    });
                    if ($centreIds->isNotEmpty()) {
                        $q->orWhere(function ($x) use ($centreIds) {
                            $x->where('ra.role', 'centre_director')->whereIn('ra.centre_id', $centreIds);
                        });
                    }
                })
                ->where('u.id', '!=', $actorId)
                ->whereNull('u.deleted_at')->whereNotNull('u.email')
                ->distinct()->pluck('u.email')->filter()->unique()->values()->all();

            if (! $to) {
                return;
            }

            $actor = DB::table('users')->where('id', $actorId)->first(['first_name', 'last_name']);
            $actorName = trim(($actor->first_name ?? '') . ' ' . ($actor->last_name ?? '')) ?: 'A staff member';
            $childName = $this->childName($childId);

            $rows = '';
            foreach ($details as $label => $value) {
                $rows .= '<tr>'
                    . '<td style="padding:7px 12px 7px 0;color:#64748B;white-space:nowrap;">' . e($label) . '</td>'
                    . '<td style="padding:7px 0;color:#0F172A;font-weight:600;">' . e((string) $value) . '</td>'
                    . '</tr>';
            }

            $body = '<p style="margin:0 0 14px;"><strong>' . e($actorName) . '</strong> '
                . ($verb === 'removed' ? 'removed an attendance entry for ' : 'added a missed attendance entry for ')
                . '<strong>' . e($childName) . '</strong>.</p>'
                . '<table role="presentation" style="border-collapse:collapse;font-size:14px;margin:0 0 16px;">'
                . $rows . '</table>'
                . '<p style="margin:0 0 14px;font-size:13px;color:#475569;">Entered '
                . e(now()->setTimezone(AgencyTime::tz($agencyId))->format('j M Y \a\t g:i A')) . '.</p>'
                . '<p style="margin:0;font-size:12.5px;color:#64748B;">Attendance records feed ratios and billing, so '
                . 'every change is recorded. The full history is in the audit log.</p>';

            $subject = 'Attendance ' . ($verb === 'removed' ? 'entry removed' : 'corrected')
                . ' — ' . $childName;

            $html = \App\Services\EmailTemplate::wrap($agencyId, $body, [
                'eyebrow' => 'Attendance',
                'title' => $subject,
                'preheader' => $actorName . ' ' . $verb . ' an attendance entry for ' . $childName,
            ]);

            \App\Services\AgencyMailer::forAgency($agencyId)->mailer()->html($html, function ($m) use ($to, $subject, $agencyId) {
                $m->to($to[0])->subject($subject);
                if (count($to) > 1) {
                    $m->bcc(array_slice($to, 1));
                }
                try { $m->getHeaders()->addTextHeader('X-KT-Agency-Id', (string) $agencyId); }
                catch (Throwable $e) {}
            });
        } catch (Throwable $e) {
            Log::warning('attendance change notice failed', ['child' => $childId, 'e' => $e->getMessage()]);
        }
    }

    /**
     * Re-evaluate the corrected day's ratio.
     *
     * A missing sign-in does not only make the register wrong; it makes the ratio
     * that was calculated from it wrong too, and that is the number a licensing
     * inspector asks about. Best-effort — an attendance record that saved correctly
     * must never be rolled back because a recalculation failed.
     */
    private function recheckRatio(int $childId, int $roomId, Carbon $day): void
    {
        try {
            $room = \App\Models\Room::find($roomId);
            if (! $room) {
                return;
            }
            $result = app(\App\Services\RatioEngine::class)->evaluateDay($room, $day->copy());

            if ($result['skipped_no_shift']) {
                /* Said out loud rather than silently passing: without staff shifts for
                   that day there is nothing to measure educator presence against, and a
                   "no breaches found" that was never actually checked is the worst of
                   the three possible answers. */
                Log::info('Ratio not re-checked after an attendance correction: no shifts on file', [
                    'room' => $roomId, 'date' => $day->toDateString(),
                ]);

                return;
            }
            if ($result['breaches'] > 0) {
                \App\Support\Audit::write([
                    'user_id' => null,
                    'agency_id' => $this->agencyForChild($childId),
                    'action' => 'ratio.recalculated',
                    'entity_type' => 'room',
                    'entity_id' => $roomId,
                    'payload' => json_encode([
                        'for_date' => $day->toDateString(),
                        'trigger' => 'attendance correction',
                        'samples_checked' => $result['samples'],
                        'breaches_found' => $result['breaches'],
                    ]),
                    'created_at' => now(),
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('Ratio recheck failed', ['room' => $roomId, 'e' => $e->getMessage()]);
        }
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    /**
     * Directors and admins only.
     *
     * Educators record attendance as it happens; backdating is a correction to the
     * record, which is a different power and belongs with the people accountable for
     * it. An educator who forgot a check-in asks their director rather than editing
     * yesterday themselves.
     */
    private function assertStaff(Request $request): void
    {
        $ok = DB::table('role_assignments')
            ->where('user_id', $request->user()->id)->where('active', 1)
            ->whereIn('role', ['centre_director', 'agency_admin', 'platform_admin'])
            ->exists();
        abort_unless($ok, 403, 'Only a director or an administrator can correct attendance.');
    }

    private function roomFor(int $childId): ?int
    {
        return DB::table('enrollments')->where('child_id', $childId)
            ->whereNull('end_date')->orderByDesc('id')->value('room_id')
            ?: DB::table('enrollments')->where('child_id', $childId)
                ->orderByDesc('id')->value('room_id');
    }

    private function tzFor(int $childId): string
    {
        $agencyId = $this->agencyForChild($childId);

        return $agencyId ? AgencyTime::tz($agencyId) : 'America/Toronto';
    }

    private function agencyForChild(int $childId): ?int
    {
        $aid = DB::table('enrollments as e')
            ->join('rooms as r', 'r.id', '=', 'e.room_id')
            ->join('centres as c', 'c.id', '=', 'r.centre_id')
            ->where('e.child_id', $childId)->value('c.agency_id');

        return $aid ? (int) $aid : null;
    }

    private function childName(int $childId): string
    {
        $c = DB::table('children')->where('id', $childId)->first(['first_name', 'last_name', 'preferred_name']);

        return $c ? trim((($c->preferred_name ?: $c->first_name) . ' ' . $c->last_name)) : ('child #' . $childId);
    }

    /** Granular on purpose: the day, both times, and the reason given. */
    private function audit(Request $request, int $childId, array $data, array $made, string $tz): void
    {
        try {
            \App\Support\Audit::write([
                'user_id' => $request->user()->id,
                'agency_id' => $this->agencyForChild($childId),
                'action' => 'attendance.backdated',
                'entity_type' => 'child',
                'entity_id' => $childId,
                'payload' => json_encode([
                    'child_name' => $this->childName($childId),
                    'for_date' => $data['date'],
                    'check_in' => $data['check_in'] ?? null,
                    'check_out' => $data['check_out'] ?? null,
                    'reason' => $data['reason'],
                    'entered_at' => now()->toDateTimeString() . ' UTC',
                    'timezone' => $tz,
                    'events' => $made,
                ]),
                'ip_address' => substr((string) $request->ip(), 0, 45),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('attendance backdate audit failed', ['child' => $childId, 'e' => $e->getMessage()]);
        }
    }
}
