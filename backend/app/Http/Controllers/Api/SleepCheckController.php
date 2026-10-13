<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use App\Support\CareSchedule;
use App\Support\CentreScope;
use App\Support\Live;
use App\Support\SleepChecks;
use App\Support\UserRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SLEEP ROOM + SLEEP CHECK RECORDS (2026-10-13; Ontario O. Reg. 137/15 s.33.1).
 *
 *   GET  /sleep-checks/status            is it on anywhere I work (menu)
 *   GET  /sleep-checks/room?room_id=     the room's under-24-month children, who is asleep, when the next check is due
 *   POST /sleep-checks                   record one or many checks ("Checked" / "Check all")
 *   GET  /sleep-checks/report            per child, per nap: every check and every gap over the interval (json|csv|pdf)
 *   GET|POST /sleep-checks/settings      the agency's sleep policy interval, per-centre overrides (admins write)
 *   POST /sleep-checks/children/{id}/direction  physician's written direction on sleep position
 *
 * Naps themselves are the existing daily_events nap_start / nap_end rows (see
 * App\Support\SleepChecks). Educators are room-scoped, directors centre-scoped, admins
 * agency-wide; every query is bounded by the active agency and fails closed.
 */
final class SleepCheckController extends Controller
{
    use ResolvesCentreContext;

    private const MANAGERS = ['centre_director', 'agency_admin', 'platform_admin'];

    private function agencyId(Request $request): int
    {
        $id = (int) ($this->resolveAgencyId($request) ?: 0);
        abort_unless($id > 0, 400, 'Select an agency first.');

        return $id;
    }

    private function isManager(Request $request): bool
    {
        return (bool) array_intersect(UserRoles::names($request), self::MANAGERS);
    }

    private function isAdmin(Request $request): bool
    {
        return (bool) array_intersect(UserRoles::names($request), ['agency_admin', 'platform_admin']);
    }

