<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * v14: Staff scheduling + certifications.
 *
 * Schema:
 *   shifts(id, user_id, room_id, starts_at, ends_at, role enum, status enum, created_at)
 *   staff_certifications(id, user_id, cert_type enum, certifier?, issued_at?, expires_at?, document_url?, active)
 *   time_entries(id, user_id, centre_id, clocked_in_at, clocked_out_at?, total_break_min, notes?, shift_id?)
 */
final class SchedulingController extends Controller
{
    // resolveAgencyId — header-aware, so an admin gets the agency they switched into.
    use \App\Http\Concerns\ResolvesCentreContext;

    /**
     * GET /api/v1/director/schedule?centre_id=X&week_starting=YYYY-MM-DD
     * Weekly view of all shifts for the centre.
     */
    public function week(Request $request): JsonResponse
    {
        $centreId = (int) $request->input('centre_id');
        $week = $request->input('week_starting');
        if (! $week) {
            /* Sunday, matching the calendars. Only the DEFAULT: week_starting is honoured
               whenever it is given, so the grid decides its own range and this just
               chooses where an unparameterised call lands. */
            $week = Carbon::now()->startOfWeek(Carbon::SUNDAY)->toDateString();
        }

        if (! $this->hasCentreAccess($request->user()->id, $centreId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $weekStart = Carbon::parse($week)->startOfDay();
        $weekEnd   = $weekStart->copy()->addDays(7);

        $rooms = DB::table('rooms')->where('centre_id', $centreId)->pluck('id')->all();

        $shifts = DB::table('shifts')
            ->whereIn('room_id', $rooms)
            ->where('starts_at', '>=', $weekStart->toDateTimeString())
            ->where('starts_at', '<', $weekEnd->toDateTimeString())
            ->orderBy('starts_at')
            ->get();

        $userIds = $shifts->pluck('user_id')->unique()->all();
        $users = !empty($userIds)
            ? DB::table('users')->whereIn('id', $userIds)->get()->keyBy('id')
            : collect();
        $roomMeta = DB::table('rooms')->whereIn('id', $rooms)->get()->keyBy('id');

        // Group by day
        /* A day the centre is shut must not look like a day nobody has been rostered on
           yet -- they are the same empty grid otherwise, and only one of them is a
           problem to fix. */
        $centre = DB::table('centres')->where('id', $centreId)->first();
        $closures = $this->closureMapFor($centreId, $weekStart, $weekStart->copy()->addDays(6));
        $openDays = $this->openDaysFor($centre);

        $byDay = [];
        for ($i = 0; $i < 7; $i++) {
            $d = $weekStart->copy()->addDays($i);
            $key = $d->toDateString();
            $byDay[$key] = [
                'day_name' => $d->format('l'),
                'shifts' => [],
                'closure' => $closures[$key] ?? null,
                'is_open_day' => in_array((int) $d->isoWeekday(), $openDays, true),
            ];
        }

        foreach ($shifts as $s) {
            $u = $users[$s->user_id] ?? null;
            $r = $roomMeta[$s->room_id] ?? null;
            $startDate = Carbon::parse($s->starts_at)->toDateString();
            if (! isset($byDay[$startDate])) continue;
            $byDay[$startDate]['shifts'][] = [
                'id' => $s->id,
                'user_id' => $s->user_id,
                'user_name' => $u ? trim($u->first_name . ' ' . $u->last_name) : 'Unknown',
                'room_id' => $s->room_id,
                'room_name' => $r->name ?? 'Room',
                'starts_at' => $s->starts_at,
                'ends_at' => $s->ends_at,
                'starts_hm' => Carbon::parse($s->starts_at)->format('H:i'),
                'ends_hm' => Carbon::parse($s->ends_at)->format('H:i'),
                'role' => $s->role,
                'status' => $s->status,
            ];
        }

        return response()->json([
            'centre_id' => $centreId,
            'week_starting' => $weekStart->toDateString(),
            'days' => $byDay,
            'total_shifts' => $shifts->count(),
            // The hours autofill would use, so the picker can state them up front rather
            // than the user finding out from what got written.
            'open_time' => $centre->open_time ?? null,
            'close_time' => $centre->close_time ?? null,
            'open_days' => $openDays,
        ]);
    }

    /**
     * v22p37 — GET /api/v1/director/schedule/range?centre_id=X&start=YYYY-MM-DD&end=YYYY-MM-DD
     * Arbitrary date range — used by the calendar (week + month views).
     * Returns shifts grouped by ISO date so the frontend can paint cells.
     */
    /**
     * Every centre in the agency the caller is working in.
     *
     * Scoped to the ACTIVE agency, never to "all centres this user can see" -- a platform
     * admin can see every tenant, and an all-staff grid that quietly spans two agencies is
     * the leak this codebase keeps re-learning about.
     */
    private function agencyCentreIds(Request $request): array
    {
        $agencyId = (int) ($request->header('X-Active-Agency-Id') ?: 0);
        if ($agencyId <= 0) {
            $agencyId = (int) DB::table('role_assignments')
                ->where('user_id', $request->user()->id)->where('active', true)
                ->whereNotNull('agency_id')->value('agency_id');
        }
        if ($agencyId <= 0) {
            return [];
        }

        $ids = DB::table('centres')->where('agency_id', $agencyId)->whereNull('deleted_at')
            ->pluck('id')->map(fn ($i) => (int) $i)->all();

        // Only the ones this caller may actually open. hasCentreAccess() is the single
        // guard everything else in this controller uses; reuse it rather than re-deriving.
        return array_values(array_filter($ids, fn ($id) => $this->hasCentreAccess($request->user()->id, $id)));
    }

    public function range(Request $request): JsonResponse
    {
        $raw = (string) $request->input('centre_id');
        $allCentres = ($raw === 'all');
        $centreId = (int) $request->input('centre_id');
        $start = Carbon::parse($request->input('start', Carbon::now()->startOfMonth()->toDateString()))->startOfDay();
        $end   = Carbon::parse($request->input('end',   Carbon::now()->endOfMonth()->toDateString()))->endOfDay();
        // Hard cap at 100 days to keep the response bounded
        if ($start->diffInDays($end) > 100) {
            $end = $start->copy()->addDays(100);
        }
        $centreIds = [];
        if ($allCentres) {
            $centreIds = $this->agencyCentreIds($request);
            if (empty($centreIds)) {
                return response()->json(['message' => 'No centres you can see.'], 403);
            }
        } else {
            if (! $this->hasCentreAccess($request->user()->id, $centreId)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            $centreIds = [$centreId];
        }

        $rooms = DB::table('rooms')->whereIn('centre_id', $centreIds)->pluck('id')->all();
        if (empty($rooms)) {
            return response()->json(['centre_id' => $centreId, 'start' => $start->toDateString(), 'end' => $end->toDateString(), 'days' => new \stdClass(), 'total_shifts' => 0]);
        }
        $shifts = DB::table('shifts')
            ->whereIn('room_id', $rooms)
            ->where('starts_at', '>=', $start->toDateTimeString())
            ->where('starts_at', '<=', $end->toDateTimeString())
            ->orderBy('starts_at')
            ->get();
        $userIds = $shifts->pluck('user_id')->unique()->all();
        $users = !empty($userIds) ? DB::table('users')->whereIn('id', $userIds)->get()->keyBy('id') : collect();
        $roomMeta = DB::table('rooms')->whereIn('id', $rooms)->get()->keyBy('id');

        /* Across several centres a single day is not simply open or shut -- one provider
           can be on holiday while the rest are working. So the all-centres view merges:
           the day is an open day if ANY centre is open, and carries the list of centres
           that are closed rather than one closure standing for all of them. */
        $centreRows = DB::table('centres')->whereIn('id', $centreIds)->get()->keyBy('id');
        $centre = $centreRows[$centreId] ?? $centreRows->first();

        $closures = [];
        $closuresByCentre = [];
        $openDays = [];
        foreach ($centreIds as $cid) {
            $row = $centreRows[$cid] ?? null;
            if (! $row) { continue; }
            $openDays = array_values(array_unique(array_merge($openDays, $this->openDaysFor($row))));
            foreach ($this->closureMapFor($cid, $start, $end) as $date => $cl) {
                $cl['centre_id'] = $cid;
                $cl['centre_name'] = $row->name;
                $closuresByCentre[$date][] = $cl;
                if (! isset($closures[$date])) { $closures[$date] = $cl; }
            }
        }
        sort($openDays);

        $byDay = [];
        $cursor = $start->copy();
        while ($cursor <= $end) {
            $key = $cursor->toDateString();
            $byDay[$key] = [
                'day_name' => $cursor->format('l'),
                'shifts' => [],
                'closure' => $closures[$key] ?? null,
                'closures' => $closuresByCentre[$key] ?? [],
                'is_open_day' => in_array((int) $cursor->isoWeekday(), $openDays, true),
            ];
            $cursor->addDay();
        }
        foreach ($shifts as $s) {
            $u = $users[$s->user_id] ?? null;
            $r = $roomMeta[$s->room_id] ?? null;
            $date = Carbon::parse($s->starts_at)->toDateString();
            if (!isset($byDay[$date])) continue;
            $byDay[$date]['shifts'][] = [
                'id' => $s->id,
                'user_id' => $s->user_id,
                'user_name' => $u ? trim($u->first_name . ' ' . $u->last_name) : 'Unknown',
                'room_id' => $s->room_id,
                'room_name' => $r->name ?? 'Room',
                'starts_at' => $s->starts_at,
                'ends_at' => $s->ends_at,
                'starts_hm' => Carbon::parse($s->starts_at)->format('H:i'),
                'ends_hm' => Carbon::parse($s->ends_at)->format('H:i'),
                'role' => $s->role,
                'status' => $s->status,
                // Which centre this shift belongs to -- the all-staff grid groups by it.
                'centre_id' => $r->centre_id ?? null,
                'centre_name' => $r ? ($centreRows[$r->centre_id]->name ?? null) : null,
            ];
        }

        // v22p37: bundle rooms so the calendar's New shift modal can populate
        // the room dropdown without a separate /director/rooms call (that
        // endpoint scopes to the caller's primary centre, breaking the
        // agency_admin picker).
        $roomsList = DB::table('rooms')
            ->whereIn('centre_id', $centreIds)
            ->orderBy('age_min_months')
            ->get(['id', 'name', 'age_group', 'centre_id']);

        return response()->json([
            'centre_id' => $allCentres ? 'all' : $centreId,
            'centre_ids' => $centreIds,
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'days' => $byDay,
            'rooms' => $roomsList,
            'total_shifts' => $shifts->count(),
        ]);
    }

    /**
     * POST /api/v1/director/schedule/shift
     */
    public function createShift(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'room_id' => ['required', 'integer'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'role' => ['nullable', 'in:lead,support,floater,volunteer'],
        ]);

        $room = DB::table('rooms')->where('id', $data['room_id'])->first();
        if (! $room) return response()->json(['message' => 'Room not found'], 404);
        if (! $this->hasCentreAccess($request->user()->id, $room->centre_id)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $shiftId = DB::table('shifts')->insertGetId([
            'user_id' => $data['user_id'],
            'room_id' => $data['room_id'],
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'role' => $data['role'] ?? 'support',
            'status' => 'scheduled',
            'created_at' => now(),
        ]);

        \App\Support\Audit::write([
            'user_id' => $request->user()->id,
            'action' => 'shift.created',
            'entity_type' => 'shift',
            'entity_id' => $shiftId,
            'payload' => json_encode($data),
            'created_at' => now(),
        ]);

        return response()->json(['success' => true, 'shift_id' => $shiftId], 201);
    }

    /**
     * The agency this caller is working in, for an agency-wide setting.
     *
     * Deliberately separate from hasCentreAccess(): that answers "may you open this
     * centre", which is not the same question as "may you change something for all of
     * them".
     */
    private function settingsAgencyId(Request $request): ?int
    {
        $uid = (int) $request->user()->id;
        $header = (int) $request->header('X-Active-Agency-Id');

        $isPlatform = DB::table('role_assignments')->where('user_id', $uid)
            ->where('role', 'platform_admin')->where('active', true)->exists();
        if ($isPlatform && $header > 0) {
            return $header;
        }

        $q = DB::table('role_assignments')->where('user_id', $uid)->where('active', true)
            ->whereIn('role', ['agency_admin', 'platform_admin', 'centre_director'])
            ->whereNotNull('agency_id');
        if ($header > 0) {
            $q->where('agency_id', $header);
        }
        $id = (int) $q->value('agency_id');
        if ($id) {
            return $id;
        }

        /* A centre_director's row usually carries centre_id and NO agency_id, so the
           query above finds nothing for them and they were told "no agency access" —
           when the intent is that they SEE this setting and cannot change it. Same trap
           MailOversight hit. Resolve through the centre instead. */
        $viaCentre = DB::table('role_assignments as ra')
            ->join('centres as c', 'c.id', '=', 'ra.centre_id')
            ->where('ra.user_id', $uid)->where('ra.active', true)
            ->whereIn('ra.role', ['agency_admin', 'platform_admin', 'centre_director'])
            ->when($header > 0, fn ($qq) => $qq->where('c.agency_id', $header))
            ->value('c.agency_id');

        return $viaCentre ? (int) $viaCentre : null;
    }

    /** Only an agency admin may set something that rosters every centre in the agency. */
    private function mayChangeAgencySettings(Request $request, int $agencyId): bool
    {
        return DB::table('role_assignments')->where('user_id', $request->user()->id)
            ->where('active', true)
            ->whereIn('role', ['agency_admin', 'platform_admin'])
            ->where(function ($q) use ($agencyId) {
                $q->where('agency_id', $agencyId)->orWhere('role', 'platform_admin');
            })
            ->exists();
    }

    /** GET /api/v1/director/schedule/settings */
    public function scheduleSettings(Request $request): JsonResponse
    {
        $agencyId = $this->settingsAgencyId($request);
        if (! $agencyId) {
            return response()->json(['message' => 'No agency access'], 403);
        }

        $s = json_decode((string) DB::table('agencies')->where('id', $agencyId)->value('settings'), true);
        $s = is_array($s) ? $s : [];

        /* How far ahead the last run reached, per centre. Shown so the switch can say
           what it has actually done rather than only what it is set to — "on" and "on and
           working" are different things, and the difference is what people ask about. */
        $through = [];
        foreach (DB::table('centres')->where('agency_id', $agencyId)->whereNull('deleted_at')
            ->get(['id', 'name', 'settings']) as $c) {
            $cs = json_decode((string) $c->settings, true);
            if (is_array($cs) && ! empty($cs['schedule_autofill_through'])) {
                $through[] = ['centre' => $c->name, 'through' => $cs['schedule_autofill_through']];
            }
        }

        return response()->json([
            'agency_id' => $agencyId,
            'auto_nightly' => (bool) ($s['schedule_autofill'] ?? false),
            'may_change' => $this->mayChangeAgencySettings($request, $agencyId),
            'days_ahead' => 28,
            'runs_at' => '04:30',
            'filled_through' => $through,
        ]);
    }

    /** PATCH /api/v1/director/schedule/settings */
    public function saveScheduleSettings(Request $request): JsonResponse
    {
        $agencyId = $this->settingsAgencyId($request);
        if (! $agencyId) {
            return response()->json(['message' => 'No agency access'], 403);
        }
        if (! $this->mayChangeAgencySettings($request, $agencyId)) {
            return response()->json([
                'message' => 'Only an agency admin can change nightly scheduling for every centre.',
            ], 403);
        }

        $data = $request->validate(['auto_nightly' => ['required', 'boolean']]);

        $s = json_decode((string) DB::table('agencies')->where('id', $agencyId)->value('settings'), true);
        $s = is_array($s) ? $s : [];
        $was = (bool) ($s['schedule_autofill'] ?? false);
        $s['schedule_autofill'] = (bool) $data['auto_nightly'];

        DB::table('agencies')->where('id', $agencyId)
            ->update(['settings' => json_encode($s), 'updated_at' => now()]);

        // Turning this on writes real shifts, so it is worth a named audit row.
        \App\Support\Audit::write([
            'user_id' => $request->user()->id,
            'agency_id' => $agencyId,
            'action' => 'schedule.autofill_' . ($data['auto_nightly'] ? 'enabled' : 'disabled'),
            'entity_type' => 'agency',
            'entity_id' => $agencyId,
            'payload' => json_encode(['was' => $was, 'now' => (bool) $data['auto_nightly']]),
            'created_at' => now(),
        ]);

        return response()->json([
            'ok' => true,
            'auto_nightly' => (bool) $data['auto_nightly'],
            'message' => $data['auto_nightly']
                ? 'On — every centre will be filled 28 days ahead each night at 04:30.'
                : 'Off — no shifts will be created automatically.',
        ]);
    }

    /**
     * POST /api/v1/director/schedule/autofill
     *
     * Fill a date range from the centre's own hours: a shift on every open day, nothing
     * on a closure day, nothing on a day already rostered for that person.
     *
     * Body: centre_id, start, end, user_ids[] (optional -- defaults to the centre's
     * educators), room_id (optional -- defaults to each educator's room), role.
     */
    public function autofill(Request $request): JsonResponse
    {
        $data = $request->validate([
            'centre_id' => ['required', 'integer'],
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after_or_equal:start'],
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer'],
            'room_id' => ['nullable', 'integer'],
            'role' => ['nullable', 'in:lead,support,floater,volunteer'],
        ]);

        $centreId = (int) $data['centre_id'];
        if (! $this->hasCentreAccess($request->user()->id, $centreId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $centre = DB::table('centres')->where('id', $centreId)->whereNull('deleted_at')->first();
        if (! $centre) {
            return response()->json(['message' => 'Centre not found'], 404);
        }
        if (empty($centre->open_time) || empty($centre->close_time)) {
            return response()->json([
                'message' => 'This centre has no opening hours set. Add an open and close time first, then autofill.',
            ], 422);
        }

        $start = Carbon::parse($data['start'])->startOfDay();
        $end   = Carbon::parse($data['end'])->startOfDay();
        // Same bound as range(): a year of shifts per click is not a thing anyone means.
        if ($start->diffInDays($end) > 100) {
            $end = $start->copy()->addDays(100);
        }

        $openDays = $this->openDaysFor($centre);
        $closures = $this->closureMapFor($centreId, $start, $end);

        $centreRooms = DB::table('rooms')->where('centre_id', $centreId)->orderBy('id')->get(['id', 'name']);
        if ($centreRooms->isEmpty()) {
            return response()->json(['message' => 'This centre has no rooms, so there is nothing to roster into.'], 422);
        }
        $roomIds = $centreRooms->pluck('id')->all();

        $roomId = $data['room_id'] ?? null;
        if ($roomId !== null && ! in_array((int) $roomId, array_map('intval', $roomIds), true)) {
            return response()->json(['message' => 'That room is not in this centre.'], 422);
        }

        /* Who to roster. An explicit list wins. Failing that, the centre's DESIGNATED
           PROVIDER -- not "every educator here", which is what this used to do and what
           put three people on Amna Ahsan's rota when two of them were agency staff filed
           at her centre by mistake. Only when no provider can be identified does it fall
           back to the centre's educators, which is the right answer for a real multi-room
           centre. */
        $userIds = array_values(array_filter(array_map('intval', $data['user_ids'] ?? [])));
        $defaulted = false;
        if (! $userIds) {
            $defaulted = true;
            $provider = $this->designatedProviderFor($centre);
            $userIds = $provider !== null ? [$provider] : $this->centreEducators($centreId);
        }
        if (! $userIds) {
            return response()->json(['message' => 'No active staff are assigned to this centre.'], 422);
        }

        /* An explicit list must still be staff of THIS agency. Without this check a
           caller could roster somebody from another tenant into this centre's room, and
           their name would then appear on a rota they have nothing to do with. */
        $allowed = DB::table('role_assignments as ra')
            ->join('users as u', 'u.id', '=', 'ra.user_id')
            ->whereIn('ra.user_id', $userIds)
            ->where('ra.active', true)
            ->whereIn('ra.role', self::STAFF_ROLES)
            ->where(function ($q) use ($centreId, $centre) {
                $q->where('ra.centre_id', $centreId)->orWhere('ra.agency_id', $centre->agency_id);
            })
            ->whereNull('u.deleted_at')
            ->whereNotIn('u.status', \App\Support\AccountStatus::CLOSED)
            ->distinct()->pluck('u.id')->map(fn ($i) => (int) $i)->all();

        $rejected = array_values(array_diff($userIds, $allowed));
        $userIds = array_values(array_intersect($userIds, $allowed));
        if (! $userIds) {
            return response()->json([
                'message' => 'None of those people are active staff of this agency.',
            ], 422);
        }

        // Each educator's own room, so a multi-room centre does not put everyone in one.
        $ownRoom = DB::table('educator_rooms')
            ->whereIn('user_id', $userIds)
            ->whereIn('room_id', $roomIds)
            ->get()->groupBy('user_id');

        /* Every existing shift in the window, keyed user|date. One query rather than one
           per day per person, and it is what makes re-running this safe. */
        $existing = [];
        foreach (DB::table('shifts')
            ->whereIn('room_id', $roomIds)
            ->whereIn('user_id', $userIds)
            ->where('starts_at', '>=', $start->toDateTimeString())
            ->where('starts_at', '<=', $end->copy()->endOfDay()->toDateTimeString())
            ->get(['user_id', 'starts_at']) as $row) {
            $existing[$row->user_id . '|' . Carbon::parse($row->starts_at)->toDateString()] = true;
        }

        $open  = substr((string) $centre->open_time, 0, 8);
        $close = substr((string) $centre->close_time, 0, 8);

        $rows = [];
        $skipClosed = 0;
        $skipExisting = 0;
        $skipShut = 0;
        $now = now();

        foreach ($userIds as $uid) {
            $uRoom = $roomId ?: (optional($ownRoom->get($uid))->first()->room_id ?? $roomIds[0]);
            for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
                $date = $d->toDateString();
                if (! in_array((int) $d->isoWeekday(), $openDays, true)) { $skipShut++; continue; }
                if (isset($closures[$date])) { $skipClosed++; continue; }
                if (isset($existing[$uid . '|' . $date])) { $skipExisting++; continue; }
                $rows[] = [
                    'user_id' => $uid,
                    'room_id' => $uRoom,
                    'starts_at' => $date . ' ' . $open,
                    'ends_at' => $date . ' ' . $close,
                    'role' => $data['role'] ?? 'lead',
                    'status' => 'scheduled',
                    'created_at' => $now,
                ];
            }
        }

        if ($rows) {
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('shifts')->insert($chunk);
            }
        }

        \App\Support\Audit::write([
            'user_id' => $request->user()->id,
            'action' => 'shift.autofilled',
            'entity_type' => 'centre',
            'entity_id' => $centreId,
            /* Named, not counted: a later question is "who got put on the rota and for
               which days", and a bare total cannot answer it. */
            'payload' => json_encode([
                'centre' => $centre->name,
                'range' => $start->toDateString() . ' to ' . $end->toDateString(),
                'hours' => $open . '-' . $close,
                'open_days' => $openDays,
                'educators' => DB::table('users')->whereIn('id', $userIds)
                    ->get(['id', 'first_name', 'last_name'])
                    ->map(fn ($u) => trim($u->first_name . ' ' . $u->last_name))->all(),
                'created' => count($rows),
                'dates' => array_values(array_unique(array_map(
                    fn ($r) => substr($r['starts_at'], 0, 10), $rows))),
                'skipped_closed' => $skipClosed,
                'skipped_existing' => $skipExisting,
            ]),
            'created_at' => $now,
        ]);

        return response()->json([
            'success' => true,
            'created' => count($rows),
            'hours' => ['open' => $open, 'close' => $close],
            'open_days' => $openDays,
            'rostered' => DB::table('users')->whereIn('id', $userIds)
                ->get(['id', 'first_name', 'last_name'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => trim($u->first_name . ' ' . $u->last_name)])->values(),
            'used_default' => $defaulted,
            'rejected' => $rejected,
            'skipped' => [
                'closed' => $skipClosed,
                'already_rostered' => $skipExisting,
                'not_an_open_day' => $skipShut,
            ],
            'message' => count($rows)
                ? (count($rows) . ' shift' . (count($rows) === 1 ? '' : 's') . ' added from ' . $open . '-' . $close . '.'
                   . ($skipClosed ? ' ' . $skipClosed . ' closure day' . ($skipClosed === 1 ? '' : 's') . ' left clear.' : '')
                   . ($skipExisting ? ' ' . $skipExisting . ' day' . ($skipExisting === 1 ? '' : 's') . ' already rostered.' : ''))
                : 'Nothing to add - every open day in that range is already rostered or closed.',
        ]);
    }

    /**
     * PATCH /api/v1/director/schedule/shift/{id}
     */
    public function updateShift(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'role' => ['nullable', 'in:lead,support,floater,volunteer'],
            'status' => ['nullable', 'in:scheduled,active,completed,cancelled'],
            'user_id' => ['nullable', 'integer'],
            'room_id' => ['nullable', 'integer'],
        ]);

        $shift = DB::table('shifts')->where('id', $id)->first();
        if (! $shift) return response()->json(['message' => 'Not found'], 404);

        $room = DB::table('rooms')->where('id', $shift->room_id)->first();
        if (! $this->hasCentreAccess($request->user()->id, $room->centre_id)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $update = array_filter($data, fn($v) => $v !== null);
        if (!empty($update)) {
            DB::table('shifts')->where('id', $id)->update($update);
        }

        return response()->json(['success' => true]);
    }

    /**
     * DELETE /api/v1/director/schedule/shift/{id}
     */
    public function deleteShift(Request $request, int $id): JsonResponse
    {
        $shift = DB::table('shifts')->where('id', $id)->first();
        if (! $shift) return response()->json(['message' => 'Not found'], 404);
        $room = DB::table('rooms')->where('id', $shift->room_id)->first();
        if (! $this->hasCentreAccess($request->user()->id, $room->centre_id)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        DB::table('shifts')->where('id', $id)->delete();

        return response()->json(['success' => true]);
    }

    /**
     * GET /api/v1/director/schedule/staff?centre_id=X
     * List staff (users) eligible to be scheduled — anyone with educator/centre_director
     * role at this centre.
     */
    public function staffList(Request $request): JsonResponse
    {
        /* centre_id=all is the calendar's whole-agency view. Cast to (int) that is 0, and
           hasCentreAccess(user, 0) is false -- so without this branch the staff picker
           403'd precisely when the user asked to see everybody. */
        $allCentres = ((string) $request->input('centre_id')) === 'all';
        $centreId = (int) $request->input('centre_id');
        $centreIds = [];

        if ($allCentres) {
            $centreIds = $this->agencyCentreIds($request);
            if (empty($centreIds)) {
                return response()->json(['message' => 'No centres you can see.'], 403);
            }
            $centreId = $centreIds[0];
        } else {
            if (! $this->hasCentreAccess($request->user()->id, $centreId)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            $centreIds = [$centreId];
        }

        $centre = DB::table('centres')->where('id', $centreId)->first();
        if (! $centre) {
            return response()->json(['message' => 'Centre not found'], 404);
        }

        /* Every staff role, not just educators and directors. A home visitor doing a
           scheduled round, an admin covering a floor, an auditor on site -- all of them
           are people whose time belongs on a rota, and none of them could be put on one
           while this list was two roles wide. Guardians are the only role deliberately
           absent: they are not staff. */
        $rows = DB::table('users')
            ->join('role_assignments', 'role_assignments.user_id', '=', 'users.id')
            ->where(function ($q) use ($centreIds, $centre) {
                $q->whereIn('role_assignments.centre_id', $centreIds)
                  ->orWhere('role_assignments.agency_id', $centre->agency_id);
            })
            ->whereIn('role_assignments.role', self::STAFF_ROLES)
            ->where('role_assignments.active', true)
            ->whereNotIn('users.status', \App\Support\AccountStatus::CLOSED)
            ->whereNull('users.deleted_at')
            /* Integration and no-reply inboxes hold real roles, so they match everything
               above — but they are not people and must not be offered as somebody to put
               on a rota. Character-for-character the exclusion listUsers uses; do NOT
               widen it to a domain, because @ilearnhcc.com holds five real staff. */
            ->where(function ($q) {
                $q->whereNull('users.email')
                  ->orWhere(function ($w) {
                      $w->where('users.email', 'not like', '%integration+%')
                        ->where('users.email', 'not like', 'noreply@%')
                        ->where('users.email', 'not like', 'no-reply@%');
                  });
            })
            ->select(
                'users.id', 'users.first_name', 'users.last_name', 'users.email', 'users.photo_url',
                'role_assignments.role', 'role_assignments.centre_id'
            )
            ->get();

        /* One row per person, not one per role assignment -- somebody who is an educator
           at this centre AND an admin agency-wide appeared twice before. The centre role
           wins the label, because that is the hat they wear here. */
        $byUser = [];
        foreach ($rows as $r) {
            $here = in_array((int) $r->centre_id, $centreIds, true);
            if (! isset($byUser[$r->id])) {
                $byUser[$r->id] = [
                    'id' => $r->id,
                    'name' => trim($r->first_name . ' ' . $r->last_name),
                    'email' => $r->email,
                    'photo_url' => $r->photo_url,
                    'role' => $r->role,
                    'roles' => [],
                    'at_this_centre' => $here,
                ];
            }
            if (! in_array($r->role, $byUser[$r->id]['roles'], true)) {
                $byUser[$r->id]['roles'][] = $r->role;
            }
            if ($here) {
                $byUser[$r->id]['at_this_centre'] = true;
                $byUser[$r->id]['role'] = $r->role;
            }
        }

        $provider = $this->designatedProviderFor($centre);
        foreach ($byUser as $id => $row) {
            $byUser[$id]['is_provider'] = ($provider !== null && (int) $id === (int) $provider);
        }

        /* This centre's own people first, then the provider, then by name -- the picker
           is scanned top-down and the person you almost always want is at the top. */
        $staff = collect(array_values($byUser))->sortBy([
            fn ($a, $b) => ($b['at_this_centre'] <=> $a['at_this_centre']),
            fn ($a, $b) => ($b['is_provider'] <=> $a['is_provider']),
            fn ($a, $b) => strcasecmp($a['name'], $b['name']),
        ])->values();

        return response()->json([
            'staff' => $staff,
            'provider_id' => $provider,
        ]);
    }

    /**
     * GET /api/v1/director/timesheets?centre_id=X&from=&to=
     * Export-ready timesheet rows. CSV is generated client-side from this JSON.
     */
    /** Centres this person can pull a timesheet for, when they did not name one. */
    private function timesheetCentreIds(Request $request): array
    {
        $uid = (int) $request->user()->id;
        $own = DB::table('role_assignments')->where('user_id', $uid)->where('active', true)
            ->whereNotNull('centre_id')->pluck('centre_id')->map(fn ($i) => (int) $i)->all();
        if ($own) {
            return $own;                       // a director sees their own centre(s)
        }

        // Agency-level roles have no centre of their own, so they get the agency's.
        $agencyId = $this->resolveAgencyId($request);
        if (! $agencyId) {
            return [];
        }
        $isWide = DB::table('role_assignments')->where('user_id', $uid)->where('active', true)
            ->whereIn('role', ['agency_admin', 'platform_admin'])->exists();

        return $isWide
            ? DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->map(fn ($i) => (int) $i)->all()
            : [];
    }

    public function timesheets(Request $request): JsonResponse
    {
        $centreId = (int) $request->input('centre_id');
        $from = $request->input('from', Carbon::now()->startOfMonth()->toDateString());
        $to = $request->input('to', Carbon::now()->toDateString());

        // No centre asked for? Use the ones this person can see.
        //
        // An agency admin has no centre_id on any role row — the role is agency-level — so
        // the screen had no centre to send, sent nothing, and got a 403. The timesheet
        // screen has been empty for every admin since it shipped, while directors, who do
        // have a centre, saw theirs and nothing looked wrong.
        $centreIds = [];
        if ($centreId) {
            if (! $this->hasCentreAccess($request->user()->id, $centreId)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            $centreIds = [$centreId];
        } else {
            $centreIds = $this->timesheetCentreIds($request);
            if (! $centreIds) {
                return response()->json(['message' => 'No centres you can see.'], 403);
            }
        }

        // time_punches, not time_entries. The clock was consolidated onto /staff/punch
        // and nothing has written time_entries since 23 July — the StaffController
        // /clock-in and /clock-out endpoints that fed it have no callers left in the
        // app. This report was returning an empty month with no error while the hours
        // sat in the other table.
        $entries = DB::table('time_punches')
            ->join('users', 'users.id', '=', 'time_punches.user_id')
            ->whereIn('time_punches.centre_id', $centreIds)
            ->where('time_punches.punched_in_at', '>=', Carbon::parse($from)->startOfDay())
            ->where('time_punches.punched_in_at', '<=', Carbon::parse($to)->endOfDay())
            // Open punches are INCLUDED now. Dropping them hid 22 shifts, and a shift with
            // no clock-out is precisely the one payroll needs to see before it is paid —
            // silently omitting it makes the sheet look complete when it is short.

            ->orderBy('users.last_name')
            ->orderBy('time_punches.punched_in_at')
            ->select(
                'time_punches.*',
                'users.first_name',
                'users.last_name',
                'users.email'
            )
            ->get();

        // The agency's zone. time_punches stores UTC, and this formatted it raw — so
        // every clock-in on the sheet read four hours late: a 07:02 start showed as 11:02.
        // Payroll was being read off UTC.
        $sheetTz = \App\Support\AgencyTime::tzForCentre($centreIds[0] ?? null);

        $rows = $entries->map(function ($e) use ($sheetTz) {
            $in = Carbon::parse($e->punched_in_at)->timezone($sheetTz);
            // Carbon::parse(null) returns NOW, which would quietly present a shift nobody
            // has clocked out of as a finished one, with hours, and pay it. An open punch
            // is reported as open with no hours — it is a thing to chase, not to total.
            $isOpen = empty($e->punched_out_at);
            $out = $isOpen ? null : Carbon::parse($e->punched_out_at)->timezone($sheetTz);
            // time_punches has no total_break_min — breaks were a feature of the old
            // clock and the current one does not record them. Reported as 0 rather than
            // silently omitted, so the hours are not presented as break-adjusted when
            // nothing was deducted.
            $breakMin = (int) ($e->total_break_min ?? 0);
            // abs(): Carbon 3 diffInMinutes is signed, and $out->diffInMinutes($in)
            // yields a NEGATIVE value here (in precedes out) → every row was 0h.
            $minutes = $isOpen ? 0 : abs($out->diffInMinutes($in)) - $breakMin;
            return [
                // The centre's date, not UTC's. An evening shift is stamped after midnight
                // UTC and would otherwise be filed on the following day.
                'date' => $in->toDateString(),
                // Surfaced so the screen can mark it rather than showing a silent 0h.
                'open' => $isOpen,
                'status' => $isOpen ? 'Open' : 'Complete',
                'staff_name' => trim($e->first_name . ' ' . $e->last_name),
                'staff_email' => $e->email,
                'clock_in' => $in->format('H:i'),
                // Em dash rather than a time: there is no clock-out to show yet.
                'clock_out' => $isOpen ? '—' : $out->format('H:i'),
                // Display forms. Raw clock_in/clock_out above stay untouched — the CSV
                // export and the sort read them, and a display format is not a data format.
                // Each punch carries its own short date so a shift crossing midnight reads
                // correctly: 20:00 in on the 17th, 00:45 out on the 18th, plainly.
                'date_label' => $in->format('D, M j'),
                'clock_in_label' => $in->format('M j, g:i A'),
                'clock_out_label' => $isOpen ? '—' : $out->format('M j, g:i A'),
                'break_min' => $breakMin,
                'worked_min' => max(0, $minutes),
                'worked_hours' => round(max(0, $minutes) / 60, 2),
                'notes' => $e->notes,
            ];
        });

        // Everybody who should be ON a timesheet, not only everybody who clocked.
        //
        // Built from punches alone, somebody who never clocked in did not appear at all, so
        // "logged no hours" and "not on the list" looked the same. A zero row is a question
        // to ask; a missing row is invisible. Parents are excluded — they have no shifts.
        $seen = collect($rows)->pluck('staff_email')->filter()->unique()->all();
        $roleLabel = ['agency_admin' => 'Admin', 'platform_admin' => 'Admin', 'centre_director' => 'Director',
            'educator' => 'Educator', 'home_visitor' => 'Home visitor', 'auditor' => 'Auditor'];
        $agencyIds = DB::table('centres')->whereIn('id', $centreIds)->pluck('agency_id')->unique()->filter()->all();
        $missing = DB::table('role_assignments as ra')
            ->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.active', true)
            ->where('ra.role', '!=', 'guardian')
            ->whereNull('u.deleted_at')
            // Agency-level roles (an admin has no centre_id) are only pulled in when the
            // whole agency is being asked for. Naming ONE centre and still listing every
            // agency-level person put the entire agency's staff on a single provider's
            // sheet at 0h — noise that buries the people who actually work there.
            ->where(function ($q) use ($centreIds, $agencyIds, $centreId) {
                $q->whereIn('ra.centre_id', $centreIds);
                if ($agencyIds && ! $centreId) {
                    $q->orWhereIn('ra.agency_id', $agencyIds);
                }
            })
            ->when($seen, fn ($q) => $q->whereNotIn('u.email', $seen))
            ->select('u.first_name', 'u.last_name', 'u.email', 'ra.role')
            ->distinct()->get()->unique('email');

        $rows = collect($rows);
        foreach ($missing as $m) {
            $rows->push([
                'date' => null,
                'open' => false,
                'status' => 'No hours logged',
                'staff_name' => trim(($m->first_name ?? '') . ' ' . ($m->last_name ?? '')),
                'staff_email' => $m->email,
                'role' => $roleLabel[$m->role] ?? 'Staff',
                'clock_in' => '—',
                'clock_out' => '—',
                'date_label' => '—',
                'clock_in_label' => '—',
                'clock_out_label' => '—',
                'break_min' => 0,
                'worked_min' => 0,
                'worked_hours' => 0,
                'notes' => null,
            ]);
        }
        $rows = $rows->values();

        return response()->json([
            'centre_id' => $centreId,
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'total_hours' => round($rows->sum('worked_min') / 60, 2),
            'staff_count' => $rows->pluck('staff_email')->unique()->count(),
            // Stated outright so a payroll figure is never mistaken for break-adjusted.
            'breaks_tracked' => false,
        ]);
    }

    /**
     * GET /api/v1/director/certifications?centre_id=X
     * Active certs across staff at this centre, with expiry alerts.
     */
    public function certifications(Request $request): JsonResponse
    {
        $centreId = (int) $request->input('centre_id');
        if (! $this->hasCentreAccess($request->user()->id, $centreId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $centre = DB::table('centres')->where('id', $centreId)->first();
        $staffUserIds = DB::table('role_assignments')
            ->where(function ($q) use ($centreId, $centre) {
                $q->where('centre_id', $centreId)->orWhere('agency_id', $centre->agency_id);
            })
            ->whereIn('role', ['educator', 'centre_director'])
            ->where('active', true)
            ->pluck('user_id')
            ->unique()
            ->all();

        if (empty($staffUserIds)) {
            return response()->json(['certifications' => [], 'expiring_soon' => 0, 'expired' => 0]);
        }

        $certs = DB::table('staff_certifications')
            ->join('users', 'users.id', '=', 'staff_certifications.user_id')
            ->whereIn('staff_certifications.user_id', $staffUserIds)
            ->where('staff_certifications.active', true)
            ->orderBy('staff_certifications.expires_at')
            ->select(
                'staff_certifications.*',
                'users.first_name',
                'users.last_name'
            )
            ->get();

        $now = Carbon::now();
        $expiringSoon = 0;
        $expired = 0;

        $rows = $certs->map(function ($c) use ($now, &$expiringSoon, &$expired) {
            $exp = $c->expires_at ? Carbon::parse($c->expires_at) : null;
            $status = 'ok';
            $daysUntil = null;
            if ($exp) {
                $daysUntil = $now->diffInDays($exp, false); // signed
                if ($daysUntil < 0) { $status = 'expired'; $expired++; }
                elseif ($daysUntil <= 30) { $status = 'expiring_soon'; $expiringSoon++; }
                elseif ($daysUntil <= 90) { $status = 'warning'; }
            }
            return [
                'id' => $c->id,
                'staff_name' => trim($c->first_name . ' ' . $c->last_name),
                'cert_type' => $c->cert_type,
                'certifier' => $c->certifier,
                'issued_at' => $c->issued_at,
                'expires_at' => $c->expires_at,
                'days_until_expiry' => $daysUntil ? (int) $daysUntil : null,
                'status' => $status,
                'document_url' => $c->document_url,
            ];
        });

        return response()->json([
            'centre_id' => $centreId,
            'certifications' => $rows,
            'expiring_soon' => $expiringSoon,
            'expired' => $expired,
            'total_active' => $rows->count(),
        ]);
    }

    /** Active educators attached to a centre. The fallback when no provider is named. */
    private function centreEducators(int $centreId): array
    {
        return DB::table('role_assignments as ra')
            ->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.role', 'educator')
            ->where('ra.active', true)
            ->where('ra.centre_id', $centreId)
            ->whereNull('u.deleted_at')
            ->whereNotIn('u.status', \App\Support\AccountStatus::CLOSED)
            ->pluck('u.id')->unique()->map(fn ($i) => (int) $i)->values()->all();
    }

    /** Roles that belong on a rota. Guardian is the one deliberately missing. */
    public const STAFF_ROLES = [
        'educator', 'centre_director', 'agency_admin', 'home_visitor',
        'auditor', 'sales_rep', 'platform_admin',
    ];

    /**
     * The person a centre is named for, when there is one.
     *
     * A home provider's centre IS a person -- centre #14 is Amna Ahsan's home -- and
     * supervisor_first_name/supervisor_last_name is where that is recorded. Matching on
     * it lets autofill roster the provider rather than everyone who happens to carry an
     * educator assignment at the centre. At Amna's that was three people, two of them
     * agency staff filed there by mistake, and filling all three was the bug.
     *
     * Returns null when no single active user matches, which is the honest answer for a
     * multi-room centre with a real staff list. Callers must handle null rather than
     * guessing.
     */
    private function designatedProviderFor($centre): ?int
    {
        $first = trim((string) ($centre->supervisor_first_name ?? ''));
        $last  = trim((string) ($centre->supervisor_last_name ?? ''));
        if ($first === '' && $last === '') {
            return null;
        }

        $matches = DB::table('users as u')
            ->join('role_assignments as ra', 'ra.user_id', '=', 'u.id')
            ->where('ra.centre_id', $centre->id)
            ->where('ra.active', true)
            ->whereIn('ra.role', self::STAFF_ROLES)
            ->whereNull('u.deleted_at')
            ->whereNotIn('u.status', \App\Support\AccountStatus::CLOSED)
            ->whereRaw('LOWER(TRIM(u.first_name)) = ?', [mb_strtolower($first)])
            ->whereRaw('LOWER(TRIM(u.last_name)) = ?', [mb_strtolower($last)])
            ->distinct()
            ->pluck('u.id');

        // Exactly one match, or nothing. Two people with the provider's name is not a
        // question this should answer by picking the lower id.
        return $matches->count() === 1 ? (int) $matches->first() : null;
    }

    /**
     * Closures overlapping a range, flattened to one entry per calendar date.
     *
     * A closure row is a span (closure_date .. end_date), so a two-week holiday is one
     * row covering fourteen days. The schedule paints days, so it needs the span expanded.
     * Where two closures land on the same date the first wins -- arbitrary but stable,
     * and the day is shut either way.
     */
    private function closureMapFor(int $centreId, Carbon $start, Carbon $end): array
    {
        $rows = DB::table('centre_closures')
            ->where('centre_id', $centreId)
            ->where('closure_date', '<=', $end->toDateString())
            ->where(function ($q) use ($start) {
                $q->where('end_date', '>=', $start->toDateString())
                  ->orWhereNull('end_date');
            })
            ->orderBy('closure_date')
            ->get();

        $map = [];
        foreach ($rows as $r) {
            $from = Carbon::parse($r->closure_date)->startOfDay();
            $to   = Carbon::parse($r->end_date ?: $r->closure_date)->startOfDay();
            if ($to->lt($from)) {
                $to = $from->copy();
            }
            // Clamp to the window asked for, so a long holiday cannot blow up the response.
            if ($from->lt($start)) {
                $from = $start->copy()->startOfDay();
            }
            if ($to->gt($end)) {
                $to = $end->copy()->startOfDay();
            }
            for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
                $key = $d->toDateString();
                if (isset($map[$key])) {
                    continue;
                }
                $map[$key] = [
                    'id' => $r->id,
                    'type' => $r->closure_type,
                    'reason' => $r->reason,
                    'label' => $this->closureLabel($r->closure_type, $r->reason),
                    'affects_billing' => (bool) $r->affects_billing,
                    'from' => $r->closure_date,
                    'to' => $r->end_date ?: $r->closure_date,
                ];
            }
        }

        return $map;
    }

    /** What the schedule cell should read. The reason is the useful part when there is one. */
    private function closureLabel(?string $type, ?string $reason): string
    {
        $names = [
            'holiday' => 'Holiday',
            // Generated by holidays:sync. Same word to a reader; a different word in the
            // data, which is what lets the generator tell its own rows from a hand-made one.
            'stat_holiday' => 'Holiday',
            'staff_leave' => 'On leave',
            'closure' => 'Closed',
            'emergency' => 'Closed - emergency',
            'training' => 'Training day',
        ];
        $name = $names[(string) $type] ?? 'Closed';
        $reason = trim((string) $reason);

        return $reason !== '' ? ($name . ' - ' . $reason) : $name;
    }

    /**
     * The weekdays a centre operates, as ISO numbers (1 = Monday).
     *
     * settings.open_days is the record where it is set. A centre without it is treated as
     * Monday-Friday rather than as closed all week: an empty setting means nobody filled
     * the field in, and reading that as "never open" would make autofill silently do
     * nothing on exactly the centres most likely to need it.
     */
    private function openDaysFor($centre): array
    {
        $settings = json_decode((string) ($centre->settings ?? ''), true);
        $days = is_array($settings) ? ($settings['open_days'] ?? null) : null;
        if (! is_array($days) || ! count($days)) {
            return [1, 2, 3, 4, 5];
        }

        $out = [];
        foreach ($days as $d) {
            $d = (int) $d;
            if ($d >= 1 && $d <= 7) {
                $out[] = $d;
            }
        }

        return $out ?: [1, 2, 3, 4, 5];
    }

    private function hasCentreAccess(int $userId, int $centreId): bool
    {
        $has = DB::table('role_assignments')
            ->where('user_id', $userId)
            ->whereIn('role', ['centre_director', 'agency_admin'])
            ->where('active', true)
            ->where(function ($q) use ($centreId) {
                $q->where('centre_id', $centreId)
                  ->orWhereIn('agency_id', function ($qq) use ($centreId) {
                      $qq->select('agency_id')->from('centres')->where('id', $centreId);
                  });
            })
            ->exists();
        if ($has) return true;
        // v22p98: platform_admin scoped to the agency they've switched into
        // (X-Active-Agency-Id) — else certs/timesheets/schedule 403 for a super-admin.
        $isPlatform = DB::table('role_assignments')->where('user_id', $userId)->where('role', 'platform_admin')->where('active', true)->exists();
        if ($isPlatform) {
            $centreAgency = (int) DB::table('centres')->where('id', $centreId)->value('agency_id');
            return $centreAgency > 0 && $centreAgency === (int) request()->header('X-Active-Agency-Id');
        }
        return false;
    }

    /**
     * v22p53 — return approved time-off windows so the calendar can
     * render a blocked overlay + the New Shift modal can validate.
     * GET /director/schedule/time-off-blocks?start=YYYY-MM-DD&end=YYYY-MM-DD
     */
    public function timeOffBlocks(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $start = $request->query('start');
        $end = $request->query('end');
        abort_unless($start && $end, 400);
        $agencyId = (int) ($request->header('X-Active-Agency-Id')
            ?: \Illuminate\Support\Facades\DB::table('role_assignments')
                ->where('user_id', $request->user()->id)->where('active', 1)->value('agency_id'));
        $rows = \Illuminate\Support\Facades\DB::table('time_off_requests as tor')
            ->join('users as u', 'u.id', '=', 'tor.user_id')
            ->where('tor.agency_id', $agencyId)
            ->where('tor.status', 'approved')
            ->where('tor.start_at', '<=', $end)
            ->where('tor.end_at', '>=', $start)
            ->select('tor.id', 'tor.user_id', 'tor.start_at', 'tor.end_at', 'tor.request_type',
                \Illuminate\Support\Facades\DB::raw("CONCAT(u.first_name,' ',u.last_name) as user_name"))
            ->get();
        return response()->json(['data' => $rows]);
    }
}
