<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use App\Support\CentreScope;
use App\Support\FirePlans;
use App\Support\Live;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * FIRE PLANS (2026-11-28) - the Fire plan tab of the Drill log. See App\Support\FirePlans for the model.
 *
 * WHO (the same reader as the Drill log, fail closed):
 *   - agency / platform admins: every open centre of the ACTIVE agency;
 *   - directors and educators: their own centres (CentreScope::ids), open ones only (Live::centreIds);
 *   - parents: nothing (the route group has no guardian role; there is no drill-sharing setting to honour).
 * Anyone in scope may draw and submit a plan for a centre or room they see. Review (approve / ask for changes) is a
 * director of THAT centre or an agency admin - never a platform admin: an approved plan carries the agency's own
 * people (kiddietrac-super-admin-not-agency-staff). A draft is its author's alone to change or delete.
 */
final class FirePlanController extends Controller
{
    /* ── scope: the same three rules as DrillController (agencyId / centres / canManageCentre) ── */

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

    /** An agency admin of this agency, or a director of this centre. Platform admins look, but never approve. */
    private function canReview(Request $request, int $agencyId, int $centreId): bool
    {
        $roles = DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)->get(['role', 'agency_id', 'centre_id']);
        if ($roles->contains(fn ($r) => $r->role === 'platform_admin')) return false;
        foreach ($roles as $r) {
            if ($r->role === 'agency_admin' && (int) $r->agency_id === $agencyId) return true;
            if ($r->role === 'centre_director' && (int) $r->centre_id === $centreId) return true;
        }