    /** Open centres the caller may see, [id => name]. */
    private function centres(Request $request, int $agencyId): array
    {
        $scope = CentreScope::ids($request, $agencyId);
        $q = DB::table('centres')->whereIn('id', Live::centreIds($agencyId) ?: [0]);
        if ($scope !== null) $q->whereIn('id', $scope ?: [0]);

        return $q->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * Rooms the caller may run a sleep room for, [id => row]. An educator gets the rooms
     * she is assigned to (in this agency's open centres); with none assigned, her centres'
     * rooms. Directors their centres' rooms; admins every open room in the agency.
     */
    private function rooms(Request $request, int $agencyId): array
    {
        $open = Live::centreIds($agencyId);
        $q = DB::table('rooms as r')->join('centres as c', 'c.id', '=', 'r.centre_id')
            ->whereIn('r.centre_id', $open ?: [0]);
        if (! $this->isManager($request)) {
            $mine = DB::table('educator_rooms')->where('user_id', $request->user()->id)->pluck('room_id')->map(fn ($v) => (int) $v)->all();
            $inAgency = $mine ? DB::table('rooms')->whereIn('id', $mine)->whereIn('centre_id', $open ?: [0])->pluck('id')->all() : [];
            if ($inAgency) {
                $q->whereIn('r.id', $inAgency);
            } else {
                $q->whereIn('r.centre_id', array_keys($this->centres($request, $agencyId)) ?: [0]);
            }
        } else {
            $q->whereIn('r.centre_id', array_keys($this->centres($request, $agencyId)) ?: [0]);
        }
        $out = [];
        foreach ($q->orderBy('c.name')->orderBy('r.name')->get(['r.id', 'r.name', 'r.centre_id', 'c.name as centre_name']) as $r) {
            $out[(int) $r->id] = ['id' => (int) $r->id, 'name' => $r->name, 'centre_id' => (int) $r->centre_id, 'centre_name' => $r->centre_name];
        }

        return $out;
    }

    private static function publicNap(array $n): array
    {
        unset($n['cfg'], $n['due'], $n['tz']);

        return $n;
    }

    /** GET /sleep-checks/status — for the menu: is the Sleep room on at any centre I work in? */
    public function status(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $raw = SleepChecks::raw($agencyId);
        $on = false;
        foreach (array_unique(array_column($this->rooms($request, $agencyId), 'centre_id')) as $cid) {
            if (SleepChecks::forCentre($agencyId, (int) $cid, $raw)['enabled']) { $on = true; break; }
        }

        return response()->json(['on' => $on]);
    }

    /** GET /sleep-checks/room?room_id= */
    public function room(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $rooms = $this->rooms($request, $agencyId);
        $tz = AgencyTime::tz($agencyId);
        $base = ['rooms' => array_values($rooms), 'timezone' => $tz, 'now' => Carbon::now('UTC')->format('Y-m-d\TH:i:s\Z'),
            'can_manage' => $this->isManager($request), 'positions' => SleepChecks::POSITIONS];
        if (! $rooms) {
            return response()->json($base + ['room_id' => null, 'children' => [], 'enabled' => false]);
        }
        $roomId = (int) $request->query('room_id');
        if (! isset($rooms[$roomId])) $roomId = (int) array_key_first($rooms);
        $room = $rooms[$roomId];
        $cfg = SleepChecks::forCentre($agencyId, $room['centre_id']);

        $q = DB::table('enrollments as en')->join('children as c', 'c.id', '=', 'en.child_id')
            ->where('en.room_id', $roomId)->whereNull('en.end_date');
        Live::enrolledChildren($q, 'c');
        CareSchedule::constrain($q, 'en');
        $kids = $q->distinct()->get(['c.id', 'c.first_name', 'c.last_name', 'c.preferred_name', 'c.date_of_birth', 'c.photo_url',
            'c.sleep_position_direction', 'c.sleep_position_direction_note']);

        $nowLocal = Carbon::now($tz);
        $noDob = 0;
        $list = [];
        foreach ($kids as $k) {
            if (! $k->date_of_birth) { $noDob++; continue; }
            $m = SleepChecks::ageMonths($k->date_of_birth, $nowLocal);
            if ($m >= 24) continue;
            $list[(int) $k->id] = ['child_id' => (int) $k->id, 'name' => trim(($k->preferred_name ?: $k->first_name) . ' ' . $k->last_name),
                'first_name' => $k->preferred_name ?: $k->first_name, 'photo_url' => $k->photo_url, 'age_months' => $m, 'under12' => $m < 12,
                'physician_direction' => (bool) $k->sleep_position_direction, 'physician_note' => $k->sleep_position_direction_note,
                'here' => false, 'nap' => null];
        }

        foreach (SleepChecks::openNaps([$roomId]) as $n) {
            $cid = $n['child_id'];
            if (! isset($list[$cid])) {
                // Asleep in this room though enrolled elsewhere (shared care): still checked here.
                if (! $this->canAccessChildId($request->user(), $cid) && ! $this->isManager($request)) continue;
                $list[$cid] = ['child_id' => $cid, 'name' => $n['name'], 'first_name' => $n['first_name'], 'photo_url' => $n['photo_url'],
                    'age_months' => $n['age_months'], 'under12' => $n['under12'], 'physician_direction' => $n['physician_direction'],
                    'physician_note' => $n['physician_note'], 'here' => true, 'nap' => null];
            }
            $list[$cid]['nap'] = self::publicNap($n);
        }

        if ($list) {
            [$from] = AgencyTime::dayRange($agencyId);
            $last = [];
            foreach (DB::table('check_events')->whereIn('child_id', array_keys($list))->where('occurred_at', '>=', $from)
                ->where('occurred_at', '<=', Carbon::now('UTC')->format('Y-m-d H:i:s'))
                ->orderBy('occurred_at')->orderBy('id')->get(['child_id', 'event_type']) as $e) {
                $last[(int) $e->child_id] = $e->event_type;
            }
            foreach ($list as $cid => &$row) {
                if (($last[$cid] ?? null) === 'check_in' || $row['nap']) $row['here'] = true;
            }
            unset($row);
        }

        // Asleep first (most overdue first), then the rest by name.
        $list = array_values($list);
        usort($list, function ($a, $b) {
            if (! ! $a['nap'] !== ! ! $b['nap']) return $a['nap'] ? -1 : 1;
            if ($a['nap'] && $b['nap']) return strcmp($a['nap']['due_at'], $b['nap']['due_at']);
            if ($a['here'] !== $b['here']) return $a['here'] ? -1 : 1;

            return strcasecmp($a['name'], $b['name']);
        });

        return response()->json($base + [
            'room_id' => $roomId, 'room' => $room, 'enabled' => $cfg['enabled'],
            'policy' => ['infant_minutes' => $cfg['infant_minutes'], 'toddler_minutes' => $cfg['toddler_minutes']],
            'children' => $list, 'missing_dob' => $noDob,
        ]);
    }

    /** POST /sleep-checks {checks: [{nap_event_id, position, breathing_ok, colour_ok, note}]} */
    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'checks' => ['required', 'array', 'min:1', 'max:40'],
            'checks.*.nap_event_id' => ['required', 'integer'],
            'checks.*.position' => ['required', 'in:' . implode(',', array_keys(SleepChecks::POSITIONS))],
            'checks.*.breathing_ok' => ['required', 'boolean'],
            'checks.*.colour_ok' => ['required', 'boolean'],
            'checks.*.note' => ['nullable', 'string', 'max:1000'],
        ]);
        $agencyId = $this->agencyId($request);
        $rooms = $this->rooms($request, $agencyId);
        $user = $request->user();

