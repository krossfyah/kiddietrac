<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * v22p49 — Closes four competitor-parity gaps in one controller because
 * they're all simple CRUD against small new tables.
 *
 * Endpoints:
 *   POST   /care/logs                    Educator records a diaper / nap / meal / etc.
 *   GET    /care/logs/today              Today's logs for current user's child OR a centre's children (staff)
 *   GET    /care/logs/child/{child}      Recent logs for one child (parent + staff)
 *
 *   GET    /care/milestones/catalog      Static catalog of milestones by age band
 *   GET    /care/milestones/child/{child}  Recorded milestones for one child
 *   POST   /care/milestones              Mark a milestone status
 *
 *   POST   /care/portfolio/{child}       Returns the chronological observation timeline
 *
 *   POST   /staff/punch                  Clock in (or out if last punch open)
 *   GET    /staff/punches/me             Current user's punch history
 *   GET    /staff/punches/centre         Director view of all staff punches at the centre
 *
 *   POST   /public/tours                 Public tour booking (NO auth required)
 *   GET    /admin/tours                  List tour bookings for the agency
 *   PATCH  /admin/tours/{id}             Update status (confirm / complete / no-show / cancel)
 */
final class CareController extends Controller
{
    use ResolvesCentreContext;

    // ── Daily care logs ─────────────────────────────────────────────────

