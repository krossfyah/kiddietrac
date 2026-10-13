<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use App\Support\CentreScope;
use App\Support\DrillCadence;
use App\Support\DrillRules;
use App\Support\Jurisdictions;
use App\Support\Live;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * DRILL LOG (2026-10-09; Phase 1 of the compliance gap analysis — Ohio requires fire drills
 * monthly, severe weather monthly March–September and lockdown quarterly, with records; every
 * Canadian province requires monthly fire drills). Record a drill, list them by month, and see
 * which are due or were missed under the agency's jurisdiction (App\Support\DrillRules).
 *
 * Who: agency/platform admins (whole agency), directors and educators (their own centres —
 * CentreScope / Visibility). Educators record drills because they run them; deleting is for
 * the person who recorded it or an admin/director.
 */
final class DrillController extends Controller
{
    private function agencyId(Request $request): int
    {
        $uid = (int) $request->user()->id;
        $active = (int) $request->header('X-Active-Agency-Id');
        $roles = DB::table('role_assignments')->where('user_id', $uid)->where('active', true);
        if ((clone $roles)->where('role', 'platform_admin')->exists()) {
            abort_unless($active && DB::table('agencies')->where('id', $active)->whereNull('deleted_at')->exists(), 400, 'Select an agency first.');
            return $active;
        }
        if ($active && (clone $roles)->where(function ($w) use ($active) {
                $w->where('agency_id', $active)->orWhereIn('centre_id', DB::table('centres')->where('agency_id', $active)->pluck('id'));
            })->exists()) return $active;
        $id = (int) ((clone $roles)->whereNotNull('agency_id')->value('agency_id')
            ?: DB::table('centres')->whereIn('id', (clone $roles)->whereNotNull('centre_id')->pluck('centre_id'))->value('agency_id'));
        abort_unless($id, 403);

        return $id;
    }

    /** The open centres this caller may see, as [id => name]. */
    private function centres(Request $request, int $agencyId): array
    {
        $scope = CentreScope::ids($request, $agencyId);
        $q = DB::table('centres')->whereIn('id', Live::centreIds($agencyId) ?: [0]);
        if ($scope !== null) $q->whereIn('id', $scope ?: [0]);

        return $q->orderBy('name')->pluck('name', 'id')->all();
    }

    /** GET /drills?centre_id=&month=YYYY-MM */
    public function index(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $centres = $this->centres($request, $agencyId);
        $tz = AgencyTime::tz($agencyId);
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->input('month')) ? (string) $request->input('month') : Carbon::now($tz)->format('Y-m');
        $start = Carbon::createFromFormat('Y-m-d', $month . '-01', $tz)->startOfDay();
        $end = $start->copy()->addMonth();
        $cid = (int) $request->input('centre_id');
        $ids = $cid && isset($centres[$cid]) ? [$cid] : array_keys($centres);