        return false;
    }

    private function isAgencyAdmin(Request $request): bool
    {
        return \App\Support\AgencyRole::constrain(DB::table('role_assignments'))->where('user_id', $request->user()->id)->where('active', true)
            ->whereIn('role', ['agency_admin', 'platform_admin'])->exists();
    }

    /** One plan row the caller may see (this agency, an open centre in scope, not deleted), or 404. */
    private function planOr404(Request $request, int $agencyId, int $id): object
    {
        $row = DB::table('fire_plans')->where('id', $id)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        abort_unless($row && isset($this->centres($request, $agencyId)[(int) $row->centre_id]), 404, 'That fire plan was not found.');
        // A draft is its author's until it is submitted.
        abort_if($row->status === 'draft' && (int) $row->created_by !== (int) $request->user()->id, 404, 'That fire plan was not found.');

        return $row;
    }

    private function audit(Request $request, int $agencyId, string $action, int $id, string $summary): void
    {
        try {
            \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $agencyId, 'action' => $action, 'entity_type' => 'fire_plan', 'entity_id' => $id,
                'payload' => json_encode(['summary' => $summary]), 'ip_address' => substr((string) $request->ip(), 0, 45), 'created_at' => now()]);
        } catch (\Throwable $e) {}
    }

    private function placeName(object $row, array $centres): string
    {
        $c = (string) ($centres[(int) $row->centre_id] ?? 'A centre');
        $room = $row->room_id ? (string) DB::table('rooms')->where('id', $row->room_id)->value('name') : '';

        return $room !== '' ? $c . ' · ' . $room : $c;
    }

    /** One version as the list and the popup show it. */
    private function summary(Request $request, int $agencyId, object $r, array $centres, array $names, array $currentIds, string $tz): array
    {
        $me = (int) $request->user()->id;
        $local = fn ($t) => $t ? Carbon::parse($t, 'UTC')->setTimezone($tz)->format('Y-m-d H:i') : null;

        return [
            'id' => (int) $r->id, 'version' => (int) $r->version, 'status' => (string) $r->status, 'title' => (string) ($r->title ?? ''),
            'centre_id' => (int) $r->centre_id, 'room_id' => $r->room_id !== null ? (int) $r->room_id : null,
            'centre_name' => (string) ($centres[(int) $r->centre_id] ?? ''), 'room_name' => $r->room_id ? (string) ($names['room'][(int) $r->room_id] ?? '') : '',
            'created_by' => (int) $r->created_by, 'created_by_name' => (string) ($names['user'][(int) $r->created_by] ?? ''),
            'submitted_at' => $local($r->submitted_at), 'reviewed_at' => $local($r->reviewed_at),
            'reviewed_by_name' => $r->reviewed_by ? (string) ($names['user'][(int) $r->reviewed_by] ?? '') : '',
            'review_comment' => (string) ($r->review_comment ?? ''), 'updated_at' => $local($r->updated_at),
            'reviewed_on' => FirePlans::reviewedOn($r), 'has_png' => ! empty($r->png_path), 'has_underlay' => ! empty($r->underlay_path),
            'drill_id' => $r->drill_id ? (int) $r->drill_id : null, 'based_on_id' => $r->based_on_id ? (int) $r->based_on_id : null,
            'is_current' => in_array((int) $r->id, $currentIds, true),
            'is_mine' => (int) $r->created_by === $me,
            'can_review' => $r->status === 'submitted' && $this->canReview($request, $agencyId, (int) $r->centre_id),
            'can_edit_draft' => $r->status === 'draft' && (int) $r->created_by === $me,
        ];
    }

    /** Names for a batch of rows: [user => [id => full name], room => [id => name]]. */
    private function names($rows): array
    {
        $uids = collect($rows)->flatMap(fn ($r) => [$r->created_by, $r->reviewed_by, $r->submitted_by])->filter()->map(fn ($v) => (int) $v)->unique()->all();
        $rids = collect($rows)->pluck('room_id')->filter()->map(fn ($v) => (int) $v)->unique()->all();

        return [
            'user' => DB::table('users')->whereIn('id', $uids ?: [0])->get(['id', 'first_name', 'last_name'])->mapWithKeys(fn ($u) => [(int) $u->id => trim($u->first_name . ' ' . $u->last_name)])->all(),
            'room' => DB::table('rooms')->whereIn('id', $rids ?: [0])->pluck('name', 'id')->mapWithKeys(fn ($n, $i) => [(int) $i => (string) $n])->all(),
        ];
    }

    /** The newest approved version of each place (centre + room). */
    private function currentIds(array $centreIds): array
    {
        return DB::table('fire_plans')->whereIn('centre_id', $centreIds ?: [0])->whereNull('deleted_at')->where('status', 'approved')
            ->selectRaw('MAX(id) as id')->groupBy('centre_id', 'room_id')->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    /** GET /drills/fire-plans?centre_id= - every place's plan and versions, the review queue, the steps template. */
    public function index(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $centres = $this->centres($request, $agencyId);
        $tz = AgencyTime::tz($agencyId);
        $cid = (int) $request->input('centre_id');
        $ids = $cid && isset($centres[$cid]) ? [$cid] : array_keys($centres);
        $me = (int) $request->user()->id;

        $rows = DB::table('fire_plans')->where('agency_id', $agencyId)->whereIn('centre_id', $ids ?: [0])->whereNull('deleted_at')
            ->where(fn ($w) => $w->where('status', '!=', 'draft')->orWhere('created_by', $me))
            ->orderByDesc('version')->orderByDesc('id')
            ->get(['id', 'centre_id', 'room_id', 'version', 'status', 'title', 'details', 'png_path', 'underlay_path', 'drill_id', 'based_on_id',
                'created_by', 'submitted_by', 'submitted_at', 'reviewed_by', 'reviewed_at', 'review_comment', 'updated_at']);
        $names = $this->names($rows);
        $current = $this->currentIds($ids);
        $list = $rows->map(fn ($r) => $this->summary($request, $agencyId, $r, $centres, $names, $current, $tz));

        // Group by place: newest first; the current (approved) one named.
        $places = [];
        foreach ($list as $p) {
            $key = $p['centre_id'] . ':' . ($p['room_id'] ?? 0);
            if (! isset($places[$key])) $places[$key] = ['key' => $key, 'centre_id' => $p['centre_id'], 'room_id' => $p['room_id'], 'centre_name' => $p['centre_name'], 'room_name' => $p['room_name'], 'versions' => []];
            $places[$key]['versions'][] = $p;
        }
        $yearAgo = Carbon::now($tz)->subYear()->toDateString();
        foreach ($places as &$pl) {
            $cur = collect($pl['versions'])->firstWhere('is_current', true);
            $pl['current_id'] = $cur['id'] ?? null;
            $pl['review_due'] = $cur ? (! $cur['reviewed_on'] || $cur['reviewed_on'] < $yearAgo) : false;
            $pl['pending'] = collect($pl['versions'])->where('status', 'submitted')->count();
        }
        unset($pl);

        $staff = [];
        foreach ($ids as $c) $staff[$c] = FirePlans::staffAt($agencyId, (int) $c);

        return response()->json([
            'timezone' => $tz,
            'centres' => collect($centres)->map(fn ($n, $i) => ['id' => (int) $i, 'name' => $n])->values(),
            'rooms' => DB::table('rooms')->whereIn('centre_id', $ids ?: [0])->where(fn ($w) => $w->where('active', 1)->orWhereNull('active'))->orderBy('name')->get(['id', 'centre_id', 'name']),
            'places' => array_values($places),
            'queue' => $list->where('can_review', true)->values(),
            'template' => FirePlans::template($agencyId),
            'default_template' => FirePlans::defaultTemplate(),
            'staff' => (object) $staff,
            'is_admin' => $this->isAgencyAdmin($request),
            'can_review_any' => collect($ids)->contains(fn ($c) => $this->canReview($request, $agencyId, (int) $c)),
        ]);
    }

    /** GET /drills/fire-plans/{id} - one version in full, the other versions of its place, and what the printout needs. */
    public function show(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $row = $this->planOr404($request, $agencyId, $id);
        $centres = $this->centres($request, $agencyId);
        $tz = AgencyTime::tz($agencyId);
        $me = (int) $request->user()->id;
        $siblings = DB::table('fire_plans')->where('agency_id', $agencyId)->where('centre_id', $row->centre_id)
            ->where(fn ($w) => $row->room_id === null ? $w->whereNull('room_id') : $w->where('room_id', $row->room_id))
            ->whereNull('deleted_at')->where(fn ($w) => $w->where('status', '!=', 'draft')->orWhere('created_by', $me))
            ->orderByDesc('version')->get();
        $names = $this->names($siblings);
        $current = $this->currentIds([(int) $row->centre_id]);
        $details = json_decode((string) $row->details, true) ?: [];

        return response()->json([
            'plan' => $this->summary($request, $agencyId, $row, $centres, $names, $current, $tz) + [
                'drawing' => json_decode((string) $row->drawing, true),
                'tree' => FirePlans::cleanTree(json_decode((string) $row->tree, true) ?: []),
                'details' => $details,
            ],
            'versions' => $siblings->map(fn ($r) => $this->summary($request, $agencyId, $r, $centres, $names, $current, $tz))->values(),
            'doc' => $this->doc($agencyId, $row, $details, $centres),
        ]);
    }

    /**
     * What the printed plan carries (house document: the agency's logo, name and the centre's address on top; staff
     * by FULL name, as report cards are; never a platform admin or integration account).
     */
    private function doc(int $agencyId, object $row, array $details, array $centres): array
    {
        $agency = DB::table('agencies')->where('id', $agencyId)->first();
        $centre = DB::table('centres')->where('id', $row->centre_id)->first();
        $brand = \App\Support\Brand::forAgency($agencyId);
        $color = (string) ($brand['primary_color'] ?? '');
        if (! preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) $color = '#1F6080';
        $logo = ($agency->brand_logo_url ?? null) ?: (($agency->logo_url ?? null) ?: ($centre->logo_url ?? null));
        if ($logo && ! preg_match('~^(https?:|data:)~i', (string) $logo)) {   // a local file: inline it, as the report card does
            $local = realpath(base_path('../parent-portal/' . ltrim((string) $logo, '/')));
            $root = realpath(base_path('../parent-portal'));
            if ($local && $root && str_starts_with($local, $root) && is_file($local) && filesize($local) < 2000000) {
                $mime = function_exists('mime_content_type') ? (mime_content_type($local) ?: 'image/png') : 'image/png';
                $logo = str_starts_with($mime, 'image/') ? 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($local)) : null;
            } else {
                $logo = 'https://app.kiddietrac.com/' . ltrim((string) $logo, '/');
            }
        }
        $address = trim(implode(', ', array_filter([
            trim(($centre->address_line1 ?? '') . ' ' . ($centre->address_line2 ?? '')),
            $centre->city ?? null, trim(($centre->province ?? '') . ' ' . ($centre->postal_code ?? '')),
        ], fn ($v) => trim((string) $v) !== '')));
        $staff = [];
        foreach ((array) ($details['staff_ids'] ?? []) as $sid) { if ($n = FirePlans::agencyPersonName((int) $sid)) $staff[] = $n; }

        return [
            'agency_name' => (string) ($agency->name ?? ''), 'logo' => $logo ?: null, 'color' => $color,
            'centre_name' => (string) ($centres[(int) $row->centre_id] ?? ($centre->name ?? '')), 'address' => $address, 'phone' => (string) ($centre->phone ?? ''),
            'licence' => (string) ($centre->license_number ?? ''),
            'staff' => $staff,
            'prepared_by' => FirePlans::agencyPersonName((int) ($row->submitted_by ?: $row->created_by)),
            'approved_by' => $row->status === 'approved' ? FirePlans::agencyPersonName((int) $row->reviewed_by) : null,
            'white_label' => (bool) ($brand['white_label'] ?? false),
        ];
    }

    /**
     * POST /drills/fire-plans { id?, based_on_id?, centre_id, room_id, title, drawing, png, underlay, keep_underlay,
     * tree, details, submit } - save. With `id` (the caller's own draft) it changes that draft; otherwise it is the
     * place's next version. submit: true sends it for review (it never changes after that).
     */
    public function store(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $centres = $this->centres($request, $agencyId);
        $d = $request->validate([
            'id' => ['nullable', 'integer'],
            'based_on_id' => ['nullable', 'integer'],
            'centre_id' => ['required', 'integer'],
            'room_id' => ['nullable', 'integer'],
            'title' => ['nullable', 'string', 'max:190'],
            'drawing' => ['nullable', 'array'],
            'png' => ['nullable', 'string', 'max:12000000'],
            'underlay' => ['nullable', 'string', 'max:12000000'],
            'keep_underlay' => ['nullable', 'boolean'],
            'tree' => ['nullable', 'array'],
            'details' => ['nullable', 'array'],
            'submit' => ['nullable', 'boolean'],
        ]);
        $cid = (int) $d['centre_id'];
        abort_unless(isset($centres[$cid]), 403, 'That centre is not one of yours.');
        $roomId = ! empty($d['room_id']) ? (int) $d['room_id'] : null;
        if ($roomId) abort_unless(DB::table('rooms')->where('id', $roomId)->where('centre_id', $cid)->exists(), 422, 'That room is not in this centre.');
        $uid = (int) $request->user()->id;
        $submit = (bool) ($d['submit'] ?? false);

        $draft = null;
        if (! empty($d['id'])) {
            $draft = DB::table('fire_plans')->where('id', (int) $d['id'])->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
            abort_unless($draft && $draft->status === 'draft' && (int) $draft->created_by === $uid, 422, 'Only your own draft can be changed. Saving makes a new version instead.');
            abort_unless((int) $draft->centre_id === $cid && ($draft->room_id === null ? null : (int) $draft->room_id) === $roomId, 422, 'A draft stays with its centre and room.');
        }
        $base = null;
        if (! empty($d['based_on_id'])) {
            $base = DB::table('fire_plans')->where('id', (int) $d['based_on_id'])->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
            abort_unless($base && (int) $base->centre_id === $cid, 422, 'That plan is not from this centre.');
        }

        $drawing = FirePlans::cleanDrawing($d['drawing'] ?? null);
        $tree = FirePlans::cleanTree($d['tree'] ?? FirePlans::template($agencyId));
        $details = FirePlans::cleanDetails($d['details'] ?? [], array_column(FirePlans::staffAt($agencyId, $cid), 'id'));
        if ($submit) {
            $hasDrawing = $drawing && count($drawing['items']) > 0;
            if (! $hasDrawing && empty($d['png'])) return response()->json(['message' => 'Draw the plan first: at least the walls, the exits and the meeting place.'], 422);
            $filled = collect($tree['steps'])->filter(fn ($s) => trim($s['who'] . $s['what'] . $s['where']) !== '')->count();
            if ($filled < 3) return response()->json(['message' => 'Fill in the steps of the plan (who, what and where) before you submit it.'], 422);
        }

        $png = FirePlans::storeDataUrl($agencyId, $d['png'] ?? null, 'plan', ['image/png' => 'png'], 8 * 1048576);
        $underlay = FirePlans::storeDataUrl($agencyId, $d['underlay'] ?? null, 'underlay', ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'], 8 * 1048576);
        if (! $underlay && ! empty($d['keep_underlay'])) $underlay = $draft->underlay_path ?? ($base->underlay_path ?? null);

        $title = trim((string) ($d['title'] ?? ''));
        $row = [
            'title' => $title !== '' ? mb_substr($title, 0, 190) : null,
            'drawing' => $drawing ? json_encode($drawing) : null,
            'tree' => json_encode($tree), 'details' => json_encode($details),
            'underlay_path' => $underlay, 'updated_at' => now(),
        ] + ($png ? ['png_path' => $png] : []) + ($submit ? ['status' => 'submitted', 'submitted_by' => $uid, 'submitted_at' => now()] : []);

        if ($draft) {
            DB::table('fire_plans')->where('id', $draft->id)->update($row);
            $id = (int) $draft->id;
        } else {
            $id = DB::transaction(function () use ($agencyId, $cid, $roomId, $row, $uid, $base, $png, $submit) {
                $q = DB::table('fire_plans')->where('centre_id', $cid)->where(fn ($w) => $roomId === null ? $w->whereNull('room_id') : $w->where('room_id', $roomId))->lockForUpdate();
                $next = ((int) $q->max('version')) + 1;
                return (int) DB::table('fire_plans')->insertGetId($row + [
                    'agency_id' => $agencyId, 'centre_id' => $cid, 'room_id' => $roomId, 'version' => $next,
                    'status' => $submit ? 'submitted' : 'draft', 'based_on_id' => $base->id ?? null,
                    'png_path' => $png ?: ($base->png_path ?? null),
                    'created_by' => $uid, 'created_at' => now(),
                ]);
            });
        }
        $saved = DB::table('fire_plans')->where('id', $id)->first();
        $this->audit($request, $agencyId, $submit ? 'fire_plan.submitted' : 'fire_plan.saved', $id,
            ($submit ? 'Fire plan submitted for review: ' : 'Fire plan draft saved: ') . $this->placeName($saved, $centres) . ', version ' . $saved->version);

        return response()->json(['ok' => true, 'id' => $id, 'version' => (int) $saved->version, 'status' => $saved->status]);
    }

    /** POST /drills/fire-plans/{id}/review { decision: approve|changes, comment } - a director of that centre or an agency admin. */
    public function review(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $row = $this->planOr404($request, $agencyId, $id);
        abort_unless($this->canReview($request, $agencyId, (int) $row->centre_id), 403, 'Only a director of this centre or an agency admin can review a fire plan.');
        $d = $request->validate(['decision' => ['required', 'in:approve,changes'], 'comment' => ['nullable', 'string', 'max:2000']]);
        abort_unless($row->status === 'submitted', 422, 'Only a plan waiting for review can be reviewed.');
        $comment = trim((string) ($d['comment'] ?? ''));
        if ($d['decision'] === 'changes' && $comment === '') return response()->json(['message' => 'Say what needs to change - the educator sees your comment with the plan.'], 422);
        DB::table('fire_plans')->where('id', $id)->where('status', 'submitted')->update([
            'status' => $d['decision'] === 'approve' ? 'approved' : 'changes', 'reviewed_by' => (int) $request->user()->id, 'reviewed_at' => now(),
            'review_comment' => $comment !== '' ? $comment : null, 'updated_at' => now(),
        ]);
        $this->audit($request, $agencyId, $d['decision'] === 'approve' ? 'fire_plan.approved' : 'fire_plan.changes_requested', $id,
            ($d['decision'] === 'approve' ? 'Fire plan approved: ' : 'Changes asked for on the fire plan: ') . $this->placeName($row, $this->centres($request, $agencyId)) . ', version ' . $row->version);

        return response()->json(['ok' => true]);
    }

    /** DELETE /drills/fire-plans/{id} - the author's own draft only (a submitted plan is a record and stays). */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $row = $this->planOr404($request, $agencyId, $id);
        abort_unless($row->status === 'draft' && (int) $row->created_by === (int) $request->user()->id, 422, 'Only your own draft can be deleted. Submitted plans are kept with the drill records.');
        DB::table('fire_plans')->where('id', $id)->update(['deleted_at' => now(), 'updated_at' => now()]);
        $this->audit($request, $agencyId, 'fire_plan.draft_deleted', $id, 'Fire plan draft deleted: ' . $this->placeName($row, $this->centres($request, $agencyId)));

        return response()->json(['ok' => true]);
    }

    /** GET /drills/fire-plans/{id}/file/{kind} - the PNG snapshot or the traced-over photo, to someone who may see the plan. */
    public function file(Request $request, int $id, string $kind)
    {
        $agencyId = $this->agencyId($request);
        $row = $this->planOr404($request, $agencyId, $id);
        $abs = FirePlans::filePath($agencyId, $kind === 'underlay' ? $row->underlay_path : ($kind === 'png' ? $row->png_path : null));
        abort_unless($abs, 404, 'That picture is not available.');

        return response()->file($abs, ['Cache-Control' => 'private, max-age=600', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** POST /drills/fire-plans/template { tree } | { reset: true } - agency admins edit the steps every new plan starts from. */
    public function template(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        abort_unless($this->isAgencyAdmin($request), 403, 'Agency admins only.');
        $d = $request->validate(['tree' => ['nullable', 'array'], 'reset' => ['nullable', 'boolean']]);
        if (! empty($d['reset'])) {
            FirePlans::saveTemplate($agencyId, null);
        } else {
            $clean = FirePlans::cleanTree($d['tree'] ?? [], true);
            if (count($clean['steps']) < 3) return response()->json(['message' => 'A fire plan needs at least 3 steps.'], 422);
            FirePlans::saveTemplate($agencyId, $clean);
        }
        $this->audit($request, $agencyId, 'fire_plan.template_saved', $agencyId, ! empty($d['reset']) ? 'Fire plan steps reset to the KiddieTrac default' : 'Fire plan steps changed');

        return response()->json(['ok' => true, 'template' => FirePlans::template($agencyId)]);
    }
}
