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

        $canManage = [];
        foreach ($ids as $id) $canManage[$id] = $this->canManageCentre($request, $agencyId, (int) $id);
        $rows = DB::table('drills as d')->leftJoin('users as u', 'u.id', '=', 'd.recorded_by')->leftJoin('rooms as r', 'r.id', '=', 'd.room_id')
            ->whereIn('d.centre_id', $ids ?: [0])->whereNull('d.deleted_at')
            ->where('d.drilled_at', '>=', $start->copy()->utc())->where('d.drilled_at', '<', $end->copy()->utc())
            ->orderByDesc('d.drilled_at')
            ->get(['d.id', 'd.centre_id', 'd.room_id', 'r.name as room_name', 'd.type', 'd.drilled_at', 'd.evacuation_seconds', 'd.children_count',
                'd.staff_present', 'd.notes', 'd.recorded_by', 'u.first_name', 'u.last_name',
                'd.area', 'd.scenario', 'd.led_by', 'd.located_seconds', 'd.simulated_911', 'd.simulated_parent_call', 'd.improvements', 'd.created_at',
                // Fire plan (2026-11-28): the plan used, the route, attendance beside children evacuated, issues found.
                ...(\Illuminate\Support\Facades\Schema::hasColumn('drills', 'fire_plan_id') ? ['d.fire_plan_id', 'd.route_used', 'd.route_note', 'd.attendance_count', 'd.issues_found'] : [])])
            ->map(function ($r) use ($centres, $tz, $request, $canManage) {
                $r->centre_name = $centres[$r->centre_id] ?? null;
                $r->type_label = DrillCadence::label((string) $r->type);
                $r->recorded_by_name = trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? ''));
                $r->local = Carbon::parse($r->drilled_at, 'UTC')->setTimezone($tz)->format('Y-m-d H:i');
                /* Who may remove it, and whether they may NOW (2026-11-10, the drill menu): the same two rules destroy()
                   enforces - the recorder or a director of this centre, and only within 24 hours of entering it or once it
                   is a year old. kept_until says when a record still under the 12-month rule can go. */
                $r->may_remove = ($canManage[$r->centre_id] ?? false) || (int) $r->recorded_by === (int) $request->user()->id;
                $drilled = Carbon::parse($r->drilled_at, 'UTC');
                $open = ($r->created_at && Carbon::parse($r->created_at)->gt(now()->subDay())) || ! $drilled->gt(now()->subYear());
                $r->can_delete = $r->may_remove && $open;
                $r->kept_until = $open ? null : $drilled->copy()->setTimezone($tz)->addYear()->format('M j, Y');
                unset($r->first_name, $r->last_name, $r->created_at);
                return $r;
            });

        /* The newest drill of each type at each centre and who recorded it (2026-11-10: the Drills tab shows "last done"
           next to each required drill, so it needs the name too). Same centres as everything else here; 15 months back. */
        $latest = [];
        foreach (DB::table('drills as d')->leftJoin('users as u', 'u.id', '=', 'd.recorded_by')
            ->whereIn('d.centre_id', $ids ?: [0])->whereNull('d.deleted_at')->where('d.drilled_at', '>=', now()->subMonths(15))
            ->orderByDesc('d.drilled_at')->get(['d.centre_id', 'd.type', 'd.drilled_at', 'u.first_name', 'u.last_name']) as $x) {
            if (isset($latest[$x->centre_id][$x->type])) continue;
            $latest[$x->centre_id][$x->type] = ['local' => Carbon::parse($x->drilled_at, 'UTC')->setTimezone($tz)->format('Y-m-d H:i'),
                'by' => trim(($x->first_name ?? '') . ' ' . ($x->last_name ?? ''))];
        }

        $status = [];
        // Ontario (s.68 / s.68.2, 2026-10-13): rolling cadence per centre on top of the period rules.
        $ontario = DrillCadence::applies($agencyId);
        // Each centre's fire plan and whether it has gone 12 months without a review (display only; nothing is sent).
        $plans = \App\Support\FirePlans::centreSummary(array_map('intval', $ids), $tz);
        foreach ($ids as $id) $status[] = ['centre_id' => $id, 'centre_name' => $centres[$id] ?? '', 'rules' => DrillRules::status($agencyId, $id),
                'latest' => (object) ($latest[$id] ?? []), 'off' => (object) $this->offFor((int) $id, $tz), 'can_manage' => $canManage[$id] ?? false,
                'fire_plan' => $plans[(int) $id] ?? null]
            + ($ontario ? ['cadence' => DrillCadence::status($agencyId, (int) $id), 'fire_cadence' => DrillCadence::fireCadence((int) $id)] : []);
        $j = Jurisdictions::forAgency($agencyId);
        $settings = json_decode((string) DB::table('agencies')->where('id', $agencyId)->value('settings'), true) ?: [];

        return response()->json(['month' => $month, 'timezone' => $tz, 'centres' => collect($centres)->map(fn ($n, $i) => ['id' => $i, 'name' => $n])->values(),
            'drills' => $rows, 'status' => $status, 'types' => DrillCadence::types($agencyId), 'jurisdiction' => ['code' => $j['code'], 'label' => $j['label']],
            'ontario' => $ontario, 'fire_cadences' => DrillCadence::FIRE_CADENCES,
            'cadence_reminders' => $ontario && ($settings['drill_cadence_reminders']['enabled'] ?? true) !== false,
            'rules' => array_column($j['drills'] ?? [], 'label'),
            'rooms' => DB::table('rooms')->whereIn('centre_id', $ids ?: [0])->orderBy('name')->get(['id', 'centre_id', 'name']),
            'maps' => $this->maps($request, $agencyId, $ids),
            'educators' => $this->staffDates($request, $agencyId, $ids, $centres, $tz),
            'can_manage' => collect($ids)->contains(fn ($id) => $this->canManageCentre($request, $agencyId, (int) $id)),
            'reminders' => (bool) ($settings['drill_reminders']['enabled'] ?? false),
            'is_admin' => $this->isAgencyAdmin($request),
            'notify' => $this->notifyPayload($agencyId),
            'schedule_options' => ['repeats' => \App\Support\DrillSchedule::REPEATS, 'weekdays' => \App\Support\DrillSchedule::WEEKDAYS, 'weeks' => \App\Support\DrillSchedule::WEEKS]]);
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
            // Fire plan (2026-11-28): the plan this drill followed, the route used, attendance and issues found.
            'fire_plan_id' => ['nullable', 'integer'],
            'route_used' => ['nullable', 'in:primary,alternate,other'],
            'route_note' => ['nullable', 'string', 'max:255'],
            'attendance_count' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'issues_found' => ['nullable', 'string', 'max:2000'],
        ]);
        abort_unless(isset($centres[(int) $d['centre_id']]), 403, 'That centre is not one of yours.');
        if (! empty($d['room_id'])) {
            abort_unless(DB::table('rooms')->where('id', $d['room_id'])->where('centre_id', $d['centre_id'])->exists(), 422, 'That room is not in this centre.');
        }
        // The plan must be one of THIS centre's (the destination is checked, not just the record - leak rule 6), and
        // never someone else's unsubmitted draft.
        $plan = null;
        if (! empty($d['fire_plan_id'])) {
            $plan = DB::table('fire_plans')->where('id', (int) $d['fire_plan_id'])->where('agency_id', $agencyId)->where('centre_id', (int) $d['centre_id'])->whereNull('deleted_at')
                ->where(fn ($w) => $w->where('status', '!=', 'draft')->orWhere('created_by', (int) $request->user()->id))->first();
            abort_unless($plan, 422, 'That fire plan is not one of this centre\'s.');
        }
        $hasPlanCols = \Illuminate\Support\Facades\Schema::hasColumn('drills', 'fire_plan_id');
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
        ] + ($hasPlanCols ? [
            'fire_plan_id' => $plan->id ?? null, 'route_used' => $d['route_used'] ?? null,
            'route_note' => isset($d['route_note']) && trim($d['route_note']) !== '' ? trim($d['route_note']) : null,
            'attendance_count' => $d['attendance_count'] ?? null,
            'issues_found' => isset($d['issues_found']) && trim($d['issues_found']) !== '' ? trim($d['issues_found']) : null,
        ] : []));
        // A plan drawn or updated for this drill remembers it (the first drill only).
        if ($plan && empty($plan->drill_id)) DB::table('fire_plans')->where('id', $plan->id)->whereNull('drill_id')->update(['drill_id' => $id]);
        try {
            \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $agencyId, 'action' => 'drill.recorded', 'entity_type' => 'drill', 'entity_id' => $id,
                'payload' => json_encode(['summary' => DrillCadence::label($d['type']) . ' drill recorded at ' . ($centres[(int) $d['centre_id']] ?? 'a centre') . ', ' . $at->format('M j, Y g:i A')]),
                'ip_address' => substr((string) $request->ip(), 0, 45), 'created_at' => now()]);
        } catch (\Throwable $e) {}

        /* TELL THE PEOPLE WHO MANAGE IT (2026-11-03). Anthony: "yes add the bell and an email as well" - when an
           educator logs a drill, the centre's directors and the agency admins get a bell and an email, like a hazard
           report. Not the person who recorded it. A failure here never loses the drill: it is already saved. */
        try {
            $cid = (int) $d['centre_id'];
            $uid = (int) $request->user()->id;
            $to = HazardController::staffAt($agencyId, $cid, ['centre_director'], true)->where('id', '!=', $uid)
                // Roles switched off for drill notices hear nothing (Settings → Notification settings, 2026-11-09).
                ->filter(fn ($u) => \App\Support\StaffTopicNotify::allows($agencyId, 'drills', (int) $u->id));
            if ($to->isNotEmpty()) {
                $who = DB::table('users')->where('id', $uid)->first(['first_name', 'last_name']);
                $who = $who ? trim($who->first_name . ' ' . $who->last_name) : 'A staff member';
                $centre = (string) ($centres[$cid] ?? 'a centre');
                $label = DrillCadence::label($d['type']);
                $room = ! empty($d['room_id']) ? (string) DB::table('rooms')->where('id', $d['room_id'])->value('name') : '';
                $mmss = fn ($s) => $s === null ? null : intdiv((int) $s, 60) . ' min ' . str_pad((string) ((int) $s % 60), 2, '0', STR_PAD_LEFT) . ' s';
                $when = $at->format('D, M j, Y \a\t g:i A');
                $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
                $lines = array_filter([
                    'Drill' => $label,
                    'When' => $when,
                    'Room' => $room,
                    'Evacuation time' => $mmss($d['evacuation_seconds'] ?? null),
                    'Children' => isset($d['children_count']) ? (string) $d['children_count'] : null,
                    'Staff present' => $d['staff_present'] ?? null,
                    'Area' => $d['area'] ?? null,
                    'Led by' => $d['led_by'] ?? null,
                    'Child located in' => $mmss($d['located_seconds'] ?? null),
                    'Attendance' => isset($d['attendance_count']) ? (string) $d['attendance_count'] : null,
                    'Route used' => ! empty($d['route_used']) ? (['primary' => 'Primary route', 'alternate' => 'Alternate route', 'other' => 'Other'][$d['route_used']] ?? null)
                        . (! empty($d['route_note']) ? ' - ' . $d['route_note'] : '') : null,
                    'Fire plan' => $plan ? 'Version ' . $plan->version . ' (' . ['draft' => 'draft', 'submitted' => 'waiting for review', 'approved' => 'approved', 'changes' => 'changes asked for'][$plan->status] . ')' : null,
                ], fn ($v) => $v !== null && $v !== '');
                $box = implode('<br>', array_map(fn ($k, $v) => '<strong>' . $e($k) . ':</strong> ' . $e($v), array_keys($lines), $lines));
                $more = '';
                foreach (['Scenario' => 'scenario', 'Notes' => 'notes', 'Improvements' => 'improvements', 'Issues found' => 'issues_found'] as $k => $f) {
                    if (! empty($d[$f])) $more .= '<p style="margin:12px 0 0;font-size:14px;color:#334155"><b>' . $k . ':</b> ' . nl2br($e($d[$f])) . '</p>';
                }
                $html = \App\Services\EmailTemplate::wrap($agencyId,
                    '<p style="margin:0 0 12px;font-size:15px;color:#334155"><b>' . $e($who) . '</b> recorded a ' . $e(mb_strtolower($label)) . ' drill at <b>' . $e($centre) . '</b>.</p>'
                    . \App\Services\EmailTemplate::calloutBox($box, 'info') . $more
                    . '<p style="margin:14px 0 0;font-size:14px;color:#334155">It is in KiddieTrac → Drill log, kept for your licensing records.</p>',
                    ['eyebrow' => 'DRILL RECORDED', 'title' => $label . ' drill', 'subtitle' => $centre]);
                $body = $label . ' drill · ' . $when . ($room !== '' ? ' · ' . $room : '') . ' · recorded by ' . $who;
                foreach ($to as $u) {
                    \App\Support\Notify::write(['user_id' => (int) $u->id, 'type' => 'drill_recorded', 'title' => 'Drill recorded — ' . $centre,
                        'body' => $body, 'data' => json_encode(['link' => '#drills', 'drill_id' => $id])]);
                    if (filter_var((string) $u->email, FILTER_VALIDATE_EMAIL)) {
                        try { \App\Services\AgencyMailer::forAgency($agencyId)->html($html, fn ($m) => $m->to((string) $u->email)->subject($label . ' drill recorded at ' . $centre)); }
                        catch (\Throwable $ex) { report($ex); }
                    }
                }
            }
        } catch (\Throwable $ex) { report($ex); }

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
        // A director of THIS centre (2026-11-05 leak sweep): a director anywhere in the agency counted,
        // so someone who directs one centre and teaches at another could remove the other's drills.
        $manager = $this->canManageCentre($request, $agencyId, (int) $row->centre_id);
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

    /* ── CENTRE MAPS (2026-11-09, Anthony: "drill log should have ability to upload a map of the educators centre -
       wire this up"). The floor plan with exits and the meeting place, next to the drills it is used for. Anyone who
       can see the centre's drills may upload or replace it (educators run the drills); removing one is for a director
       or admin. Nothing is deleted: a replaced map keeps its row and file (replaced_at). The file sits in
       drill-maps/, a ProtectedMedia folder, so it is only ever handed out as a signed, expiring link. ── */

    /**
     * The current maps at the centres in $ids. EACH EDUCATOR'S MAP (2026-11-09, Anthony: "drill log in settings remove
     * the centres maps ... allow to be added from the educators kebab by management as optional"): a map now belongs to
     * one staff member (user_id) - their room's exits and meeting place. Rows with no user_id are the older centre-wide
     * maps; an educator with no map of their own is shown their centre's, if there is one.
     */
    private function maps(Request $request, int $agencyId, array $ids): array
    {
        if (! $ids || ! \Illuminate\Support\Facades\Schema::hasTable('drill_maps')) {
            return [];
        }
        $perUser = \Illuminate\Support\Facades\Schema::hasColumn('drill_maps', 'user_id');

        return DB::table('drill_maps as m')->leftJoin('users as u', 'u.id', '=', 'm.uploaded_by')
            ->where('m.agency_id', $agencyId)->whereIn('m.centre_id', $ids)->whereNull('m.replaced_at')
            ->orderByDesc('m.id')->get(array_merge(['m.id', 'm.centre_id', 'm.file_url', 'm.file_name', 'm.mime', 'm.created_at', 'u.first_name'], $perUser ? ['m.user_id'] : []))
            ->unique(fn ($m) => $m->centre_id . ':' . ($m->user_id ?? 0))
            ->map(fn ($m) => ['id' => (int) $m->id, 'centre_id' => (int) $m->centre_id, 'user_id' => isset($m->user_id) ? (int) $m->user_id : null,
                'url' => $m->file_url, 'file_name' => $m->file_name,
                'mime' => $m->mime, 'is_image' => str_starts_with((string) $m->mime, 'image/'),
                'uploaded_at' => $m->created_at, 'uploaded_by' => $m->first_name,
                'can_remove' => $this->canManageCentre($request, $agencyId, (int) $m->centre_id)])
            ->values()->all();
    }

    /** The centres where $uid works that are in view AND this caller manages. Empty = not theirs to change. */
    private function managedCentresOf(Request $request, int $agencyId, int $uid): array
    {
        $centres = $this->centres($request, $agencyId);
        $theirs = DB::table('role_assignments')->where('user_id', $uid)->where('active', true)
            ->whereIn('role', ['educator', 'centre_director'])->whereIn('centre_id', array_keys($centres) ?: [0])->pluck('centre_id')->map(fn ($c) => (int) $c)->unique()->all();

        return array_values(array_filter($theirs, fn ($cid) => $this->canManageCentre($request, $agencyId, $cid)));
    }

    /** POST /drills/maps (multipart: user_id, file) — management adds or replaces an educator's map. */
    public function uploadMap(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'file' => ['required', 'file', 'max:15360', 'mimes:jpg,jpeg,png,webp,pdf'],
        ], ['file.mimes' => 'Upload the map as a photo (JPG, PNG or WebP) or a PDF.', 'file.max' => 'The map must be 15 MB or smaller.']);
        $who = (int) $data['user_id'];
        $managed = $this->managedCentresOf($request, $agencyId, $who);
        abort_unless($managed, 403, 'Only a director of their centre or an admin can add an educator\'s map.');
        $cid = $managed[0];
        $centres = $this->centres($request, $agencyId);

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
        $name = 'staff-' . $who . '-' . now()->format('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        $file->storeAs('drill-maps/' . $agencyId, $name, 'public');
        $url = '/storage/drill-maps/' . $agencyId . '/' . $name;
        $uid = (int) $request->user()->id;

        $id = DB::transaction(function () use ($agencyId, $cid, $who, $url, $file, $uid) {
            DB::table('drill_maps')->where('agency_id', $agencyId)->where('user_id', $who)->whereNull('replaced_at')->lockForUpdate()
                ->update(['replaced_at' => now(), 'replaced_by' => $uid, 'updated_at' => now()]);

            return DB::table('drill_maps')->insertGetId(['agency_id' => $agencyId, 'centre_id' => $cid, 'user_id' => $who, 'file_url' => $url,
                'file_name' => mb_substr((string) $file->getClientOriginalName(), 0, 190), 'mime' => $file->getMimeType(),
                'size' => (int) $file->getSize(), 'uploaded_by' => $uid, 'created_at' => now(), 'updated_at' => now()]);
        });
        try {
            $first = DB::table('users')->where('id', $who)->value('first_name');
            \App\Support\Audit::write(['user_id' => $uid, 'agency_id' => $agencyId, 'action' => 'drill.map_uploaded', 'entity_type' => 'drill_map', 'entity_id' => $id,
                'payload' => json_encode(['summary' => 'Drill map added for ' . ($first ?: 'a staff member') . ' (' . ($centres[$cid] ?? 'their centre') . ')']), 'ip_address' => substr((string) $request->ip(), 0, 45), 'created_at' => now()]);
        } catch (\Throwable $e) {}

        return response()->json(['ok' => true, 'map' => collect($this->maps($request, $agencyId, [$cid]))->firstWhere('id', $id)]);
    }

    /** DELETE /drills/maps/{id} — a director of that centre or an admin takes the map down (the row and file are kept). */
    public function removeMap(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $row = DB::table('drill_maps')->where('id', $id)->where('agency_id', $agencyId)->whereNull('replaced_at')->first();
        abort_unless($row, 404);
        abort_unless(isset($this->centres($request, $agencyId)[(int) $row->centre_id]), 403);
        abort_unless($this->canManageCentre($request, $agencyId, (int) $row->centre_id), 403, 'Only a director or admin can remove a drill map.');
        DB::table('drill_maps')->where('id', $id)->update(['replaced_at' => now(), 'replaced_by' => (int) $request->user()->id, 'updated_at' => now()]);
        try {
            \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $agencyId, 'action' => 'drill.map_removed', 'entity_type' => 'drill_map', 'entity_id' => $id,
                'payload' => json_encode(['summary' => 'Centre map removed']), 'ip_address' => substr((string) $request->ip(), 0, 45), 'created_at' => now()]);
        } catch (\Throwable $e) {}

        return response()->json(['ok' => true]);
    }

    /* ── EDUCATORS' FIRE DRILL DATES (2026-11-09, Anthony: "the centre map should be a kebab that can view the map in
       the table for each educator and configure each educator to set the fire drill renewal dates and expiry dates").
       One row per staff member at the centres in view: their centre, when they last renewed, when it expires. A
       director of that centre or an agency admin sets the dates; an educator sees only their own row. ── */

    /** The staff at $ids with their dates. A manager sees everyone there; anyone else sees only themselves. */
    private function staffDates(Request $request, int $agencyId, array $ids, array $centres, string $tz): array
    {
        if (! $ids || ! \Illuminate\Support\Facades\Schema::hasTable('drill_staff_dates')) {
            return [];
        }
        $me = (int) $request->user()->id;
        $manage = [];
        foreach ($ids as $id) $manage[(int) $id] = $this->canManageCentre($request, $agencyId, (int) $id);
        $rows = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.active', true)->whereIn('ra.role', ['educator', 'centre_director'])->whereIn('ra.centre_id', $ids)
            ->whereNull('u.deleted_at')
            ->orderBy('u.first_name')->orderBy('u.last_name')
            ->get(['ra.user_id', 'ra.centre_id', 'ra.role', 'u.first_name', 'u.last_name']);
        $hasSched = \Illuminate\Support\Facades\Schema::hasColumn('drill_staff_dates', 'sched_repeat');
        $dates = DB::table('drill_staff_dates')->where('agency_id', $agencyId)->whereIn('user_id', $rows->pluck('user_id')->unique()->all() ?: [0])
            ->get(array_merge(['user_id', 'renewed_on', 'expires_on', 'note', 'updated_at'],
                $hasSched ? ['sched_repeat', 'sched_weekday', 'sched_week', 'sched_date', 'sched_time'] : []))->keyBy('user_id');
        $maps = collect($this->maps($request, $agencyId, $ids));
        $now = Carbon::now($tz);
        $today = Carbon::now($tz)->toDateString();
        $soon = Carbon::now($tz)->addDays(30)->toDateString();
        $out = [];
        foreach ($rows->groupBy('user_id') as $uid => $theirs) {
            $uid = (int) $uid;
            $mine = $theirs->filter(fn ($r) => isset($centres[(int) $r->centre_id]));
            $canEdit = $mine->contains(fn ($r) => ! empty($manage[(int) $r->centre_id]));
            if (! $canEdit && $uid !== $me) continue;            // educators: their own row only
            $first = $mine->first();
            if (! $first) continue;
            $d = $dates->get($uid);
            $exp = $d->expires_on ?? null;
            $out[] = [
                'user_id' => $uid,
                'name' => trim(($first->first_name ?? '') . ' ' . ($first->last_name ?? '')),
                'role' => $mine->contains(fn ($r) => $r->role === 'centre_director') ? 'Director' : 'Educator',
                'centre_id' => (int) $first->centre_id,
                'centre_name' => $mine->map(fn ($r) => $centres[(int) $r->centre_id] ?? null)->filter()->unique()->implode(', '),
                'renewed_on' => $d->renewed_on ?? null,
                'expires_on' => $exp,
                'note' => $d->note ?? null,
                'state' => ! $exp ? 'none' : ($exp < $today ? 'expired' : ($exp <= $soon ? 'due_soon' : 'ok')),
                'can_edit' => $canEdit,
                // Their own map, else their centre's older centre-wide one (own = false: nothing to remove for them).
                'map' => ($own = $maps->first(fn ($m) => $m['user_id'] === $uid))
                    ? $own + ['own' => true]
                    : (($c = $maps->first(fn ($m) => $m['user_id'] === null && $mine->contains(fn ($r) => (int) $r->centre_id === $m['centre_id']))) ? $c + ['own' => false] : null),
                'schedule' => $d && \App\Support\DrillSchedule::has($d) ? [
                    'repeat' => $d->sched_repeat, 'weekday' => $d->sched_weekday !== null ? (int) $d->sched_weekday : null,
                    'week' => $d->sched_week !== null ? (int) $d->sched_week : null, 'date' => $d->sched_date, 'time' => $d->sched_time,
                    'label' => \App\Support\DrillSchedule::label($d),
                ] : null,
                'next' => ($n = $d ? \App\Support\DrillSchedule::next($d, $now, $tz) : null) ? $n->format('Y-m-d H:i') : null,
                'next_label' => $n ? $n->format('D, M j · g:i A') : null,
            ];
        }

        return $out;
    }

    /** POST /drills/staff-dates { user_id, renewed_on, expires_on, note } — a director of their centre or an admin. */
    public function staffDatesSave(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $d = $request->validate([
            'user_id' => ['required', 'integer'],
            'renewed_on' => ['nullable', 'date_format:Y-m-d'],
            'expires_on' => ['nullable', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:190'],
        ], ['renewed_on.date_format' => 'Pick the renewal date from the calendar.', 'expires_on.date_format' => 'Pick the expiry date from the calendar.']);
        if (! empty($d['renewed_on']) && ! empty($d['expires_on']) && $d['expires_on'] < $d['renewed_on']) {
            return response()->json(['message' => 'The expiry date must be on or after the renewal date.'], 422);
        }
        $uid = (int) $d['user_id'];
        $centres = $this->centres($request, $agencyId);
        $theirCentres = DB::table('role_assignments')->where('user_id', $uid)->where('active', true)
            ->whereIn('role', ['educator', 'centre_director'])->whereIn('centre_id', array_keys($centres) ?: [0])->pluck('centre_id')->all();
        abort_unless($theirCentres, 404, 'That person does not work at one of your centres.');
        $ok = false;
        foreach ($theirCentres as $cid) { if ($this->canManageCentre($request, $agencyId, (int) $cid)) { $ok = true; break; } }
        abort_unless($ok, 403, 'Only a director of their centre or an admin can set these dates.');

        DB::table('drill_staff_dates')->updateOrInsert(['agency_id' => $agencyId, 'user_id' => $uid], [
            'renewed_on' => $d['renewed_on'] ?? null, 'expires_on' => $d['expires_on'] ?? null,
            'note' => isset($d['note']) && trim($d['note']) !== '' ? trim($d['note']) : null,
            'updated_by' => (int) $request->user()->id, 'updated_at' => now(), 'created_at' => now(),
        ]);
        try {
            $who = DB::table('users')->where('id', $uid)->value('first_name');
            \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $agencyId, 'action' => 'drill.staff_dates_set', 'entity_type' => 'user', 'entity_id' => $uid,
                'payload' => json_encode(['summary' => 'Fire drill dates set for ' . ($who ?: 'a staff member') . ': renewed ' . ($d['renewed_on'] ?? '—') . ', expires ' . ($d['expires_on'] ?? '—')]),
                'ip_address' => substr((string) $request->ip(), 0, 45), 'created_at' => now()]);
        } catch (\Throwable $e) {}

        return response()->json(['ok' => true]);
    }

    /**
     * POST /drills/staff-schedule { user_id, repeat, weekday, week, date, time } — set (or with repeat "" clear) when this
     * person runs their fire drill. A director of their centre or an admin. App\Support\DrillSchedule does the dates.
     */
    public function staffScheduleSave(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $d = $request->validate([
            'user_id' => ['required', 'integer'],
            'repeat' => ['nullable', 'in:' . implode(',', array_keys(\App\Support\DrillSchedule::REPEATS))],
            'weekday' => ['nullable', 'integer', 'between:1,7'],
            'week' => ['nullable', 'integer', 'between:1,5'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'time' => ['nullable', 'date_format:H:i'],
        ], ['time.date_format' => 'Pick the time of the drill.', 'date.date_format' => 'Pick the date from the calendar.']);
        $uid = (int) $d['user_id'];
        abort_unless($this->managedCentresOf($request, $agencyId, $uid), 403, 'Only a director of their centre or an admin can set this schedule.');
        $repeat = (string) ($d['repeat'] ?? '');
        $row = ['sched_repeat' => null, 'sched_weekday' => null, 'sched_week' => null, 'sched_date' => null, 'sched_time' => null];
        if ($repeat !== '') {
            if (empty($d['time'])) return response()->json(['message' => 'Pick the time of the drill.'], 422);
            if ($repeat === 'once' && empty($d['date'])) return response()->json(['message' => 'Pick the date of the drill.'], 422);
            if ($repeat !== 'once' && empty($d['weekday'])) return response()->json(['message' => 'Pick the day of the week.'], 422);
            $row = ['sched_repeat' => $repeat, 'sched_time' => $d['time'],
                'sched_weekday' => $repeat === 'once' ? null : (int) $d['weekday'],
                'sched_week' => in_array($repeat, ['monthly', 'every_2_months', 'quarterly'], true) ? (int) ($d['week'] ?? 1) : null,
                'sched_date' => in_array($repeat, ['once', 'every_2_months', 'quarterly'], true) ? ($d['date'] ?? null) : null];
            if ($repeat === 'once') {
                $tz = AgencyTime::tz($agencyId);
                if (Carbon::createFromFormat('Y-m-d H:i', $d['date'] . ' ' . $d['time'], $tz)->lt(Carbon::now($tz))) {
                    return response()->json(['message' => 'That date and time has already passed.'], 422);
                }
            }
        }
        $exists = DB::table('drill_staff_dates')->where('agency_id', $agencyId)->where('user_id', $uid)->exists();
        DB::table('drill_staff_dates')->updateOrInsert(['agency_id' => $agencyId, 'user_id' => $uid],
            $row + ['updated_by' => (int) $request->user()->id, 'updated_at' => now()] + ($exists ? [] : ['created_at' => now()]));
        $saved = DB::table('drill_staff_dates')->where('agency_id', $agencyId)->where('user_id', $uid)->first();
        $label = \App\Support\DrillSchedule::label($saved);
        try {
            $who = DB::table('users')->where('id', $uid)->value('first_name');
            \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $agencyId, 'action' => 'drill.staff_schedule_set', 'entity_type' => 'user', 'entity_id' => $uid,
                'payload' => json_encode(['summary' => 'Fire drill schedule for ' . ($who ?: 'a staff member') . ': ' . ($label ?: 'cleared')]),
                'ip_address' => substr((string) $request->ip(), 0, 45), 'created_at' => now()]);
        } catch (\Throwable $e) {}

        return response()->json(['ok' => true, 'label' => $label]);
    }

    /** True for an agency admin of this agency or a platform admin. */
    private function isAgencyAdmin(Request $request): bool
    {
        return \App\Support\AgencyRole::constrain(DB::table('role_assignments'))->where('user_id', $request->user()->id)->where('active', true)
            ->whereIn('role', ['agency_admin', 'platform_admin'])->exists();
    }

    /** What the drill Settings tab shows under Notifications. */
    private function notifyPayload(int $agencyId): array
    {
        return \App\Support\DrillSchedule::notifySettings($agencyId) + ['roles' => \App\Support\StaffTopicNotify::settings($agencyId)['drills'],
            'role_labels' => \App\Support\StaffTopicNotify::ROLES, 'leads' => \App\Support\DrillSchedule::LEADS];
    }

    /**
     * POST /drills/notify-settings { educator_reminder, educator_lead, admin_upcoming, admin_missed, roles: {educator,
     * centre_director, agency_admin} } — who hears about drills. roles is the same switch as Settings → Notification
     * settings → drills (StaffTopicNotify); only the drills half is touched here. Agency admins only.
     */
    public function notifySettingsSave(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        abort_unless($this->isAgencyAdmin($request), 403, 'Agency admins only.');
        $d = $request->validate([
            'educator_reminder' => ['sometimes', 'boolean'],
            'educator_lead' => ['sometimes', 'in:' . implode(',', array_keys(\App\Support\DrillSchedule::LEADS))],
            'admin_upcoming' => ['sometimes', 'boolean'],
            'admin_missed' => ['sometimes', 'boolean'],
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['boolean'],
        ]);
        \App\Support\DrillSchedule::saveNotifySettings($agencyId, $d);
        if (isset($d['roles'])) {
            $all = \App\Support\StaffTopicNotify::settings($agencyId);
            foreach (array_keys(\App\Support\StaffTopicNotify::ROLES) as $r) if (array_key_exists($r, $d['roles'])) $all['drills'][$r] = (bool) $d['roles'][$r];
            \App\Support\StaffTopicNotify::save($agencyId, $all);
        }
        try {
            \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $agencyId, 'action' => 'drill.notify_settings', 'entity_type' => 'agency', 'entity_id' => $agencyId,
                'payload' => json_encode(['summary' => 'Drill notification settings changed']), 'ip_address' => substr((string) $request->ip(), 0, 45), 'created_at' => now()]);
        } catch (\Throwable $e) {}

        return response()->json(['ok' => true, 'notify' => $this->notifyPayload($agencyId)]);
    }

    /** POST /drills/reminders { enabled } — admins/directors switch the monthly reminder on or off. */
    public function reminders(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        abort_unless(\App\Support\AgencyRole::constrain(DB::table('role_assignments'))->where('user_id', $request->user()->id)->where('active', true)
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

    /** A centre's switched-off drills for the screen: the reason, who and when (names read now, not stored). */
    private function offFor(int $centreId, string $tz): array
    {
        $out = [];
        foreach (DrillCadence::off($centreId) as $type => $o) {
            $u = ! empty($o['by']) ? DB::table('users')->where('id', (int) $o['by'])->first(['first_name', 'last_name']) : null;
            $out[$type] = ['label' => DrillCadence::label((string) $type) . ' drill', 'reason' => (string) ($o['reason'] ?? ''),
                'by_name' => $u ? trim($u->first_name . ' ' . $u->last_name) : '',
                'at' => ! empty($o['at']) ? Carbon::parse((string) $o['at'], 'UTC')->setTimezone($tz)->format('M j, Y') : ''];
        }

        return $out;
    }

    /**
     * POST /drills/requirement { centre_id, type, required, reason } (2026-11-10). An admin or this centre's director marks
     * a required drill "not required here" (a reason is kept, and audited) or makes it required again. See DrillCadence::off.
     */
    public function requirement(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $d = $request->validate(['centre_id' => ['required', 'integer'], 'type' => ['required', 'string', 'in:' . implode(',', array_keys(DrillCadence::types($agencyId)))],
            'required' => ['required', 'boolean'], 'reason' => ['nullable', 'string', 'max:300']]);
        $cid = (int) $d['centre_id'];
        abort_unless(isset($this->centres($request, $agencyId)[$cid]) && $this->canManageCentre($request, $agencyId, $cid), 403, 'Only an admin or this centre\'s director can change this.');
        $reason = trim((string) ($d['reason'] ?? ''));
        if (! $d['required'] && $reason === '') {
            return response()->json(['message' => 'Say why this drill is not required here - it is kept with the drill records.'], 422);
        }
        $uid = (int) $request->user()->id;
        DB::transaction(function () use ($cid, $d, $reason, $uid) {   // re-read under a lock: only this key changes
            $raw = DB::table('centres')->where('id', $cid)->lockForUpdate()->value('settings');
            $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
            $off = is_array($s['drill_off'] ?? null) ? $s['drill_off'] : [];
            if ($d['required']) unset($off[$d['type']]);
            else $off[$d['type']] = ['reason' => $reason, 'by' => $uid, 'at' => now()->utc()->toDateTimeString()];
            if ($off) $s['drill_off'] = $off; else unset($s['drill_off']);
            DB::table('centres')->where('id', $cid)->update(['settings' => json_encode($s), 'updated_at' => now()]);
        });
        $label = DrillCadence::label($d['type']) . ' drill';
        try {
            \App\Support\Audit::write(['user_id' => $uid, 'agency_id' => $agencyId, 'action' => $d['required'] ? 'drill.requirement_on' : 'drill.requirement_off',
                'entity_type' => 'centre', 'entity_id' => $cid,
                'payload' => json_encode(['summary' => $d['required'] ? $label . ' made required again' : $label . ' marked not required: ' . $reason]),
                'ip_address' => substr((string) $request->ip(), 0, 45), 'created_at' => now()]);
        } catch (\Throwable $e) {}

        return response()->json(['ok' => true]);
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
        abort_unless(\App\Support\AgencyRole::constrain(DB::table('role_assignments'))->where('user_id', $request->user()->id)->where('active', true)
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