    public function logCare(Request $request): JsonResponse
    {
        $data = $request->validate([
            // child_id (one) or child_ids (many). Outdoor play, meals and sunscreen
            // happen to a whole room at once; child_id is kept so every existing
            // caller, including the installed APK, keeps working unchanged.
            'child_id' => ['required_without:child_ids', 'integer'],
            'child_ids' => ['required_without:child_id', 'array', 'min:1'],
            'child_ids.*' => ['integer'],
            // medicine + clothing: 2026-11-09, Anthony: "log a moment add medicine as an option as well as change of clothing"
            'log_type' => ['required', 'in:diaper,bathroom,nap,meal,snack,bottle,sunscreen,mood,outdoor,medicine,clothing'],
            // Not in the future. A log describes what happened; a picker set to the
            // wrong day otherwise files an entry for a time that has not arrived,
            // which is what put a 21:30 meal on a parent's timeline at 10:26.
            // Five minutes of slack so a slightly fast device clock still works.
            'occurred_at' => ['nullable', 'date', 'before_or_equal:'.now()->addMinutes(5)->toDateTimeString()],
            'ended_at' => ['nullable', 'date', 'before_or_equal:'.now()->addMinutes(5)->toDateTimeString()],

            'details' => ['nullable', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'amount_ml' => ['nullable', 'integer', 'min:0', 'max:2000'],
            'amount_oz' => ['nullable', 'numeric', 'min:0', 'max:60'],
        ]);

        /* Whose logs may this person write? logCare() previously inserted straight
           from the posted id with NO access check, so any signed-in user could write a
           care log against any child on the platform — every sibling method here
           checks. Accepting a list would multiply that, so it is enforced now. */
        $requested = ! empty($data['child_ids'])
            ? array_map('intval', $data['child_ids'])
            : [(int) $data['child_id']];
        $requested = array_values(array_unique(array_filter($requested)));

        $allowed = array_values(array_filter($requested, fn ($cid) => $this->canSeeChild($request, $cid)));
        if (empty($allowed)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        /* Signed in? A care log describes something that happened to a child in the
           room, so the child should be here. Not a hard block: an educator catching up
           after sign-out is a real workflow, so they may confirm and continue — and
           the confirmation is recorded, so a later reader can tell a live log from a
           retrospective one. */
        $absent = [];
        if (! $request->boolean('confirm_absent')) {
            foreach ($allowed as $childId) {
                $lastIn = DB::table('check_events')->where('child_id', $childId)
                    ->where('event_type', 'check_in')->orderByDesc('created_at')->value('created_at');
                $isHere = false;
                if ($lastIn) {
                    $isHere = ! DB::table('check_events')->where('child_id', $childId)
                        ->where('event_type', 'check_out')->where('created_at', '>', $lastIn)->exists();
                }
                if (! $isHere) {
                    $absent[] = DB::table('children')->where('id', $childId)
                        ->selectRaw("TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) as n")
                        ->value('n') ?: ('child #'.$childId);
                }
            }
        }
        if ($absent) {
            return response()->json([
                'message' => 'not_signed_in',
                'absent' => $absent,
                'prompt' => (count($absent) === 1 ? $absent[0].' is' : implode(', ', $absent).' are')
                    .' not signed in right now. Log this anyway as a catch-up entry?',
            ], 422);
        }

        $ids = [];
        foreach ($allowed as $childId) {
        $ids[] = DB::table('daily_care_logs')->insertGetId([
            'child_id' => $childId,
            'recorded_by_id' => $request->user()->id,
            'log_type' => $data['log_type'],
            // Parse to a real datetime — the app sends ISO 8601 ("2026-08-07T12:30:00.000Z")
            // and inserting that raw string into a MySQL datetime column 500s (invalid
            // format). Carbon normalises it to 'Y-m-d H:i:s' (UTC, the app timezone).
            'occurred_at' => ! empty($data['occurred_at']) ? \Illuminate\Support\Carbon::parse($data['occurred_at']) : now(),
            'ended_at' => ! empty($data['ended_at']) ? \Illuminate\Support\Carbon::parse($data['ended_at']) : null,
            'details' => $data['details'] ?? null,
            'notes' => $data['notes'] ?? null,
            'amount_ml' => $data['amount_ml'] ?? null,
            'amount_oz' => $data['amount_oz'] ?? null,
            'created_at' => now(),
        ]);
        }

        // Each child's own family is told straight away (App\Support\ChildMomentNotify).
        try {
            $items = [];
            foreach ($allowed as $cid) {
                $it = \App\Support\ChildMomentNotify::momentItem((int) $cid, (string) $data['log_type'], $data['notes'] ?? null, [],
                    $data['occurred_at'] ?? null, $data['ended_at'] ?? null, $data['details'] ?? null);
                if ($it) $items[] = $it;
            }
            \App\Support\ChildMomentNotify::after($items);
        } catch (\Throwable $e) { /* telling the family must never fail the log */ }

        /* Catch-up logs are allowed, but somebody should know. One reminder per
           educator per day — a morning of catching up must not send six emails. */
        if ($request->boolean('confirm_absent') && ! empty($ids)) {
            try {
                \App\Support\AttendanceReminder::maybeSend(
                    (int) $request->user()->id, $allowed, $request
                );
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Attendance reminder failed: '.$e->getMessage());
            }
        }

        // `id` kept for callers that expect a single value; `ids`/`count` are new.
        return response()->json([
            'id' => $ids[0],
            'ids' => $ids,
            'count' => count($ids),
            'skipped' => count($requested) - count($allowed),
            'message' => count($ids) === 1 ? 'Logged' : ('Logged for '.count($ids).' children'),
        ], 201);
    }

    /**
     * GET /care/logs/recent?child_ids=1,2,3 — the whole roster in one request.
     *
     * The per-child endpoint below is correct and stays; what was wrong was calling
     * it ninety-six times. screen-care.js fired one request per child in parallel,
     * which is what saturates the box at drop-off: the 508 "resource limit reached"
     * responses cluster at 08:00–09:00 and name this exact path.
     *
     * Two queries for the whole list rather than two per child, and one framework
     * boot instead of twenty.
     */
    public function recentLogsBatch(Request $request): JsonResponse
    {
        $since = $request->query('since')
            ? \Carbon\Carbon::parse($request->query('since'))
            : now()->subDays(7);

        $asked = collect(explode(',', (string) $request->query('child_ids', '')))
            ->map(fn ($v) => (int) trim($v))->filter()->unique()->values();

        /* Intersected with what this person may see, never trusted. A roster is a
           bulk read, so an id they cannot see simply returns nothing for that child
           rather than failing the whole screen. */
        $allowed = collect($this->visibleChildIds($request));
        $ids = $asked->isEmpty()
            ? $allowed->all()
            : $asked->intersect($allowed)->values()->all();

        if (empty($ids)) {
            return response()->json(['logs' => [], 'by_child' => new \stdClass()]);
        }

        $care = DB::table('daily_care_logs as l')
            ->leftJoin('users as u', 'u.id', '=', 'l.recorded_by_id')
            ->whereIn('l.child_id', $ids)
            ->where('l.occurred_at', '>=', $since)
            ->orderByDesc('l.occurred_at')
            ->limit(2000)
            ->get([
                'l.id', 'l.child_id', 'l.log_type', 'l.occurred_at', 'l.ended_at',
                'l.details', 'l.notes', 'l.amount_ml', 'l.amount_oz',
                DB::raw("COALESCE(NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), ''), 'staff') as logged_by"),
            ])
            ->map(function ($r) { $r->source = 'care_log'; return $r; });

        /* The same second table the per-child endpoint merges: a nappy logged from
           the room roster lands in daily_events, not daily_care_logs, and reading
           only one of them is how entries looked lost. */
        $events = DB::table('daily_events as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.recorded_by_id')
            ->whereIn('e.child_id', $ids)
            ->whereNull('e.deleted_at')
            ->where('e.occurred_at', '>=', $since)
            ->whereIn('e.event_type', ['diaper', 'bathroom', 'nap', 'meal', 'snack', 'bottle', 'sunscreen', 'mood', 'outdoor'])
            ->orderByDesc('e.occurred_at')
            ->limit(2000)
            ->get([
                'e.id', 'e.child_id', 'e.event_type', 'e.occurred_at', 'e.payload', 'e.notes',
                DB::raw("COALESCE(NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), ''), 'staff') as logged_by"),
            ])
            ->map(function ($e) {
                $details = null;
                if (! empty($e->payload)) {
                    $p = json_decode($e->payload, true);
                    if (is_array($p)) {
                        $parts = [];
                        foreach ($p as $v) {
                            if (is_scalar($v) && trim((string) $v) !== '') { $parts[] = (string) $v; }
                        }
                        $details = $parts ? implode(', ', $parts) : null;
                    } elseif (is_string($p) && trim($p) !== '') {
                        $details = $p;
                    }
                }

                return (object) [
                    'id' => $e->id, 'child_id' => $e->child_id, 'log_type' => $e->event_type,
                    'occurred_at' => $e->occurred_at, 'ended_at' => null, 'details' => $details,
                    'notes' => $e->notes, 'amount_ml' => null, 'amount_oz' => null,
                    'logged_by' => $e->logged_by, 'source' => 'event',
                ];
            });

        $all = $care->concat($events)->sortByDesc('occurred_at')->values();

        /* Grouped as well as flat: the roster wants "this child's latest", the
           activity feed wants one merged list, and doing it here saves the browser
           re-sorting the same rows twice. */
        $byChild = [];
        foreach ($all as $r) {
            $cid = (string) $r->child_id;
            if (! isset($byChild[$cid])) { $byChild[$cid] = []; }
            if (count($byChild[$cid]) < 300) { $byChild[$cid][] = $r; }
        }

        return response()->json([
            'logs' => $all->take(600)->values(),
            'by_child' => $byChild ?: new \stdClass(),
        ]);
    }

    public function logsForChild(Request $request, int $child): JsonResponse
    {
        // Parent-or-staff access check — guardian on the child's family, or
        // staff at the child's centre.
        if (!$this->canSeeChild($request, $child)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $since = $request->query('since')
            ? \Carbon\Carbon::parse($request->query('since'))
            : now()->subDays(7);

        $rows = DB::table('daily_care_logs as l')
            ->leftJoin('users as u', 'u.id', '=', 'l.recorded_by_id')
            ->where('l.child_id', $child)
            ->where('l.occurred_at', '>=', $since)
            ->orderByDesc('l.occurred_at')
            ->limit(300)
            ->get([
                'l.id', 'l.log_type', 'l.occurred_at', 'l.ended_at',
                'l.details', 'l.notes', 'l.amount_ml', 'l.amount_oz',
                DB::raw("COALESCE(NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), ''), 'staff') as logged_by"),
            ])
            ->map(function ($r) { $r->source = 'care_log'; return $r; });

        // A care moment can be recorded from TWO places, into two different
        // tables: "Log a moment" writes daily_care_logs, while the room roster's
        // quick-log writes daily_events. Reading only the first meant a nappy
        // logged from the roster never appeared in Today's log — the entry looked
        // lost. Merge both so every reader sees the child's full day.
        $eventRows = DB::table('daily_events as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.recorded_by_id')
            ->where('e.child_id', $child)
            ->whereNull('e.deleted_at')
            ->where('e.occurred_at', '>=', $since)
            ->whereIn('e.event_type', ['diaper', 'bathroom', 'nap', 'meal', 'snack', 'bottle', 'sunscreen', 'mood', 'outdoor'])
            ->orderByDesc('e.occurred_at')
            ->limit(300)
            ->get([
                'e.id', 'e.event_type', 'e.occurred_at', 'e.payload', 'e.notes',
                DB::raw("COALESCE(NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), ''), 'staff') as logged_by"),
            ])
            ->map(function ($e) {
                // payload is a small JSON blob ({"type":"wet"}) — flatten its values
                // into the same "details" string daily_care_logs uses ("wet").
                $details = null;
                if (!empty($e->payload)) {
                    $p = json_decode($e->payload, true);
                    if (is_array($p)) {
                        $parts = [];
                        foreach ($p as $v) {
                            if (is_scalar($v) && trim((string) $v) !== '') {
                                $parts[] = (string) $v;
                            }
                        }
                        $details = $parts ? implode(', ', $parts) : null;
                    } elseif (is_string($p) && trim($p) !== '') {
                        $details = $p;
                    }
                }
                return (object) [
                    'id' => $e->id,
                    'log_type' => $e->event_type,
                    'occurred_at' => $e->occurred_at,
                    'ended_at' => null,
                    'details' => $details,
                    'notes' => $e->notes,
                    'amount_ml' => null,
                    'amount_oz' => null,
                    'logged_by' => $e->logged_by,
                    'source' => 'event',
                ];
            });

        $rows = $rows->concat($eventRows)
            ->sortByDesc('occurred_at')
            ->values()
            ->take(300);

        // "Today" means today at the CENTRE, not today in UTC. An 8pm Toronto
        // nappy is stamped 00:07 the next day in UTC, so a UTC-midnight cutoff
        // dropped every late-afternoon and evening entry from the day's summary.
        $tz = $this->centreTimezoneForChild($child);
        $today = \Carbon\Carbon::now($tz)->startOfDay()->timezone(config('app.timezone', 'UTC'));

        $summary = [];
        foreach (['diaper','bathroom','nap','meal','snack','bottle','sunscreen','mood','outdoor'] as $t) {
            $summary[$t] = $rows
                ->filter(fn ($r) => $r->log_type === $t && \Carbon\Carbon::parse($r->occurred_at)->gte($today))
                ->count();
        }

        // Times in the centre's zone, sent so that they SAY SO.
        //
        // This used to emit 'Y-m-d H:i:s' — the centre's wall clock with no zone marker,
        // which is indistinguishable from the UTC datetimes the rest of this API returns.
        // A client cannot tell the two apart, so it has to guess, and any guess is wrong
        // half the time. It broke for real: a client-side fix that (correctly) treats
        // zone-less datetimes as UTC re-stamped these already-local times and every
        // educator daily log rendered four hours early.
        //
        // ISO-8601 with the offset attached is unambiguous — "2026-08-17T10:45:00-04:00"
        // means one instant and only one, whatever the reader's device is set to.
        // time_display is the same moment ready to render, so a screen never has to
        // compute a wall clock at all.
        $rows = $rows->map(function ($r) use ($tz) {
            // UTC on the wire, not the local offset. One format across the whole API
            // keeps these strings sortable by plain comparison, which several screens
            // rely on; mixed offsets sort wrongly while looking right. The human-facing
            // time travels beside it as time_display, in the centre's zone.
            $at = \Carbon\Carbon::parse($r->occurred_at);
            $r->occurred_at = $at->copy()->utc()->toIso8601ZuluString();
            $r->time_display = \App\Support\AgencyTime::fmt($at, $tz);
            if (!empty($r->ended_at)) {
                $r->ended_at = \Carbon\Carbon::parse($r->ended_at)->utc()->toIso8601ZuluString();
            }
            return $r;
        });

        return response()->json(['logs' => $rows->values(), 'today_summary' => $summary]);
    }

    /**
     * The timezone the child's centre actually operates in.
     *
     * There is no `timezone` column on centres — the value /provider/bootstrap
     * reports is a constant — so we look in the centre's settings JSON and fall
     * back to the same default the rest of the app assumes. This matters because
     * app.timezone is UTC: without it, an early-evening entry in Toronto is
     * stamped tomorrow and disappears from "today".
     */
    private const DEFAULT_TZ = 'America/Toronto';

    private function centreTimezoneForChild(int $child): string
    {
        $settings = DB::table('children as c')
            ->leftJoin('families as f', 'f.id', '=', 'c.family_id')
            ->leftJoin('centres as ce', 'ce.id', '=', 'f.centre_id')
            ->where('c.id', $child)
            ->value('ce.settings');

        if ($settings) {
            $decoded = json_decode((string) $settings, true);
            if (is_array($decoded) && !empty($decoded['timezone'])) {
                return (string) $decoded['timezone'];
            }
        }

        return self::DEFAULT_TZ;
    }

    // ── Milestones ──────────────────────────────────────────────────────

    /**
     * Static HDLH-style milestone catalog. Returned to the UI so the
     * checklist can render without a separate seed table. Grouped by
     * domain × age band so a 2-year-old shows toddler milestones, etc.
     */
    public function milestoneCatalog(): JsonResponse
    {
        return response()->json(['catalog' => $this->catalog()]);
    }

    public function milestonesForChild(Request $request, int $child): JsonResponse
    {
        if (!$this->canSeeChild($request, $child)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $rows = DB::table('milestone_records')
            ->where('child_id', $child)
            ->orderByDesc('observed_at')
            ->get();
        $byKey = $rows->keyBy('milestone_key');
        return response()->json(['records' => $byKey, 'list' => $rows]);
    }

    public function recordMilestone(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_id' => ['required', 'integer'],
            'framework' => ['required', 'in:HDLH,ELECT,ELOF,custom'],
            'domain' => ['required', 'string', 'max:80'],
            'milestone_key' => ['required', 'string', 'max:120'],
            'milestone_label' => ['required', 'string', 'max:200'],
            'status' => ['required', 'in:emerging,in_progress,achieved'],
            'observed_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        if (!$this->canSeeChild($request, (int) $data['child_id'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        // Upsert — one row per (child, milestone_key)
        DB::table('milestone_records')->updateOrInsert(
            ['child_id' => (int) $data['child_id'], 'milestone_key' => $data['milestone_key']],
            [
                'recorded_by_id' => $request->user()->id,
                'framework' => $data['framework'],
                'domain' => $data['domain'],
                'milestone_label' => $data['milestone_label'],
                'status' => $data['status'],
                'observed_at' => $data['observed_at'],
                'notes' => $data['notes'] ?? null,
                'updated_at' => now(),
            ]
        );
        // A milestone reached or started is news for the family (App\Support\ChildMomentNotify).
        try {
            $st = ['emerging' => 'emerging', 'in_progress' => 'in progress', 'achieved' => 'achieved'][$data['status']] ?? $data['status'];
            \App\Support\ChildMomentNotify::after([[
                'child_id' => (int) $data['child_id'], 'kind' => 'milestones', 'emoji' => '🌟',
                'label' => 'Milestone ' . $st, 'text' => (string) $data['milestone_label'], 'link' => '#today',
            ]]);
        } catch (\Throwable $e) {}
        return response()->json(['message' => 'Saved']);
    }

    // ── Child portfolio ─────────────────────────────────────────────────

    public function portfolio(Request $request, int $child): JsonResponse
    {
        if (!$this->canSeeChild($request, $child)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $childRow = DB::table('children')->where('id', $child)->whereNull('deleted_at')->first();
        if (!$childRow) return response()->json(['message' => 'Not found'], 404);

        $observations = DB::table('observations as o')
            ->leftJoin('users as u', 'u.id', '=', 'o.recorded_by_id')
            ->where('o.child_id', $child)
            ->orderByDesc('o.observed_at')
            ->limit(500)
            ->get([
                'o.id', 'o.framework', 'o.domain', 'o.title', 'o.body',
                'o.observed_at', 'o.media_ids', 'o.shared_with_family',
                DB::raw("COALESCE(NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), ''), 'staff') as recorded_by"),
            ]);

        $milestones = DB::table('milestone_records')
            ->where('child_id', $child)
            ->orderByDesc('observed_at')
            ->get();

        $stats = [
            'observations' => $observations->count(),
            'milestones_achieved' => $milestones->where('status', 'achieved')->count(),
            'milestones_in_progress' => $milestones->where('status', 'in_progress')->count(),
            'milestones_emerging' => $milestones->where('status', 'emerging')->count(),
        ];

        return response()->json([
            'child' => $childRow,
            'observations' => $observations,
            'milestones' => $milestones,
            'stats' => $stats,
        ]);
    }

    // ── Staff time clock ────────────────────────────────────────────────

    public function punch(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        /* `source` lands in an ENUM('web','kiosk','mobile','auto'). It was written
           straight through from the request, so any other value — a typo, an old client,
           a probe — reached MySQL as "Data truncated for column 'source'" and came back
           as a 500 on somebody's clock-in. A punch is the last thing that should fail
           loudly for a reason the person cannot act on: an unknown source is simply
           'web'. (Found while testing the rota guard, 2026-08-31.) */
        $source = (string) ($request->input('source') ?: 'web');
        if (! in_array($source, ['web', 'kiosk', 'mobile', 'auto'], true)) {
            $source = 'web';
        }
        $request->merge(['source' => $source]);
        // v22p97: resolve the centre WITHIN the active agency (header-aware) so a
        // multi-agency user clocks in at the agency they've switched into, and a
        // super-admin testing Test Agency resolves to its centre instead of the
        // bogus "no centre assigned" (which fired because their only role rows are
        // agency/platform-level with no centre_id).
        $centreId = $this->resolveCentreId($request->user());
        if (!$centreId) return response()->json(['message' => 'No centre assigned'], 422);

        /* A DOUBLE TAP IS ONE PRESS (2026-10-09). The clock is a toggle, so a second tap
           seconds after the first clocked straight back out (or in): Sukhman 13:17:33 in,
           13:17:36 out, 13:17:43 in again; Amna clocked out and two more taps made two
           2-second "shifts". Each showed on the timesheet as an extra 0h row. A press within
           20 seconds of this person's last clock-in or clock-out answers with that same
           result and records nothing new. */
        $last = DB::table('time_punches')->where('user_id', $userId)->orderByDesc('id')->first(['id', 'punched_in_at', 'punched_out_at']);
        /* Only for presses with no intent (old clients, the blind toggle). A press that says
           in or out is answered by the intent check below, and the guard here swallowed
           "End my day" picked seconds after "Lunch" - leaving them on a break. (2026-11-05) */
        if ($last && ! in_array($request->input('intent'), ['in', 'out'], true)) {
            $lastAt = \Illuminate\Support\Carbon::parse($last->punched_out_at ?: $last->punched_in_at);
            if (abs(now()->diffInSeconds($lastAt)) < 20) {
                return response()->json($last->punched_out_at
                    ? ['action' => 'out', 'minutes_worked' => 0, 'message' => 'Already clocked out a moment ago', 'repeat' => true]
                    : ['action' => 'in', 'id' => (int) $last->id, 'message' => 'Already clocked in a moment ago', 'repeat' => true]);
            }
        }

        /* WHAT THE PERSON PRESSED (2026-11-05). The clock was a blind toggle: a strip
           showing a stale "not clocked in" sent a press that the server took as a clock
           OUT, and the other way round. Clients now send intent in|out; a press that no
           longer matches the real state is answered with the state, not flipped. No
           intent (an old client) keeps the toggle. */
        $intent = in_array($request->input('intent'), ['in', 'out'], true) ? (string) $request->input('intent') : null;
        $hasOpen = DB::table('time_punches')->where('user_id', $userId)->whereNull('punched_out_at')->exists();
        $onBreak = $hasOpen ? null : \App\Support\StaffBreaks::current($userId);

        /* ON A BREAK (2026-11-05): the shift row is closed but the day is not over.
           Clocking in = back from the break; clocking out = the day ended when the break
           began. Neither goes through the closure / rota guards below - they are for
           starting a day, and this person already started theirs. */
        if ($onBreak) {
            if ($intent === 'out') {
                $b = \App\Support\StaffBreaks::endDay($userId);
                $this->auditClock($request, $userId, 'out', (int) ($b->centre_id ?? $centreId), null, ['from_break' => $b->kind ?? null]);
                return response()->json(['action' => 'out', 'minutes_worked' => 0, 'from_break' => true,
                    'message' => 'Clocked out. Your shift ended when your ' . strtolower(\App\Support\StaffBreaks::KINDS[$b->kind ?? 'break'] ?? 'break') . ' began.']);
            }
            return $this->backFromBreak($request, $userId, $centreId);
        }
        if ($intent === 'in' && $hasOpen) {
            return response()->json(['message' => "You're already clocked in.", 'reason' => 'already_in', 'action' => 'in', 'repeat' => true], 409);
        }
        if ($intent === 'out' && ! $hasOpen) {
            return response()->json(['message' => "You're not clocked in, so there is nothing to clock out of.", 'reason' => 'not_clocked_in', 'action' => 'out', 'repeat' => true], 409);
        }

        // The centre is shut — there is no shift to start.
        //
        // This guard was written onto StaffController::clockIn, which serves /clock-in, and
        // nothing calls that route: the clock was consolidated onto /staff/punch. So it has
        // never fired. It belongs here, on the endpoint people actually press.
        //
        // Only blocks clocking IN. Somebody already on shift when a closure is entered must
        // still be able to clock OUT, or their hours are stranded and payroll is wrong —
        // which is the opposite of what this is for.
        $isClockingIn = ! DB::table('time_punches')->where('user_id', $userId)->whereNull('punched_out_at')->exists();
        if ($isClockingIn && ($closure = \App\Support\Closures::forDate($centreId))) {
            return response()->json([
                'message' => 'The centre is closed today (' . \App\Support\Closures::reason($closure)
                    . '), so there is no shift to clock into. Enjoy the day.',
                'closed' => true,
                'closure' => [
                    'dates' => \App\Support\Closures::dateLabel($closure),
                    'reason' => \App\Support\Closures::reason($closure),
                ],
            ], 422);
        }

        /* Not on today's rota.
         *
         * Clocking IN only, for the same reason as the closure guard above: somebody
         * already on shift must always be able to clock OUT, or their hours are stranded
         * and payroll is wrong.
         *
         * FAILS OPEN, and that matters more than the rule itself. It refuses only when
         * the centre is demonstrably RUNNING a rota — there are shifts scheduled there in
         * the surrounding fortnight — and this person has none today. A centre that does
         * not use the schedule is untouched; without that, switching the rota on for one
         * room would lock every other educator in the building out of their own clock.
         */
        if ($isClockingIn && $this->notRosteredToday($request, $userId, $centreId)) {
            return response()->json([
                'message' => "You're not on today's schedule yet, so there's no shift to "
                    . "clock into. If you're covering for someone or your days have "
                    . "changed, your centre director or admin can add you to today's rota "
                    . "— a quick message to them will sort it out.",
                'not_scheduled' => true,
            ], 422);
        }

        $open = DB::table('time_punches')
            ->where('user_id', $userId)
            ->whereNull('punched_out_at')
            ->orderByDesc('punched_in_at')
            ->first();

        if ($open) {
            /* NOBODY CLOCKS OUT LEAVING A CHILD SIGNED IN - UNWARNED.

               A child still marked present after the last adult has gone is the failure
               this exists to prevent: the roster says they are here, the ratio counts
               them, and the record of when they left is lost. Caught at the one moment
               somebody can still fix it in seconds.

               Scoped to rooms this person can act on — their assignments if they have
               any, otherwise their centre's, the same rule assignedRoomIds() uses.
               Blocking somebody over a child in a room they cannot open would trap them.

               The message names every child, because "sign the children out first" to
               somebody who believes they already did is not actionable. */
            $children = $this->childrenStillIn($userId, (int) ($open->centre_id ?: $centreId));

            /* WARN, THEN ALLOW (2026-11-05, Anthony). This refused outright, which also
               refused the ordinary case: a colleague is covering the room and this person
               is going to lunch or going home. The warning still names every child; the
               person confirms (confirm_children) and the override is audited with the
               list, so the record of who left children signed in is kept. */
            if ($children->isNotEmpty() && ! $request->boolean('confirm_children')) {
                return response()->json($this->childrenStillInBody($children, $userId, (int) ($open->centre_id ?: $centreId), 'clock out'), 409);
            }
            $overrode = $children->isNotEmpty() ? $children->pluck('label')->values()->all() : null;

            DB::table('time_punches')->where('id', $open->id)->update([
                'punched_out_at' => now(),
                'notes' => $request->input('notes') ?: $open->notes,
            ]);
            // diffInMinutes returns a (possibly negative) FLOAT on Carbon 3 — force a
            // positive int for both the audit (?int param) and the response.
            $mins = (int) abs(now()->diffInMinutes(\Carbon\Carbon::parse($open->punched_in_at)));
            $this->notifyClockEvent($request->user(), (int) ($open->centre_id ?: $centreId), 'out');
            $this->auditClock($request, $userId, 'out', (int) ($open->centre_id ?: $centreId), $mins, $overrode ? ['children_still_signed_in' => $overrode] : []);
            return response()->json([
                'action' => 'out',
                'minutes_worked' => $mins,
                'message' => 'Clocked out',
            ]);
        }

        $id = DB::table('time_punches')->insertGetId([
            'user_id' => $userId,
            'centre_id' => (int) $centreId,
            'punched_in_at' => now(),
            'notes' => $request->input('notes') ?: null,
            'source' => $request->input('source') ?: 'web',
            'created_at' => now(),
        ]);
        $this->notifyClockEvent($request->user(), (int) $centreId, 'in');
        $this->auditClock($request, $userId, 'in', (int) $centreId, null);
        return response()->json(['action' => 'in', 'id' => $id, 'message' => 'Clocked in']);
    }

    /**
     * Children still signed in to the rooms this person can act on (their assignments,
     * otherwise their centre's) - the list the clock-out and break warnings name.
     */
    private function childrenStillIn(int $userId, int $centreId): \Illuminate\Support\Collection
    {
        $myRooms = DB::table('educator_rooms')->where('user_id', $userId)
            ->pluck('room_id')->map(fn ($i) => (int) $i)->all();
        if (! $myRooms) {
            $myRooms = DB::table('rooms')
                ->where('centre_id', $centreId)
                ->pluck('id')->map(fn ($i) => (int) $i)->all();
        }

        $stillIn = $myRooms ? DB::table('check_events as ci')
            ->join('children as ch', 'ch.id', '=', 'ci.child_id')
            ->leftJoin('rooms as r', 'r.id', '=', 'ci.room_id')
            ->whereIn('ci.room_id', $myRooms)
            ->where('ci.event_type', 'check_in')
            /* Agency day as instants. This is the guard that refuses a clock-out
               while children are still signed in; on the UTC date it found nobody
               from 8pm and let an educator leave a room still marked occupied. */
            ->where('ci.occurred_at', '>=', \App\Support\AgencyTime::dayRangeForCentre($centreId)[0])
            ->where('ci.occurred_at', '<', \App\Support\AgencyTime::dayRangeForCentre($centreId)[1])
            /* Same tie-break as RoomController::presentCount: a check-out is "later"
               on a greater timestamp, or the same second with a higher id, so a rapid
               re-scan does not read as still-present. */
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('check_events as co')
                ->whereColumn('co.child_id', 'ci.child_id')
                ->where('co.event_type', 'check_out')
                ->where(fn ($w) => $w->whereColumn('co.occurred_at', '>', 'ci.occurred_at')
                    ->orWhere(fn ($w2) => $w2->whereColumn('co.occurred_at', 'ci.occurred_at')
                        ->whereColumn('co.id', '>', 'ci.id'))))
            ->orderBy('ci.occurred_at')
            ->get(['ci.child_id', 'ci.occurred_at', 'ch.first_name', 'ch.preferred_name',
                   'ch.last_name', 'r.name as room_name'])
            ->unique('child_id')->values() : collect();

        if ($stillIn->isEmpty()) return collect();
        $tz = \App\Support\AgencyTime::tzForCentre($centreId);
        $children = $stillIn->map(function ($c) use ($tz) {
            $name = trim(($c->preferred_name ?: $c->first_name) . ' ' . $c->last_name);
            $since = \App\Support\AgencyTime::fmt($c->occurred_at, $tz, 'g:i A');

            return [
                'child_id' => (int) $c->child_id,
                'name' => $name,
                'room' => $c->room_name,
                'since' => $since,
                'label' => $name . ($c->room_name ? ' — ' . $c->room_name : '')
                    . ($since ? ' — in since ' . $since : ''),
            ];
        })->values();

        return $children;
    }

    /** The 409 body for "children are still signed in" - a warning the person can confirm past. */
    private function childrenStillInBody(\Illuminate\Support\Collection $children, int $userId, int $centreId, string $doing): array
    {
        $n = $children->count();
        // Who else is on the clock here right now - "is anyone covering?" is the question being asked.
        $covering = DB::table('time_punches as t')->join('users as u', 'u.id', '=', 't.user_id')
            ->where('t.centre_id', $centreId)->whereNull('t.punched_out_at')->where('t.user_id', '<>', $userId)
            ->where('t.punched_in_at', '>=', now()->subHours(16))
            ->orderBy('u.first_name')->pluck('u.first_name')->filter()->unique()->values()->all();

        return [
            'message' => $n . ' ' . ($n === 1 ? 'child is' : 'children are') . ' still signed in to your '
                . ($n === 1 ? 'room' : 'rooms') . '. Make sure ' . ($n === 1 ? 'they are' : 'they are all')
                . ' signed out or that someone is covering before you ' . $doing . '.',
            'blocked' => true,
            'can_override' => true,
            'reason' => 'children_still_signed_in',
            'children' => $children,
            'covering' => $covering,
            'what_to_do' => $covering
                ? 'Still on the clock here: ' . implode(', ', $covering) . '. If one of them has the room, you can go ahead.'
                : 'Nobody else is clocked in here right now. Sign the children out, or hand the room over first.',
        ];
    }

    /** POST /staff/break/start {kind: lunch|break} - off the floor without ending the day. */
    public function breakStart(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $kind = (string) $request->input('kind');
        if (! isset(\App\Support\StaffBreaks::KINDS[$kind])) return response()->json(['message' => 'Pick lunch or break.'], 422);
        if (\App\Support\StaffBreaks::current($userId)) return response()->json(['message' => "You're already on a break.", 'reason' => 'already_on_break'], 409);
        $open = DB::table('time_punches')->where('user_id', $userId)->whereNull('punched_out_at')->orderByDesc('punched_in_at')->first();
        if (! $open) return response()->json(['message' => "You're not clocked in, so there is no shift to take a break from.", 'reason' => 'not_clocked_in'], 409);
        $centreId = (int) ($open->centre_id ?: $this->resolveCentreId($request->user()));

        $children = $this->childrenStillIn($userId, $centreId);
        $label = strtolower(\App\Support\StaffBreaks::KINDS[$kind]);
        if ($children->isNotEmpty() && ! $request->boolean('confirm_children')) {
            return response()->json($this->childrenStillInBody($children, $userId, $centreId, 'go on ' . $label), 409);
        }

        $r = \App\Support\StaffBreaks::start($userId, $kind);
        if (! $r['ok']) return response()->json(['message' => $r['message']], 409);
        $mins = (int) abs(now()->diffInMinutes(\Carbon\Carbon::parse($open->punched_in_at)));
        $this->notifyClockEvent($request->user(), $centreId, 'break_' . $kind);
        $this->auditClock($request, $userId, 'break_start', $centreId, $mins, ['kind' => $kind, 'paid' => (bool) $r['break']->paid]
            + ($children->isNotEmpty() ? ['children_still_signed_in' => $children->pluck('label')->values()->all()] : []));

        return response()->json(['action' => 'break', 'on_break' => \App\Support\StaffBreaks::present($r['break']),
            'message' => 'Enjoy your ' . $label . '. Tap "Back" when you return.']);
    }

    /** POST /staff/break/end - back on the clock. */
    public function breakEnd(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        if (! \App\Support\StaffBreaks::current($userId)) {
            return response()->json(['message' => "You're not on a break.", 'reason' => 'not_on_break'], 409);
        }

        return $this->backFromBreak($request, $userId, (int) $this->resolveCentreId($request->user()));
    }

    private function backFromBreak(Request $request, int $userId, int $centreId): JsonResponse
    {
        $r = \App\Support\StaffBreaks::end($userId, (string) ($request->input('source') ?: 'web'));
        if (! $r['ok']) return response()->json(['message' => $r['message'], 'reason' => 'not_on_break'], 409);
        $b = $r['break'];
        $centreId = (int) ($b->centre_id ?: $centreId);
        $this->notifyClockEvent($request->user(), $centreId, 'back');
        $this->auditClock($request, $userId, 'break_end', $centreId, $r['minutes'], ['kind' => $b->kind, 'paid' => (bool) $b->paid, 'break_minutes' => $r['minutes']]);
        $label = strtolower(\App\Support\StaffBreaks::KINDS[$b->kind] ?? 'break');

        return response()->json(['action' => 'in', 'id' => $r['punch_id'], 'from_break' => true, 'break_minutes' => $r['minutes'],
            'message' => 'Welcome back. ' . ucfirst($label) . ' ' . $r['minutes'] . ' min' . ($b->paid ? ' (paid)' : '') . '.']);
    }

    /**
     * Write a DETAILED audit entry for a clock punch, so the audit trail says
     * exactly whether the user clocked IN or OUT, at which centre, and (on clock
     * out) for how long — instead of the generic "post:staff/punch" the middleware
     * would record for a toggle endpoint. The distinct action (staff.clock_in /
     * staff.clock_out) + a top-level `summary` string drive a clean line in the
     * audit viewer. Never throws.
     */
    /**
     * Is this person rostered off today, at a centre that actually keeps a rota?
     *
     * Three conditions, all of which must hold before anyone is refused:
     *   1. they are an EDUCATOR and nothing more senior — a director or admin covering
     *      the floor is exactly who has to be able to clock in on a day nobody rostered
     *      them, and they are usually not on the rota at all;
     *   2. the centre HAS shifts either side of today, i.e. somebody maintains it;
     *   3. this person has none of them today.
     *
     * "Today" is the AGENCY's day, not the server's — a punch at 8pm Toronto is already
     * tomorrow in UTC, and reading the wrong day would refuse an evening shift.
     */
    private function notRosteredToday(Request $request, int $userId, int $centreId): bool
    {
        try {
            $roles = \App\Support\AgencyRole::names($request);
            foreach (['platform_admin', 'agency_admin', 'centre_director'] as $senior) {
                if (in_array($senior, $roles, true)) return false;
            }
            if (! in_array('educator', $roles, true)) return false;

            $agencyId = (int) DB::table('centres')->where('id', $centreId)->value('agency_id');
            $tz = \App\Support\AgencyTime::tz($agencyId) ?: 'America/Toronto';
            $today = \Illuminate\Support\Carbon::now($tz)->toDateString();

            $roomIds = DB::table('rooms')->where('centre_id', $centreId)->pluck('id')->all();
            if (! $roomIds) return false;   // no rooms, no rota to be off

            $mineToday = DB::table('shifts')
                ->where('user_id', $userId)
                ->whereIn('room_id', $roomIds)
                ->whereDate('starts_at', $today)
                ->exists();
            if ($mineToday) return false;

            // Does anyone run a rota here? A fortnight either side is wide enough to
            // cover a centre that rosters weekly, and narrow enough that a rota abandoned
            // months ago stops locking people out.
            $centreRuns = DB::table('shifts')
                ->whereIn('room_id', $roomIds)
                ->whereDate('starts_at', '>=', \Illuminate\Support\Carbon::now($tz)->subDays(14)->toDateString())
                ->whereDate('starts_at', '<=', \Illuminate\Support\Carbon::now($tz)->addDays(14)->toDateString())
                ->exists();

            return $centreRuns;
        } catch (\Throwable $e) {
            // Anything unexpected means we do not know — and "do not know" must never
            // stop somebody starting their shift.
            return false;
        }
    }

    private function auditClock(Request $request, int $userId, string $action, int $centreId, ?int $minutes, array $extra = []): void
    {
        try {
            $u        = $request->user();
            $name     = trim((($u->first_name ?? '') . ' ' . ($u->last_name ?? ''))) ?: ($u->name ?? ('User #' . $userId));
            $centreNm = DB::table('centres')->where('id', $centreId)->value('name') ?: ('centre #' . $centreId);
            $verb     = ['in' => 'clocked IN', 'break_start' => 'started a ' . ($extra['kind'] ?? 'break'), 'break_end' => 'came back from ' . ($extra['kind'] ?? 'break')][$action] ?? 'clocked OUT';
            $summary  = $name . ' ' . $verb . ' at ' . $centreNm;
            $payload  = [
                'summary'   => $summary,
                'event'     => 'clock_' . $action,
                'direction' => in_array($action, ['in', 'break_end'], true) ? 'in' : 'out',
                'centre_id' => $centreId,
                'centre'    => $centreNm,
                'at'        => now()->toDateTimeString(),
                'source'    => $request->input('source') ?: 'web',
            ];
            if ($minutes !== null) {
                $payload['minutes_worked'] = $minutes;
                $payload['duration'] = intdiv($minutes, 60) . 'h ' . ($minutes % 60) . 'm';
                $payload['summary'] = $summary . ($action === 'break_end' ? ' · break ' : ' · shift ') . $payload['duration'];
            }
            if ($extra) {
                $payload += $extra;
                if (! empty($extra['children_still_signed_in'])) $payload['summary'] .= ' · warned: ' . count($extra['children_still_signed_in']) . ' child(ren) still signed in';
                if (! empty($extra['from_break'])) $payload['summary'] .= ' · from ' . $extra['from_break'];
            }
            \App\Support\Audit::write([
                'user_id'     => $userId,
                'action'      => 'staff.clock_' . $action,
                'entity_type' => 'time_punch',
                'entity_id'   => null,
                'payload'     => json_encode($payload),
                'ip_address'  => substr((string) $request->ip(), 0, 45),
                'user_agent'  => substr((string) $request->userAgent(), 0, 500),
                'created_at'  => now(),
            ]);
        } catch (\Throwable $e) { /* auditing must never break the punch */ }
    }

    /**
     * Alert the centre's directors + the agency's admins when an educator clocks
     * in/out (in-app notification + high-priority FCM push). Wrapped so a failure
     * here can NEVER break the punch itself.
     */
    /* A PUNCH MUST NOT WAIT FOR GOOGLE (2026-09-17).

       Found in the audit log: POST /staff/punch, median **10.4s**, worst 10.9s across
       seven events. An educator standing at a tablet waited ten seconds to clock in.

       The cause was this method, called inline. It loops over every centre director and
       agency admin and calls FcmService::sendToUser() for each, which makes a curl POST
       to fcm.googleapis.com with a 15-SECOND timeout, and falls back to WebPushService
       (more HTTP) when the person has no handset registered. iLearn has **four** such
       recipients, so a punch made four sequential round-trips to Google before it
       answered. Four times a couple of seconds is exactly the ten seconds measured.

       NONE OF IT IS SOMETHING THE PUNCH DEPENDS ON. The clock-in is already written; this
       is a courtesy notification to somebody else. So it is queued, and the request
       returns as soon as the row is in.

       QUEUED WORK HERE IS SAFE TO RELY ON: the driver is `database` and the scheduler
       runs `queue:work --queue=mail,default --stop-when-empty` every minute (verified
       draining — a job dispatched during this investigation was picked up and gone within
       the minute). The cost is that a director sees "Safia clocked in" up to a minute
       later, which is the right trade against making Safia wait.

       The in-request part is now only the dispatch; the work itself moved to
       deliverClockEvent(), and the closure captures scalars rather than a User model. */
    private function notifyClockEvent($actor, int $centreId, string $action): void
    {
        try {
            if (! $centreId) { return; }

            $actorId = (int) ($actor->id ?? 0);
            $name = trim((($actor->first_name ?? '') . ' ' . ($actor->last_name ?? '')))
                ?: ($actor->name ?? 'An educator');

            /* Serialising scalars, not the model: a queued closure holding an Eloquent
               user re-fetches it on the worker and fails loudly if the row has changed. */
            dispatch(function () use ($actorId, $centreId, $action, $name) {
                \App\Http\Controllers\Api\CareController::deliverClockEvent($actorId, $centreId, $action, $name);
            })->onQueue('default');
        } catch (\Throwable $e) { /* never break the punch */ }
    }

    /** The part that talks to Google. Runs on the worker, never in a request. */
    public static function deliverClockEvent(int $actorId, int $centreId, string $action, string $name): void
    {
        try {
            $agencyId  = DB::table('centres')->where('id', $centreId)->value('agency_id');
            $centreNm  = DB::table('centres')->where('id', $centreId)->value('name') ?: 'the centre';
            $recipients = DB::table('role_assignments')->where('active', true)
                ->where(function ($q) use ($centreId, $agencyId) {
                    $q->where(function ($a) use ($centreId) {
                        $a->where('role', 'centre_director')->where('centre_id', $centreId);
                    });
                    if ($agencyId) {
                        $q->orWhere(function ($b) use ($agencyId) {
                            $b->where('role', 'agency_admin')->where('agency_id', $agencyId);
                        });
                    }
                })
                ->pluck('user_id')->unique();

            $inOut = ['in' => 'clocked in', 'break_lunch' => 'is on lunch', 'break_break' => 'is on a break', 'back' => 'is back from break'][$action] ?? 'clocked out';
            $icon  = ['in' => '🟢', 'back' => '🟢', 'break_lunch' => '🍽️', 'break_break' => '☕'][$action] ?? '🔴';
            $title = $icon . ' ' . $name . ' ' . $inOut;
            $body  = $centreNm;

            foreach ($recipients as $rid) {
                if ((int) $rid === $actorId) continue;   // don't notify the actor
                \App\Support\Notify::write([
                    'agency_id'  => $agencyId ? (int) $agencyId : null,   // the row named no centre, so it had no agency (2026-11-26)
                    'user_id'    => $rid,
                    'type'       => 'clock',
                    'title'      => $title,
                    'body'       => $body,
                    'data'       => json_encode(['link' => '#dashboard']),
                    'created_at' => now(),
                ]);
                try {
                    app(\App\Services\FcmService::class)->sendToUser((int) $rid, $title, $body, '#dashboard', false);
                } catch (\Throwable $e) { /* push is best-effort */ }
            }
        } catch (\Throwable $e) {
            /* On the worker now, so there is no punch left to break - but a failed
               courtesy notification must still not fail the job and retry forever. */
            report($e);
        }
    }

    public function myPunches(Request $request): JsonResponse
    {
        $uid = (int) $request->user()->id;
        /* 60 rows ran out before a month did for anybody taking breaks (each unpaid break
           is another row), so My hours under-counted the month. Since the 1st of LAST
           month, capped high. (2026-11-05) */
        $since = now()->subMonthNoOverflow()->startOfMonth()->subDay();
        $rows = DB::table('time_punches')
            ->where('user_id', $uid)
            ->where(fn ($q) => $q->where('punched_in_at', '>=', $since)->orWhereNull('punched_out_at'))
            ->orderByDesc('punched_in_at')
            ->limit(600)
            ->get();
        if ($rows->count() < 20) {
            $rows = DB::table('time_punches')->where('user_id', $uid)->orderByDesc('punched_in_at')->limit(60)->get();
        }
        $breaks = DB::table('time_punch_breaks')->where('user_id', $uid)->where('started_at', '>=', $since)
            ->orderByDesc('started_at')->limit(300)->get(['id', 'punch_id', 'return_punch_id', 'kind', 'paid', 'started_at', 'ended_at', 'ended_how']);

        return response()->json(['punches' => $rows, 'breaks' => $breaks,
            'on_break' => \App\Support\StaffBreaks::present(\App\Support\StaffBreaks::current($uid))]);
    }

    public function centrePunches(Request $request): JsonResponse
    {
        // v22p98: resolve the centre WITHIN the active agency (header-aware) so an
        // agency_admin / platform_admin (who have no direct centre_id role) still
        // see the centre's punches — the old direct-centre lookup returned [].
        $centreId = $this->resolveCentreId($request->user());
        if (!$centreId) return response()->json(['punches' => []]);
        $rows = DB::table('time_punches as t')
            ->leftJoin('users as u', 'u.id', '=', 't.user_id')
            ->where('t.centre_id', $centreId)
            ->orderByDesc('t.punched_in_at')
            ->limit(200)
            ->get([
                't.id', 't.user_id', 't.punched_in_at', 't.punched_out_at', 't.notes',
                DB::raw("COALESCE(NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), ''), u.email) as staff_name"),
            ]);
        return response()->json(['punches' => $rows]);
    }

    // ── Tour bookings (public) ──────────────────────────────────────────

    public function publicTourBook(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agency_slug' => ['required', 'string', 'max:80'],
            'centre_id' => ['required', 'integer'],
            'parent_name' => ['required', 'string', 'max:160'],
            'parent_email' => ['required', 'email', 'max:180'],
            'parent_phone' => ['nullable', 'string', 'max:40'],
            'child_age_months' => ['nullable', 'integer', 'min:0', 'max:144'],
            'preferred_start_date' => ['nullable', 'date'],
            'tour_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $agency = DB::table('agencies')
            ->where('slug', $data['agency_slug'])
            ->whereNull('deleted_at')->first();
        if (!$agency) return response()->json(['message' => 'Agency not found'], 404);

        $centre = DB::table('centres')->where('id', $data['centre_id'])
            ->where('agency_id', $agency->id)->whereNull('deleted_at')->first();
        if (!$centre) return response()->json(['message' => 'Centre not in this agency'], 422);

        $id = DB::table('tour_bookings')->insertGetId([
            'agency_id' => $agency->id,
            'centre_id' => (int) $data['centre_id'],
            'parent_name' => $data['parent_name'],
            'parent_email' => $data['parent_email'],
            'parent_phone' => $data['parent_phone'] ?? null,
            'child_age_months' => $data['child_age_months'] ?? null,
            'preferred_start_date' => $data['preferred_start_date'] ?? null,
            'tour_at' => $data['tour_at'],
            'notes' => $data['notes'] ?? null,
            'status' => 'requested',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Notify centre director + agency admins that a new tour was requested
        $staffIds = DB::table('role_assignments')->where('active', true)
            ->where(function ($q) use ($agency, $centre) {
                $q->where(function ($x) use ($agency) {
                    $x->where('role', 'agency_admin')->where('agency_id', $agency->id);
                })->orWhere(function ($x) use ($centre) {
                    $x->where('role', 'centre_director')->where('centre_id', $centre->id);
                });
            })->pluck('user_id')->all();
        if (!empty($staffIds)) {
            $rows = [];
            foreach ($staffIds as $sid) {
                $rows[] = [
                    'user_id' => $sid,
                    'type' => 'tour',
                    'title' => 'New tour booking — ' . $data['parent_name'],
                    'body' => 'Requested tour of ' . $centre->name . ' on ' . \Carbon\Carbon::parse($data['tour_at'])->format('M j, g:i A'),
                    'data' => json_encode(['url' => '/dashboard.html#tours', 'tour_id' => $id]),
                    'created_at' => now(),
                ];
            }
            \App\Support\Notify::write($rows);
        }

        return response()->json(['id' => $id, 'message' => 'Tour requested. We will be in touch shortly.'], 201);
    }

    /**
     * WHO SEES WHICH TOURS (2026-11-26, Anthony: "directors see and manage tour bookings for their OWN centres only").
     * [agency, scope]: agency/platform admins get null = the whole ACTIVE agency, exactly as before; a centre director
     * gets the centres CentreScope gives them. A booking with no centre is agency business: CentreScope::canRead() does
     * not share it, so it stays with the admins and never reaches a director of any centre. Anyone else: null agency.
     * listTours() and updateTour() both ask this one question, so the list and the write cannot disagree. Fails closed.
     *
     * @return array{0:int,1:?array}|null
     */
    private function tourScope(Request $request): ?array
    {
        $agencyId = (int) $this->resolveAgencyId($request);
        if ($agencyId <= 0) {
            return null;
        }
        $roles = \App\Support\AgencyRole::names($request);
        if (array_intersect($roles, ['agency_admin', 'platform_admin'])) {
            return [$agencyId, null];
        }
        if (! in_array('centre_director', $roles, true)) {
            return null;
        }
        return [$agencyId, \App\Support\CentreScope::ids($request, $agencyId) ?? []];
    }

    /** The bookings this caller may touch, in the active agency (CentreScope for directors; no-centre rows admin-only). */
    private function toursQuery(int $agencyId, ?array $scope, string $alias = 'tb')
    {
        $q = DB::table('tour_bookings as ' . $alias)->where($alias . '.agency_id', $agencyId);
        if ($scope !== null) {
            $q->whereIn($alias . '.centre_id', $scope ?: [0]);   // NULL centre_id never matches: admins only
        }
        return $q;
    }

    public function listTours(Request $request): JsonResponse
    {
        $s = $this->tourScope($request);
        if (! $s) {
            return response()->json(['tours' => []]);
        }
        [$agencyId, $scope] = $s;
        $rows = $this->toursQuery($agencyId, $scope)
            ->leftJoin('centres as c', 'c.id', '=', 'tb.centre_id')
            ->orderByDesc('tb.tour_at')
            ->limit(200)
            ->get(['tb.*', 'c.name as centre_name']);
        return response()->json(['tours' => $rows]);
    }

    public function updateTour(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:requested,confirmed,completed,no_show,cancelled'],
            'tour_at' => ['sometimes', 'date'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);
        // Who may touch a tour, and WHICH tours: the same tourScope() as listTours() (admins the active agency, a
        // director their own centres, nobody else). Without it ANY signed-in user - a parent included - could change
        // a booking by guessing an id. The agency comes from resolveAgencyId (header only for an agency they belong to).
        $s = $this->tourScope($request);
        abort_unless($s, 403, 'Not authorized to change tour bookings.');
        [$agencyId, $scope] = $s;

        // Scoped by agency AND the caller's centres as well as id: a booking in another tenant, at another director's
        // centre or with no centre (for a director) simply is not found, rather than being found and refused. Found
        // first, then written: update() counts CHANGED rows, so saving the same status used to answer 404.
        abort_unless($this->toursQuery($agencyId, $scope)->where('tb.id', $id)->exists(), 404, 'Tour booking not found.');
        $data['updated_at'] = now();
        $this->toursQuery($agencyId, $scope)->where('tb.id', $id)->update($data);

        return response()->json(['message' => 'Tour updated']);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function canSeeChild(Request $request, int $childId): bool
    {
        $userId = $request->user()->id;
        // v22p96: a platform_admin's access is SCOPED to the agency they've
        // switched into (X-Active-Agency-Id) — not every child on the platform.
        // The prior unconditional `return true` leaked care logs / milestones /
        // portfolios across all tenants for a switched super-admin.
        $isPlatform = \App\Support\AgencyRole::constrain(DB::table('role_assignments'))
            ->where('user_id', $userId)->where('role', 'platform_admin')
            ->where('active', true)->exists();
        if ($isPlatform) {
            $activeId = (int) $request->header('X-Active-Agency-Id');
            if (!$activeId) return false;
            return DB::table('children as c')
                ->join('families as f', 'f.id', '=', 'c.family_id')
                ->join('centres as ce', 'ce.id', '=', 'f.centre_id')
                ->where('c.id', $childId)
                ->where('ce.agency_id', $activeId)
                ->exists();
        }

        // Direct: a guardian on the child's family
        $isGuardian = DB::table('guardians as g')
            ->join('children as c', 'c.family_id', '=', 'g.family_id')
            ->where('g.user_id', $userId)
            ->where('c.id', $childId)
            ->exists();
        if ($isGuardian) return true;

        // Staff: an active STAFF role at the child's centre. A guardian role row carries its
        // family's centre_id too, and without the role filter every parent at the centre could
        // read another family's care logs and portfolio (2026-11-05 leak sweep).
        $isCentreStaff = DB::table('children as c')
            ->join('families as f', 'f.id', '=', 'c.family_id')
            ->join('role_assignments as ra', function ($j) {
                $j->on('ra.centre_id', '=', 'f.centre_id')->where('ra.active', '=', true);
            })
            ->where('c.id', $childId)
            ->where('ra.user_id', $userId)
            ->whereIn('ra.role', \App\Support\Visibility::STAFF_ROLES)
            ->exists();
        if ($isCentreStaff) return true;

        // v22p77: agency_admin whose agency owns the child's centre (admins have
        // an agency-level role_assignment, not a centre-level one).
        return DB::table('children as c')
            ->join('families as f', 'f.id', '=', 'c.family_id')
            ->join('centres as ce', 'ce.id', '=', 'f.centre_id')
            ->join('role_assignments as ra', function ($j) {
                $j->on('ra.agency_id', '=', 'ce.agency_id')->where('ra.active', '=', true);
            })
            ->where('c.id', $childId)
            ->where('ra.user_id', $userId)
            ->whereIn('ra.role', ['agency_admin'])
            ->exists();
    }

    /**
     * Minimal HDLH-flavoured milestone catalog. 4 domains × 4 age bands × a
     * handful of milestones each. Curated for MVP — operators can pad later
     * once we collect feedback.
     */
    private function catalog(): array
    {
        return [
            'infant' => [   // 0–18 mo
                'Belonging' => [
                    ['key' => 'inf_b_smile_caregivers', 'label' => 'Smiles at familiar caregivers'],
                    ['key' => 'inf_b_seeks_comfort', 'label' => 'Seeks comfort when distressed'],
                ],
                'Wellbeing' => [
                    ['key' => 'inf_w_self_feed', 'label' => 'Brings food to mouth independently'],
                    ['key' => 'inf_w_rolls', 'label' => 'Rolls from back to tummy'],
                    ['key' => 'inf_w_sits', 'label' => 'Sits unsupported'],
                ],
                'Engagement' => [
                    ['key' => 'inf_e_object_perm', 'label' => 'Looks for a partially hidden object'],
                    ['key' => 'inf_e_imitates', 'label' => 'Imitates simple gestures'],
                ],
                'Expression' => [
                    ['key' => 'inf_x_babbles', 'label' => 'Babbles consonant-vowel sounds'],
                    ['key' => 'inf_x_first_word', 'label' => 'Says first word'],
                ],
            ],
            'toddler' => [  // 18 mo – 3 yr
                'Belonging' => [
                    ['key' => 'tod_b_says_hi', 'label' => 'Greets familiar adults by name or wave'],
                    ['key' => 'tod_b_parallel_play', 'label' => 'Engages in parallel play'],
                ],
                'Wellbeing' => [
                    ['key' => 'tod_w_walks_steady', 'label' => 'Walks steadily without support'],
                    ['key' => 'tod_w_uses_cup', 'label' => 'Drinks from an open cup'],
                    ['key' => 'tod_w_hand_wash', 'label' => 'Attempts hand-washing routine'],
                ],
                'Engagement' => [
                    ['key' => 'tod_e_sorts_shapes', 'label' => 'Sorts simple shapes'],
                    ['key' => 'tod_e_pretend_play', 'label' => 'Begins simple pretend play'],
                ],
                'Expression' => [
                    ['key' => 'tod_x_2_words', 'label' => 'Combines two words to make a phrase'],
                    ['key' => 'tod_x_names_objects', 'label' => 'Names 5+ familiar objects'],
                ],
            ],
            'preschool' => [ // 3–4
                'Belonging' => [
                    ['key' => 'pre_b_cooperative_play', 'label' => 'Plays cooperatively with one peer'],
                    ['key' => 'pre_b_takes_turns', 'label' => 'Takes turns with adult prompting'],
                ],
                'Wellbeing' => [
                    ['key' => 'pre_w_uses_utensils', 'label' => 'Uses fork and spoon competently'],
                    ['key' => 'pre_w_dresses_self', 'label' => 'Dresses self with simple clothing'],
                    ['key' => 'pre_w_toilet_independent', 'label' => 'Uses the toilet independently'],
                ],
                'Engagement' => [
                    ['key' => 'pre_e_count_to_10', 'label' => 'Counts to 10'],
                    ['key' => 'pre_e_completes_puzzle', 'label' => 'Completes a 6–9 piece puzzle'],
                    ['key' => 'pre_e_sustained_attention', 'label' => 'Maintains focus on an activity for 10+ minutes'],
                ],
                'Expression' => [
                    ['key' => 'pre_x_simple_sentence', 'label' => 'Speaks in 4–5 word sentences'],
                    ['key' => 'pre_x_tells_story', 'label' => 'Retells a simple story'],
                ],
            ],
            'kindergarten' => [ // 4–6
                'Belonging' => [
                    ['key' => 'kin_b_group_play', 'label' => 'Sustains group play and follows shared rules'],
                    ['key' => 'kin_b_resolves_conflict', 'label' => 'Uses words to resolve a peer conflict'],
                ],
                'Wellbeing' => [
                    ['key' => 'kin_w_balance', 'label' => 'Balances on one foot for 10+ seconds'],
                    ['key' => 'kin_w_runs_skips', 'label' => 'Runs, skips, and jumps with control'],
                    ['key' => 'kin_w_self_care', 'label' => 'Manages self-care routine independently'],
                ],
                'Engagement' => [
                    ['key' => 'kin_e_recognize_letters', 'label' => 'Recognises most upper and lower case letters'],
                    ['key' => 'kin_e_count_20', 'label' => 'Counts objects accurately to 20'],
                    ['key' => 'kin_e_writes_name', 'label' => 'Writes own first name'],
                ],
                'Expression' => [
                    ['key' => 'kin_x_full_sentences', 'label' => 'Speaks in complete grammatical sentences'],
                    ['key' => 'kin_x_describes_events', 'label' => 'Describes a past event with detail'],
                ],
            ],
        ];
    }
}