        $ok = [];
        $refused = [];
        foreach ($d['checks'] as $c) {
            $nap = SleepChecks::openNap((int) $c['nap_event_id']);
            if (! $nap) { $refused[] = ['nap_event_id' => (int) $c['nap_event_id'], 'reason' => 'This child is no longer asleep.']; continue; }
            if ($nap['agency_id'] !== $agencyId || ! isset($rooms[$nap['room_id']])) { $refused[] = ['nap_event_id' => $nap['nap_event_id'], 'reason' => 'Not one of your rooms.']; continue; }
            if (! $nap['enabled']) { $refused[] = ['nap_event_id' => $nap['nap_event_id'], 'reason' => 'Sleep checks are switched off for this centre.']; continue; }
            if (! $this->isManager($request) && ! $this->canAccessChildId($user, $nap['child_id'])) { $refused[] = ['nap_event_id' => $nap['nap_event_id'], 'reason' => 'Not one of your children.']; continue; }
            $note = trim((string) ($c['note'] ?? ''));
            /* Under 12 months: on the back, unless a physician has directed otherwise in
               writing. Anything else must be explained (and the educator should reposition
               the child) — the record has to show why. */
            if ($nap['under12'] && in_array($c['position'], ['side', 'tummy'], true) && ! $nap['physician_direction'] && $note === '') {
                return response()->json(['message' => $nap['first_name'] . ' is under 12 months: an infant sleeps on their back unless a physician has directed otherwise in writing. Reposition them and add a note saying so.',
                    'nap_event_id' => $nap['nap_event_id']], 422);
            }
            if ((! $c['breathing_ok'] || ! $c['colour_ok']) && $note === '') {
                return response()->json(['message' => 'Add a note describing what you saw for ' . $nap['first_name'] . ', and get help right away if the child is not breathing normally.',
                    'nap_event_id' => $nap['nap_event_id']], 422);
            }
            $ok[] = [$nap, $c, $note];
        }