        $rows = DB::table('drills as d')->leftJoin('users as u', 'u.id', '=', 'd.recorded_by')->leftJoin('rooms as r', 'r.id', '=', 'd.room_id')
            ->whereIn('d.centre_id', $ids ?: [0])->whereNull('d.deleted_at')
            ->where('d.drilled_at', '>=', $start->copy()->utc())->where('d.drilled_at', '<', $end->copy()->utc())
            ->orderByDesc('d.drilled_at')
            ->get(['d.id', 'd.centre_id', 'd.room_id', 'r.name as room_name', 'd.type', 'd.drilled_at', 'd.evacuation_seconds', 'd.children_count',
                'd.staff_present', 'd.notes', 'd.recorded_by', 'u.first_name', 'u.last_name',
                'd.area', 'd.scenario', 'd.led_by', 'd.located_seconds', 'd.simulated_911', 'd.simulated_parent_call', 'd.improvements'])
            ->map(function ($r) use ($centres, $tz, $request) {
                $r->centre_name = $centres[$r->centre_id] ?? null;
                $r->type_label = DrillCadence::label((string) $r->type);
                $r->recorded_by_name = trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? ''));
                $r->local = Carbon::parse($r->drilled_at, 'UTC')->setTimezone($tz)->format('Y-m-d H:i');
                $r->can_delete = (int) $r->recorded_by === (int) $request->user()->id;
                unset($r->first_name, $r->last_name);
                return $r;
            });

        $status = [];
        // Ontario (s.68 / s.68.2, 2026-10-13): rolling cadence per centre on top of the period rules.
        $ontario = DrillCadence::applies($agencyId);
        foreach ($ids as $id) $status[] = ['centre_id' => $id, 'centre_name' => $centres[$id] ?? '', 'rules' => DrillRules::status($agencyId, $id)]
            + ($ontario ? ['cadence' => DrillCadence::status($agencyId, (int) $id), 'fire_cadence' => DrillCadence::fireCadence((int) $id),
                'can_manage' => $this->canManageCentre($request, $agencyId, (int) $id)] : []);
        $j = Jurisdictions::forAgency($agencyId);
        $settings = json_decode((string) DB::table('agencies')->where('id', $agencyId)->value('settings'), true) ?: [];

        return response()->json(['month' => $month, 'timezone' => $tz, 'centres' => collect($centres)->map(fn ($n, $i) => ['id' => $i, 'name' => $n])->values(),
            'drills' => $rows, 'status' => $status, 'types' => DrillCadence::types($agencyId), 'jurisdiction' => ['code' => $j['code'], 'label' => $j['label']],
            'ontario' => $ontario, 'fire_cadences' => DrillCadence::FIRE_CADENCES,
            'cadence_reminders' => $ontario && ($settings['drill_cadence_reminders']['enabled'] ?? true) !== false,
            'rules' => array_column($j['drills'] ?? [], 'label'),
            'rooms' => DB::table('rooms')->whereIn('centre_id', $ids ?: [0])->orderBy('name')->get(['id', 'centre_id', 'name']),
            'reminders' => (bool) ($settings['drill_reminders']['enabled'] ?? false)]);
    }

    /** POST /drills */
    public function store(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $centres = $this->centres($request, $agencyId);
        $d = $request->validate([
            'centre_id' => ['required', 'integer'],
            'room_id' => ['nullable', 'integer'],
            'type' => ['required', 'in:' . implode(',', array_keys(DrillCadence::types($agencyId)))],
            'drilled_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'evacuation_seconds' => ['nullable', 'integer', 'min:0', 'max:7200'],
            'children_count' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'staff_present' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
            // Missing-child drill (Ontario s.68.2) — optional on every type.
            'area' => ['nullable', 'string', 'max:255'],
            'scenario' => ['nullable', 'string', 'max:2000'],
            'led_by' => ['nullable', 'string', 'max:160'],
            'located_seconds' => ['nullable', 'integer', 'min:0', 'max:7200'],
            'simulated_911' => ['nullable', 'boolean'],
            'simulated_parent_call' => ['nullable', 'boolean'],
            'improvements' => ['nullable', 'string', 'max:2000'],
        ]);
        abort_unless(isset($centres[(int) $d['centre_id']]), 403, 'That centre is not one of yours.');
        if (! empty($d['room_id'])) {
            abort_unless(DB::table('rooms')->where('id', $d['room_id'])->where('centre_id', $d['centre_id'])->exists(), 422, 'That room is not in this centre.');
        }
        $tz = AgencyTime::tz($agencyId);
        $at = Carbon::createFromFormat('Y-m-d\TH:i', $d['drilled_at'], $tz);
        abort_if($at->gt(Carbon::now($tz)->addMinutes(5)), 422, 'A drill cannot be recorded in the future.');
        $id = DB::table('drills')->insertGetId([
            'agency_id' => $agencyId, 'centre_id' => (int) $d['centre_id'], 'room_id' => $d['room_id'] ?? null, 'type' => $d['type'],
            'drilled_at' => $at->copy()->utc(), 'evacuation_seconds' => $d['evacuation_seconds'] ?? null, 'children_count' => $d['children_count'] ?? null,
            'staff_present' => $d['staff_present'] ?? null, 'notes' => $d['notes'] ?? null, 'recorded_by' => (int) $request->user()->id,
            'area' => $d['area'] ?? null, 'scenario' => $d['scenario'] ?? null, 'led_by' => $d['led_by'] ?? null,
            'located_seconds' => $d['located_seconds'] ?? null,
            'simulated_911' => array_key_exists('simulated_911', $d) && $d['simulated_911'] !== null ? (bool) $d['simulated_911'] : null,
            'simulated_parent_call' => array_key_exists('simulated_parent_call', $d) && $d['simulated_parent_call'] !== null ? (bool) $d['simulated_parent_call'] : null,
            'improvements' => $d['improvements'] ?? null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        try {
            \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $agencyId, 'action' => 'drill.recorded', 'entity_type' => 'drill', 'entity_id' => $id,
                'payload' => json_encode(['summary' => DrillCadence::label($d['type']) . ' drill recorded at ' . ($centres[(int) $d['centre_id']] ?? 'a centre') . ', ' . $at->format('M j, Y g:i A')]),
                'ip_address' => substr((string) $request->ip(), 0, 45), 'created_at' => now()]);
        } catch (\Throwable $e) {}

        return response()->json(['ok' => true, 'id' => $id]);
    }

    /** DELETE /drills/{id} — the person who recorded it, or an admin/director of that centre. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $row = DB::table('drills')->where('id', $id)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        abort_unless($row, 404);
        $centres = $this->centres($request, $agencyId);
        abort_unless(isset($centres[(int) $row->centre_id]), 403);
        $manager = DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)
            ->whereIn('role', ['agency_admin', 'platform_admin', 'centre_director'])->exists();
        abort_unless($manager || (int) $row->recorded_by === (int) $request->user()->id, 403, 'Only the person who recorded it, or a director, can remove a drill.');
        /* KEPT 12 MONTHS (2026-10-13, Anthony: block removal of drill records under 12 months
           old; Ontario O. Reg. 137/15 s.68.2 keeps missing-child drill records 12 months, and
           fire drill records are inspection evidence). A record can still be removed within
           24 hours of being entered, to undo a mistake; after that it stays until it is a year
           old. Drills cannot be edited, so the 24-hour window is the only correction path. */
        $enteredAt = $row->created_at ? \Illuminate\Support\Carbon::parse($row->created_at) : null;
        $drilledAt = $row->drilled_at ? \Illuminate\Support\Carbon::parse($row->drilled_at) : null;
        $justEntered = $enteredAt && $enteredAt->gt(now()->subDay());
        $underAYear = $drilledAt && $drilledAt->gt(now()->subYear());
        if (! $justEntered && $underAYear) {
            return response()->json(['message' => 'Drill records are kept for 12 months, so this one can be removed after '
                . $drilledAt->copy()->addYear()->format('M j, Y') . '. A record can only be removed within 24 hours of being entered.'], 422);
        }
        DB::table('drills')->where('id', $id)->update(['deleted_at' => now(), 'updated_at' => now()]);
        try {
            \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $agencyId, 'action' => 'drill.removed', 'entity_type' => 'drill', 'entity_id' => $id,
                'payload' => json_encode(['summary' => 'Drill record removed (' . $row->type . ', ' . $row->drilled_at . ' UTC)']), 'ip_address' => substr((string) $request->ip(), 0, 45), 'created_at' => now()]);
        } catch (\Throwable $e) {}

        return response()->json(['ok' => true]);
    }

    /** POST /drills/reminders { enabled } — admins/directors switch the monthly reminder on or off. */
    public function reminders(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        abort_unless(DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)
            ->whereIn('role', ['agency_admin', 'platform_admin'])->exists(), 403, 'Agency admins only.');
        $on = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];
        DB::transaction(function () use ($agencyId, $on) {
            $raw = DB::table('agencies')->where('id', $agencyId)->lockForUpdate()->value('settings');
            $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
            $s['drill_reminders'] = ['enabled' => $on];
            DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($s), 'updated_at' => now()]);
        });

        return response()->json(['ok' => true, 'enabled' => $on]);
    }

    /** Agency/platform admins anywhere in the agency; a director only for a centre they direct. */
    private function canManageCentre(Request $request, int $agencyId, int $centreId): bool
    {
        $roles = DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)->get(['role', 'agency_id', 'centre_id']);
        foreach ($roles as $r) {
            if ($r->role === 'platform_admin') return true;
            if ($r->role === 'agency_admin' && (int) $r->agency_id === $agencyId) return true;
            if ($r->role === 'centre_director' && (int) $r->centre_id === $centreId) return true;
        }

        return false;
    }

    /** POST /drills/cadence { centre_id, fire: monthly|school_term } — Ontario: a school-based program runs 3 fire drills a term. */
    public function cadence(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        abort_unless(DrillCadence::applies($agencyId), 404);
        $d = $request->validate(['centre_id' => ['required', 'integer'], 'fire' => ['required', 'in:' . implode(',', array_keys(DrillCadence::FIRE_CADENCES))]]);
        $cid = (int) $d['centre_id'];
        abort_unless(isset($this->centres($request, $agencyId)[$cid]) && $this->canManageCentre($request, $agencyId, $cid), 403, 'Only an admin or this centre\'s director can change this.');
        DB::transaction(function () use ($cid, $d) {   // re-read under a lock: only this key changes
            $raw = DB::table('centres')->where('id', $cid)->lockForUpdate()->value('settings');
            $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
            $s['drill_cadence'] = array_merge(is_array($s['drill_cadence'] ?? null) ? $s['drill_cadence'] : [], ['fire' => $d['fire']]);
            DB::table('centres')->where('id', $cid)->update(['settings' => json_encode($s), 'updated_at' => now()]);
        });
        try {
            \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $agencyId, 'action' => 'drill.cadence_changed', 'entity_type' => 'centre', 'entity_id' => $cid,
                'payload' => json_encode(['summary' => 'Fire drill cadence set to ' . DrillCadence::FIRE_CADENCES[$d['fire']]]), 'ip_address' => substr((string) $request->ip(), 0, 45), 'created_at' => now()]);
        } catch (\Throwable $e) {}

        return response()->json(['ok' => true, 'status' => DrillCadence::status($agencyId, $cid)]);
    }

    /** POST /drills/cadence-reminders { enabled } — Ontario cadence reminders (on by default). Agency admins only. */
    public function cadenceReminders(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        abort_unless(DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)
            ->whereIn('role', ['agency_admin', 'platform_admin'])->exists(), 403, 'Agency admins only.');
        $on = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];
        DB::transaction(function () use ($agencyId, $on) {
            $raw = DB::table('agencies')->where('id', $agencyId)->lockForUpdate()->value('settings');
            $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
            $s['drill_cadence_reminders'] = ['enabled' => $on];
            DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($s), 'updated_at' => now()]);
        });

        return response()->json(['ok' => true, 'enabled' => $on]);
    }
}