        $saved = 0;
        $now = Carbon::now('UTC');
        DB::transaction(function () use ($ok, $agencyId, $user, $now, &$saved) {
            foreach ($ok as [$nap, $c, $note]) {
                // A double tap on "Check all" is one check, not two.
                $dupe = DB::table('sleep_checks')->where('nap_event_id', $nap['nap_event_id'])->where('checked_by', $user->id)
                    ->where('checked_at', '>=', $now->copy()->subSeconds(20)->format('Y-m-d H:i:s'))->exists();
                if ($dupe) continue;
                DB::table('sleep_checks')->insert([
                    'agency_id' => $agencyId, 'centre_id' => $nap['centre_id'], 'room_id' => $nap['room_id'], 'child_id' => $nap['child_id'],
                    'nap_event_id' => $nap['nap_event_id'], 'checked_by' => $user->id, 'checked_at' => $now->format('Y-m-d H:i:s'),
                    'position' => $c['position'], 'breathing_ok' => (bool) $c['breathing_ok'], 'colour_ok' => (bool) $c['colour_ok'],
                    'note' => $note !== '' ? $note : null, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $saved++;
            }
        });

        if ($saved) {
            try {
                \App\Support\Audit::write(['user_id' => (int) $user->id, 'agency_id' => $agencyId, 'action' => 'sleep_check.recorded', 'entity_type' => 'sleep_check',
                    'entity_id' => $ok[0][0]['nap_event_id'],
                    'payload' => json_encode(['summary' => 'Sleep check recorded for ' . implode(', ', array_map(fn ($o) => $o[0]['first_name'], $ok)) . ' (' . $ok[0][0]['room_name'] . ')']),
                    'ip_address' => substr((string) $request->ip(), 0, 45), 'created_at' => now()]);
            } catch (\Throwable $e) {}
        }

        return response()->json(['ok' => true, 'saved' => $saved, 'refused' => $refused,
            'message' => $saved ? ($saved === 1 ? 'Check recorded.' : $saved . ' checks recorded.') : ($refused[0]['reason'] ?? 'Nothing to record.')], $saved || ! $refused ? 200 : 422);
    }

    /** POST /sleep-checks/children/{child}/direction {on, note} — directors and admins. */
    public function direction(Request $request, int $child): JsonResponse
    {
        abort_unless($this->isManager($request), 403, 'Directors and admins record a physician\'s direction.');
        $agencyId = $this->agencyId($request);
        $d = $request->validate(['on' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:1000']]);
        $centreId = (int) DB::table('children as c')->join('families as f', 'f.id', '=', 'c.family_id')->where('c.id', $child)->whereNull('c.deleted_at')->value('f.centre_id');
        abort_unless($centreId && isset($this->centres($request, $agencyId)[$centreId]), 404);
        if ($d['on'] && trim((string) ($d['note'] ?? '')) === '') {
            return response()->json(['message' => 'Say what the physician directed (position, and who signed it, when).'], 422);
        }
        DB::table('children')->where('id', $child)->update([
            'sleep_position_direction' => (bool) $d['on'],
            'sleep_position_direction_note' => $d['on'] ? trim((string) $d['note']) : null,
            'sleep_position_direction_at' => $d['on'] ? now() : null,
            'sleep_position_direction_by' => $d['on'] ? $request->user()->id : null,
        ]);
        try {
            \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $agencyId, 'action' => 'child.sleep_direction', 'entity_type' => 'child', 'entity_id' => $child,
                'payload' => json_encode(['summary' => $d['on'] ? 'Physician direction on sleep position recorded: ' . trim((string) $d['note']) : 'Physician direction on sleep position removed']),
                'ip_address' => substr((string) $request->ip(), 0, 45), 'created_at' => now()]);
        } catch (\Throwable $e) {}

        return response()->json(['ok' => true]);
    }

    /** GET /sleep-checks/settings */
    public function settings(Request $request): JsonResponse
    {
        abort_unless($this->isManager($request), 403);
        $agencyId = $this->agencyId($request);
        $raw = SleepChecks::raw($agencyId);
        $centres = [];
        foreach ($this->centres($request, $agencyId) as $id => $name) {
            $o = $raw['centres'][(string) $id] ?? null;
            $centres[] = ['id' => (int) $id, 'name' => $name, 'override' => is_array($o) ? $o : null, 'effective' => SleepChecks::forCentre($agencyId, (int) $id, $raw)];
        }
        $j = \App\Support\Jurisdictions::forAgency($agencyId);

        return response()->json(['agency' => SleepChecks::agency($agencyId, $raw), 'centres' => $centres, 'can_edit' => $this->isAdmin($request),
            'jurisdiction' => ['code' => $j['code'], 'label' => $j['label'] ?? $j['code']], 'default_enabled' => SleepChecks::defaultEnabled($agencyId),
            'limits' => ['min' => SleepChecks::MIN_MINUTES, 'max' => SleepChecks::MAX_MINUTES]]);
    }

    /** POST /sleep-checks/settings — agency admins. */
    public function saveSettings(Request $request): JsonResponse
    {
        abort_unless($this->isAdmin($request), 403, 'Agency admins only.');
        $agencyId = $this->agencyId($request);
        $min = SleepChecks::MIN_MINUTES; $max = SleepChecks::MAX_MINUTES;
        $d = $request->validate([
            'enabled' => ['required', 'boolean'],
            'infant_minutes' => ['required', 'integer', "min:$min", "max:$max"],
            'toddler_minutes' => ['required', 'integer', "min:$min", "max:$max"],
            'alert_director' => ['required', 'boolean'],
            'director_grace_minutes' => ['required', 'integer', 'min:0', 'max:60'],
            'centres' => ['nullable', 'array'],
            'centres.*.enabled' => ['nullable', 'boolean'],
            'centres.*.infant_minutes' => ['nullable', 'integer', "min:$min", "max:$max"],
            'centres.*.toddler_minutes' => ['nullable', 'integer', "min:$min", "max:$max"],
        ]);
        $mine = $this->centres($request, $agencyId);
        $over = [];
        foreach (($d['centres'] ?? []) as $cid => $o) {
            if (! isset($mine[(int) $cid]) || ! is_array($o)) continue;
            $o = array_filter(['enabled' => $o['enabled'] ?? null, 'infant_minutes' => $o['infant_minutes'] ?? null, 'toddler_minutes' => $o['toddler_minutes'] ?? null], fn ($v) => $v !== null);
            if ($o) $over[(string) (int) $cid] = $o;
        }
        $block = ['enabled' => (bool) $d['enabled'], 'infant_minutes' => (int) $d['infant_minutes'], 'toddler_minutes' => (int) $d['toddler_minutes'],
            'alert_director' => (bool) $d['alert_director'], 'director_grace_minutes' => (int) $d['director_grace_minutes'], 'centres' => (object) $over];
        DB::transaction(function () use ($agencyId, $block) {
            $raw = DB::table('agencies')->where('id', $agencyId)->lockForUpdate()->value('settings');
            $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
            $s['sleep_checks'] = $block;
            DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($s), 'updated_at' => now()]);
        });
        try {
            \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $agencyId, 'action' => 'settings.sleep_checks', 'entity_type' => 'agency', 'entity_id' => $agencyId,
                'payload' => json_encode(['summary' => 'Sleep check policy ' . ($block['enabled'] ? 'on' : 'off') . ': under 12 months every ' . $block['infant_minutes'] . ' min, 12–24 months every ' . $block['toddler_minutes'] . ' min' . ($over ? ' (' . count($over) . ' centre override(s))' : '')]),
                'ip_address' => substr((string) $request->ip(), 0, 45), 'created_at' => now()]);
        } catch (\Throwable $e) {}

        return response()->json(['ok' => true]);
    }

    /** GET /sleep-checks/report?centre_id=&date=&to=&room_id=&child_id=&format=json|csv|pdf — directors and admins. */
    public function report(Request $request)
    {
        abort_unless($this->isManager($request), 403, 'Directors and admins.');
        $agencyId = $this->agencyId($request);
        $tz = AgencyTime::tz($agencyId);
        $centres = $this->centres($request, $agencyId);
        abort_unless($centres, 403, 'No centres.');
        $cid = (int) $request->query('centre_id');
        if (! isset($centres[$cid])) $cid = (int) array_key_first($centres);
        $today = AgencyTime::today($agencyId);
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('date')) ? (string) $request->query('date') : $today;
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('to')) ? (string) $request->query('to') : $date;
        if ($to < $date) $to = $date;
        if (Carbon::parse($date)->diffInDays(Carbon::parse($to)) > 31) $to = Carbon::parse($date)->addDays(31)->toDateString();
        [$from, $until] = AgencyTime::spanRange($agencyId, $date, $to);

        $rooms = DB::table('rooms')->where('centre_id', $cid)->orderBy('name')->pluck('name', 'id')->all();
        $roomId = (int) $request->query('room_id');
        $roomIds = $roomId && isset($rooms[$roomId]) ? [$roomId] : array_keys($rooms);
        $childId = (int) $request->query('child_id');
        $cfg = SleepChecks::forCentre($agencyId, $cid);

        $q = DB::table('daily_events as e')->join('children as c', 'c.id', '=', 'e.child_id')
            ->whereIn('e.room_id', $roomIds ?: [0])->where('e.event_type', 'nap_start')->whereNull('e.deleted_at')
            ->where('e.occurred_at', '>=', $from)->where('e.occurred_at', '<', $until)->whereNull('c.deleted_at')
            // A nap that has not started yet (entered in advance) is not part of the record.
            ->where('e.occurred_at', '<=', Carbon::now('UTC')->format('Y-m-d H:i:s'))
            ->whereRaw('NOT (e.created_at < e.occurred_at - INTERVAL ' . SleepChecks::ADVANCE_MINUTES . ' MINUTE)')
            ->whereNotNull('c.date_of_birth');
        if ($childId) $q->where('e.child_id', $childId);
        $starts = $q->orderBy('e.occurred_at')->get(['e.id', 'e.child_id', 'e.room_id', 'e.occurred_at', 'c.first_name', 'c.last_name', 'c.preferred_name', 'c.date_of_birth', 'c.sleep_position_direction']);

        $naps = [];
        if ($starts->isNotEmpty()) {
            $kids = $starts->pluck('child_id')->unique()->values()->all();
            $evs = DB::table('daily_events')->whereIn('child_id', $kids)->whereIn('event_type', ['nap_start', 'nap_end'])->whereNull('deleted_at')
                ->where('occurred_at', '>=', $from)->where('occurred_at', '<', Carbon::parse($until, 'UTC')->addHours(SleepChecks::OPEN_HOURS)->format('Y-m-d H:i:s'))
                ->where('occurred_at', '<=', Carbon::now('UTC')->format('Y-m-d H:i:s'))
                ->orderBy('occurred_at')->orderBy('id')->get(['id', 'child_id', 'event_type', 'occurred_at'])->groupBy('child_id');
            $outs = DB::table('check_events')->whereIn('child_id', $kids)->where('event_type', 'check_out')->where('occurred_at', '>=', $from)
                ->where('occurred_at', '<=', Carbon::now('UTC')->format('Y-m-d H:i:s'))
                ->orderBy('occurred_at')->get(['child_id', 'occurred_at'])->groupBy('child_id');
            $checks = DB::table('sleep_checks as s')->leftJoin('users as u', 'u.id', '=', 's.checked_by')
                ->whereIn('s.nap_event_id', $starts->pluck('id')->all())->orderBy('s.checked_at')
                ->get(['s.nap_event_id', 's.checked_at', 's.position', 's.breathing_ok', 's.colour_ok', 's.note', 'u.first_name', 'u.last_name'])->groupBy('nap_event_id');
            $nowUtc = Carbon::now('UTC');

            foreach ($starts as $s) {
                $start = Carbon::parse($s->occurred_at, 'UTC');
                $months = SleepChecks::ageMonths($s->date_of_birth, $start->copy()->setTimezone($tz));
                $interval = SleepChecks::intervalFor($cfg, $months);
                if ($interval === null) continue;

                // Wake = the first nap_end at/after the start, unless another nap_start comes first.
                $end = null; $endReason = null;
                foreach ($evs->get($s->child_id, collect()) as $e) {
                    if ((int) $e->id === (int) $s->id || $e->occurred_at < $s->occurred_at) continue;
                    if ($e->event_type === 'nap_start') { break; }
                    $end = Carbon::parse($e->occurred_at, 'UTC'); $endReason = 'woke'; break;
                }
                if (! $end) {
                    foreach ($outs->get($s->child_id, collect()) as $o) {
                        if ($o->occurred_at > $s->occurred_at) { $end = Carbon::parse($o->occurred_at, 'UTC'); $endReason = 'signed_out'; break; }
                    }
                }
                if (! $end && $nowUtc->diffInHours($start, true) < SleepChecks::OPEN_HOURS) { $end = $nowUtc->copy(); $endReason = 'asleep'; }

                $list = [];
                $prev = $start;
                $gaps = [];
                $maxGap = 0;
                foreach ($checks->get($s->id, collect()) as $c) {
                    $at = Carbon::parse($c->checked_at, 'UTC');
                    $gap = (int) round($prev->diffInSeconds($at, true) / 60);
                    $maxGap = max($maxGap, $gap);
                    if ($gap > $interval) $gaps[] = ['from' => $prev->copy()->setTimezone($tz)->format('g:i A'), 'to' => $at->copy()->setTimezone($tz)->format('g:i A'), 'minutes' => $gap];
                    $list[] = ['at_local' => $at->copy()->setTimezone($tz)->format('g:i A'), 'by' => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')),
                        'position' => $c->position, 'position_label' => SleepChecks::POSITIONS[$c->position] ?? $c->position,
                        'breathing_ok' => (bool) $c->breathing_ok, 'colour_ok' => (bool) $c->colour_ok, 'note' => $c->note,
                        'minutes_since_previous' => $gap, 'over' => $gap > $interval];
                    $prev = $at;
                }
                if ($end) {
                    $gap = (int) round($prev->diffInSeconds($end, true) / 60);
                    $maxGap = max($maxGap, $gap);
                    if ($gap > $interval) $gaps[] = ['from' => $prev->copy()->setTimezone($tz)->format('g:i A'),
                        'to' => $endReason === 'asleep' ? 'now' : $end->copy()->setTimezone($tz)->format('g:i A'), 'minutes' => $gap, 'tail' => true];
                }

                $naps[] = [
                    'nap_event_id' => (int) $s->id, 'child_id' => (int) $s->child_id,
                    'child' => trim(($s->preferred_name ?: $s->first_name) . ' ' . $s->last_name), 'date_of_birth' => $s->date_of_birth,
                    'age_months' => $months, 'under12' => $months < 12, 'physician_direction' => (bool) $s->sleep_position_direction,
                    'room' => $rooms[$s->room_id] ?? '', 'date' => $start->copy()->setTimezone($tz)->format('Y-m-d'),
                    'start_local' => $start->copy()->setTimezone($tz)->format('g:i A'),
                    'end_local' => $end && $endReason !== 'asleep' ? $end->copy()->setTimezone($tz)->format('g:i A') : null,
                    'end_reason' => $endReason ?: 'not_logged',
                    'minutes_asleep' => $end ? (int) round($start->diffInSeconds($end, true) / 60) : null,
                    'interval' => $interval, 'checks' => $list, 'gaps' => $gaps, 'max_gap' => $maxGap, 'ok' => ! $gaps && $endReason !== null,
                ];
            }
        }

        $summary = ['naps' => count($naps), 'checks' => array_sum(array_map(fn ($n) => count($n['checks']), $naps)),
            'naps_with_gaps' => count(array_filter($naps, fn ($n) => ! $n['ok']))];
        $meta = ['centre_id' => $cid, 'centre_name' => $centres[$cid], 'date' => $date, 'to' => $to, 'room_id' => $roomId ?: null,
            'policy' => ['infant_minutes' => $cfg['infant_minutes'], 'toddler_minutes' => $cfg['toddler_minutes'], 'enabled' => $cfg['enabled']], 'timezone' => $tz];

        $fmt = (string) $request->query('format');
        if ($fmt === 'csv') return $this->csv($naps, $meta);
        if ($fmt === 'pdf') return $this->pdf($naps, $meta, $summary, $agencyId);

        return response()->json($meta + ['summary' => $summary, 'naps' => $naps,
            'centres' => collect($centres)->map(fn ($n, $i) => ['id' => (int) $i, 'name' => $n])->values(),
            'rooms' => collect($rooms)->map(fn ($n, $i) => ['id' => (int) $i, 'name' => $n])->values()]);
    }

    private static function endText(array $n): string
    {
        return match ($n['end_reason']) {
            'woke' => (string) $n['end_local'], 'signed_out' => 'Signed out ' . $n['end_local'],
            'asleep' => 'Still asleep', default => 'Wake time not logged',
        };
    }

    private function csv(array $naps, array $meta)
    {
        $name = 'sleep-checks-' . preg_replace('/[^a-z0-9]+/i', '-', $meta['centre_name']) . '-' . $meta['date'] . ($meta['to'] !== $meta['date'] ? '-to-' . $meta['to'] : '') . '.csv';

        return response()->streamDownload(function () use ($naps) {
            $h = fopen('php://output', 'w');
            fwrite($h, "\xEF\xBB\xBF");
            fputcsv($h, ['Date', 'Child', 'Date of birth', 'Age (months)', 'Room', 'Asleep', 'Woke', 'Check interval (min)', 'Check time', 'Checked by', 'Position', 'Breathing normal', 'Colour normal', 'Note', 'Minutes since previous', 'Over interval']);
            foreach ($naps as $n) {
                $lead = [$n['date'], $n['child'], $n['date_of_birth'], $n['age_months'], $n['room'], $n['start_local'], self::endText($n), $n['interval']];
                if (! $n['checks']) fputcsv($h, array_merge($lead, ['', '', '', '', '', 'NO CHECKS RECORDED', '', '']));
                foreach ($n['checks'] as $c) {
                    fputcsv($h, array_merge($lead, [$c['at_local'], $c['by'], $c['position_label'], $c['breathing_ok'] ? 'Yes' : 'NO', $c['colour_ok'] ? 'Yes' : 'NO', (string) $c['note'], $c['minutes_since_previous'], $c['over'] ? 'YES' : '']));
                }
                foreach ($n['gaps'] as $g) {
                    if (! empty($g['tail'])) fputcsv($h, array_merge($lead, ['', '', '', '', '', 'Last check to ' . ($g['to'] === 'now' ? 'now' : 'waking') . ': ' . $g['from'] . ' to ' . $g['to'], $g['minutes'], 'YES']));
                }
            }
            fclose($h);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function pdf(array $naps, array $meta, array $summary, int $agencyId)
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $agency = (string) DB::table('agencies')->where('id', $agencyId)->value('name');
        $period = $meta['date'] . ($meta['to'] !== $meta['date'] ? ' to ' . $meta['to'] : '');
        $h = '<html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:10px;color:#0F172A;}h1{font-size:16px;margin:0;}'
            . '.m{color:#475569;margin:2px 0 10px;}table{width:100%;border-collapse:collapse;margin:4px 0 12px;}th,td{border:1px solid #CBD5E1;padding:3px 5px;text-align:left;vertical-align:top;}'
            . 'th{background:#F1F5F9;font-size:9px;text-transform:uppercase;}.n{font-weight:bold;font-size:11px;margin-top:10px;}.bad{color:#B91C1C;font-weight:bold;}.ok{color:#047857;}</style></head><body>'
            . '<h1>Sleep supervision checks</h1><div class="m">' . $e($agency) . ' · ' . $e($meta['centre_name']) . ' · ' . $e($period)
            . '<br>Sleep policy: under 12 months every ' . (int) $meta['policy']['infant_minutes'] . ' min; 12–24 months every ' . (int) $meta['policy']['toddler_minutes'] . ' min. '
            . 'Times in ' . $e($meta['timezone']) . '. ' . (int) $summary['naps'] . ' naps, ' . (int) $summary['checks'] . ' checks, ' . (int) $summary['naps_with_gaps'] . ' with a gap over the interval.</div>';
        if (! $naps) $h .= '<p>No naps recorded for children under 24 months in this period.</p>';
        foreach ($naps as $n) {
            $h .= '<div class="n">' . $e($n['child']) . ' <span style="font-weight:normal;color:#475569;">(' . (int) $n['age_months'] . ' months, ' . $e($n['room']) . ') · ' . $e($n['date'])
                . ' · asleep ' . $e($n['start_local']) . ' · ' . $e(self::endText($n)) . ' · checks every ' . (int) $n['interval'] . ' min'
                . ($n['physician_direction'] ? ' · physician direction on file' : '') . '</span> '
                . ($n['ok'] ? '<span class="ok">✓ no gaps</span>' : '<span class="bad">gap(s): ' . $e(implode('; ', array_map(fn ($g) => $g['from'] . '–' . $g['to'] . ' (' . $g['minutes'] . ' min)', $n['gaps'])) ?: 'wake time not logged') . '</span>') . '</div>';
            $h .= '<table><tr><th>Time</th><th>Checked by</th><th>Position</th><th>Breathing</th><th>Colour</th><th>Min since previous</th><th>Note</th></tr>';
            if (! $n['checks']) $h .= '<tr><td colspan="7" class="bad">No checks recorded</td></tr>';
            foreach ($n['checks'] as $c) {
                $h .= '<tr><td>' . $e($c['at_local']) . '</td><td>' . $e($c['by']) . '</td><td>' . $e($c['position_label']) . '</td><td>' . ($c['breathing_ok'] ? 'Normal' : '<span class="bad">NO</span>') . '</td><td>'
                    . ($c['colour_ok'] ? 'Normal' : '<span class="bad">NO</span>') . '</td><td' . ($c['over'] ? ' class="bad"' : '') . '>' . (int) $c['minutes_since_previous'] . '</td><td>' . $e($c['note']) . '</td></tr>';
            }
            $h .= '</table>';
        }
        $h .= '</body></html>';
        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml($h, 'UTF-8');
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();

        return response($dompdf->output(), 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="sleep-checks-' . $meta['date'] . '.pdf"']);
    }
}
