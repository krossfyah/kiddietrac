<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\HrAccess;
use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use App\Support\CentreScope;
use App\Support\HrLeave;
use App\Support\HrOpenShifts;
use App\Support\HrSafety;
use App\Support\Live;
use App\Support\Notify;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * KiddieTrac HR Phase 4, batch 7 (2026-11-27): Human Resources > Health & safety - risk assessments, the WHMIS chemical
 * inventory, named H&S duties and H&S tasks (App\Support\HrSafety). Safe work procedures are Policy library entries
 * (category "Safe work procedure", PolicyController) and hazards stay in HazardController.
 *
 * Scope: the ACTIVE agency (HrAccess::hrAgency, HR add-on on), open centres only; admins every centre, directors their
 * own (CentreScope). Staff see their own duties and H&S tasks and the chemical list + safety data sheets of their own
 * centres (GET hr/me/safety). A risk assessment is signed by a director of that centre or an admin, never by the person
 * who last edited it. An H&S task is closed only with evidence.
 */
final class HrSafetyController extends Controller
{
    use HrAccess;

    /** Open centres the caller manages [id => name] (admins: all open centres). */
    private function myCentres(Request $request, int $aid): array
    {
        $scope = CentreScope::ids($request, $aid);
        $q = DB::table('centres')->whereIn('id', Live::centreIds($aid) ?: [0]);
        if ($scope !== null) $q->whereIn('id', $scope ?: [0]);

        return $q->orderBy('name')->pluck('name', 'id')->all();
    }

    /** Open centres a staff member works at [id => name] (agency-wide staff: every open centre). */
    private function staffCentres(Request $request, int $aid): array
    {
        $me = (int) $request->user()->id;
        if (! in_array($me, HrLeave::staffIds($aid), true)) return [];
        $open = Live::centreIds($aid);
        $wide = DB::table('role_assignments')->where('user_id', $me)->where('active', 1)->where('agency_id', $aid)->whereNull('centre_id')
            ->whereIn('role', HrLeave::STAFF_ROLES)->exists();
        $ids = $wide ? $open : array_values(array_intersect(CentreScope::centresOfUser($me, $aid), $open));

        return DB::table('centres')->whereIn('id', $ids ?: [0])->orderBy('name')->pluck('name', 'id')->all();
    }

    private function needCentre(array $centres, $cid): int
    {
        $cid = (int) $cid;
        abort_unless($cid && isset($centres[$cid]), 403, 'That centre is not one of yours.');

        return $cid;
    }

    private function today(int $aid): string
    {
        return AgencyTime::today($aid);
    }

    private function centreList(array $centres): array
    {
        return collect($centres)->map(fn ($n, $i) => ['id' => (int) $i, 'name' => $n])->values()->all();
    }

    // ═══════════════════════════════ RISK ASSESSMENTS ═══════════════════════════════

    private function risk(Request $request, int $aid, int $id): object
    {
        $r = DB::table('hr_risk_assessments')->where('id', $id)->where('agency_id', $aid)->first();
        abort_unless($r && isset($this->myCentres($request, $aid)[(int) $r->centre_id]), 404, 'That risk assessment was not found.');

        return $r;
    }

    private function riskShape(object $r, array $centres, array $names, string $today): array
    {
        $items = json_decode((string) $r->items, true) ?: [];
        $items = array_map(function ($i) {
            $i['score'] = HrSafety::score($i['likelihood'] ?? null, $i['severity'] ?? null);
            $i['residual_score'] = HrSafety::score($i['residual_likelihood'] ?? null, $i['residual_severity'] ?? null);
            $i['band'] = HrSafety::band($i['score']);
            $i['residual_band'] = HrSafety::band($i['residual_score']);

            return $i;
        }, $items);
        $top = HrSafety::topScore($items);

        return ['id' => (int) $r->id, 'centre_id' => (int) $r->centre_id, 'centre_name' => $centres[(int) $r->centre_id] ?? '', 'title' => $r->title, 'area' => $r->area,
            'template_key' => $r->template_key, 'is_example' => (bool) $r->is_example, 'items' => $items, 'item_count' => count($items), 'notes' => $r->notes,
            'top_score' => $top, 'top_band' => HrSafety::band($top),
            'owner_user_id' => $r->owner_user_id ? (int) $r->owner_user_id : null, 'owner_name' => $names[(int) $r->owner_user_id] ?? null,
            'review_on' => $r->review_on, 'review_state' => ! $r->review_on ? 'none' : ($r->review_on < $today ? 'overdue' : ($r->review_on <= Carbon::parse($today)->addDays(HrSafety::SOON_DAYS)->toDateString() ? 'soon' : 'ok')),
            'status' => $r->archived_at ? 'archived' : $r->status, 'prepared_by' => $r->prepared_by ? (int) $r->prepared_by : null, 'prepared_by_name' => $names[(int) $r->prepared_by] ?? null,
            'signed_by_name' => $names[(int) $r->signed_by] ?? null, 'signed_name' => $r->signed_name,
            'signed_at' => $r->signed_at ? Carbon::parse($r->signed_at, 'UTC')->setTimezone(AgencyTime::tz((int) $r->agency_id))->format('Y-m-d H:i') : null,
            'updated_at' => $r->updated_at];
    }

    /** GET /hr/safety/risk?centre_id=&archived=1 */
    public function riskIndex(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedManager($request);
        $centres = $this->myCentres($request, $aid);
        $cid = (int) $request->input('centre_id');
        $ids = $cid && isset($centres[$cid]) ? [$cid] : array_keys($centres);
        $q = DB::table('hr_risk_assessments')->where('agency_id', $aid)->whereIn('centre_id', $ids ?: [0]);
        $request->boolean('archived') ? $q->whereNotNull('archived_at') : $q->whereNull('archived_at');
        $rows = $q->orderBy('centre_id')->orderBy('title')->limit(500)->get();
        $names = $this->hrNames($rows->flatMap(fn ($r) => [(int) $r->owner_user_id, (int) $r->prepared_by, (int) $r->signed_by])->filter()->unique()->all());
        $today = $this->today($aid);
        $owners = DB::table('users')->whereIn('id', $this->hrPeople($request, $aid) ?: [0])->orderBy('first_name')->get(['id', 'first_name', 'last_name'])
            ->map(fn ($u) => ['id' => (int) $u->id, 'name' => $this->hrName($u)])->values();

        return response()->json(['today' => $today, 'me' => (int) $request->user()->id, 'centres' => $this->centreList($centres), 'owners' => $owners,
            'templates' => collect(HrSafety::TEMPLATES)->map(fn ($t, $k) => ['key' => $k, 'title' => $t['title'], 'area' => $t['area'], 'lines' => count($t['items'])])->values(),
            'rows' => $rows->map(fn ($r) => $this->riskShape($r, $centres, $names, $today))->values()]);
    }

    /** GET /hr/safety/risk/{id} */
    public function riskShow(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedManager($request);
        $r = $this->risk($request, $aid, $id);
        $names = $this->hrNames(array_filter([(int) $r->owner_user_id, (int) $r->prepared_by, (int) $r->signed_by]));

        return response()->json(['row' => $this->riskShape($r, $this->myCentres($request, $aid), $names, $this->today($aid)), 'me' => (int) $request->user()->id]);
    }

    private function riskFields(Request $request, int $aid, bool $create): array
    {
        $d = $request->validate([
            'centre_id' => [$create ? 'required' : 'prohibited', 'integer'],
            'template_key' => ['nullable', 'string', 'in:' . implode(',', array_keys(HrSafety::TEMPLATES))],
            'title' => [$create ? 'nullable' : 'sometimes', 'string', 'max:200'],
            'area' => ['nullable', 'string', 'max:160'],
            'items' => ['sometimes', 'array', 'max:60'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'owner_user_id' => ['nullable', 'integer'],
            'review_on' => ['nullable', 'date_format:Y-m-d'],
        ]);
        if (! empty($d['owner_user_id'])) abort_unless(in_array((int) $d['owner_user_id'], HrLeave::staffIds($aid), true), 422, 'The owner must be one of your staff.');

        return $d;
    }

    /** POST /hr/safety/risk — blank, or a copy of a starter template (marked as an example). */
    public function riskCreate(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedManager($request);
        $d = $this->riskFields($request, $aid, true);
        $cid = $this->needCentre($this->myCentres($request, $aid), $d['centre_id']);
        $tk = $d['template_key'] ?? null;
        $tpl = $tk ? HrSafety::TEMPLATES[$tk] : null;
        $items = $tpl ? HrSafety::templateItems($tk) : HrSafety::items($d['items'] ?? []);
        $title = trim((string) ($d['title'] ?? '')) ?: ($tpl['title'] ?? '');
        abort_if($title === '', 422, 'Give the risk assessment a title.');
        $me = (int) $request->user()->id;
        $id = DB::table('hr_risk_assessments')->insertGetId(['agency_id' => $aid, 'centre_id' => $cid, 'title' => mb_substr($title, 0, 200),
            'area' => $d['area'] ?? ($tpl['area'] ?? null), 'template_key' => $tk, 'is_example' => (bool) $tpl, 'items' => json_encode($items),
            'notes' => $d['notes'] ?? null, 'owner_user_id' => $d['owner_user_id'] ?? null, 'review_on' => $d['review_on'] ?? Carbon::parse($this->today($aid))->addYear()->toDateString(),
            'status' => 'draft', 'prepared_by' => $me, 'created_by' => $me, 'created_at' => now(), 'updated_at' => now()]);
        $this->hrNote($aid, 'hr.risk_assessment_created', 'hr_risk_assessment', $id, ['title' => $title, 'template' => $tk]);

        return response()->json(['ok' => true, 'id' => $id]);
    }

    /** PUT /hr/safety/risk/{id} — any change returns it to draft; the editor cannot sign it. */
    public function riskUpdate(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedManager($request);
        $r = $this->risk($request, $aid, $id);
        abort_if($r->archived_at, 422, 'Restore this risk assessment before changing it.');
        $d = $this->riskFields($request, $aid, false);
        $set = ['updated_at' => now(), 'prepared_by' => (int) $request->user()->id, 'is_example' => false,
            'status' => 'draft', 'signed_by' => null, 'signed_at' => null, 'signed_name' => null];
        if (array_key_exists('title', $d)) { $t = trim((string) $d['title']); abort_if($t === '', 422, 'Give the risk assessment a title.'); $set['title'] = mb_substr($t, 0, 200); }
        foreach (['area', 'notes', 'owner_user_id', 'review_on'] as $k) if (array_key_exists($k, $d)) $set[$k] = $d[$k];
        if (array_key_exists('items', $d)) $set['items'] = json_encode(HrSafety::items($d['items']));
        if (array_key_exists('review_on', $d)) $set['reminded_for'] = null;
        DB::table('hr_risk_assessments')->where('id', $id)->update($set);
        $this->hrNote($aid, 'hr.risk_assessment_updated', 'hr_risk_assessment', $id, ['title' => $set['title'] ?? $r->title, 'was_signed' => $r->status === 'signed']);

        return response()->json(['ok' => true, 'unsigned' => $r->status === 'signed']);
    }

    /** POST /hr/safety/risk/{id}/sign {typed_name} — a director of the centre or an admin; never the last editor. */
    public function riskSign(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedManager($request);
        $r = $this->risk($request, $aid, $id);
        $me = (int) $request->user()->id;
        abort_if($r->archived_at, 422, 'This risk assessment is archived.');
        abort_if($r->status === 'signed', 422, 'This risk assessment is already signed.');
        abort_if((int) $r->prepared_by === $me, 403, 'You made the last changes to this risk assessment, so another director or an admin signs it off.');
        abort_unless(in_array($me, HrLeave::staffIds($aid), true), 403, 'Sign-off is for the agency\'s own directors and admins.');
        abort_unless(json_decode((string) $r->items, true), 422, 'Add at least one hazard before signing it off.');
        $typed = preg_replace('/\s+/u', ' ', trim((string) $request->validate(['typed_name' => ['required', 'string', 'min:3', 'max:190']])['typed_name']));
        DB::table('hr_risk_assessments')->where('id', $id)->update(['status' => 'signed', 'signed_by' => $me, 'signed_at' => now(), 'signed_name' => $typed, 'updated_at' => now()]);
        $this->hrNote($aid, 'hr.risk_assessment_signed', 'hr_risk_assessment', $id, ['title' => $r->title, 'typed_name' => $typed]);

        return response()->json(['ok' => true]);
    }

    /** POST /hr/safety/risk/{id}/archive {archived} — kept on file. */
    public function riskArchive(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedManager($request);
        $r = $this->risk($request, $aid, $id);
        $on = (bool) $request->validate(['archived' => ['required', 'boolean']])['archived'];
        DB::table('hr_risk_assessments')->where('id', $id)->update(['archived_at' => $on ? now() : null, 'updated_at' => now()]);
        $this->hrNote($aid, $on ? 'hr.risk_assessment_archived' : 'hr.risk_assessment_restored', 'hr_risk_assessment', $id, ['title' => $r->title]);

        return response()->json(['ok' => true]);
    }

    // ═══════════════════════════════ CHEMICALS (WHMIS) ═══════════════════════════════

    private function chemShape(object $c, array $centres, string $today, bool $manager): array
    {
        return ['id' => (int) $c->id, 'centre_id' => (int) $c->centre_id, 'centre_name' => $centres[(int) $c->centre_id] ?? '', 'product' => $c->product,
            'supplier' => $c->supplier, 'location' => $c->location, 'hazard_classes' => HrSafety::classes($c->hazard_classes), 'quantity' => $c->quantity,
            'notes' => $c->notes, 'has_sds' => (bool) $c->sds_path, 'sds_name' => $c->sds_name, 'sds_date' => $c->sds_date,
            'sds_review_on' => $manager ? $c->sds_review_on : null, 'flags' => HrSafety::chemFlags($c, $today), 'archived' => (bool) $c->archived_at];
    }

    /** GET /hr/safety/chemicals?centre_id=&archived=1 */
    public function chemIndex(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedManager($request);
        $centres = $this->myCentres($request, $aid);
        $cid = (int) $request->input('centre_id');
        $q = DB::table('hr_chemicals')->where('agency_id', $aid)->whereIn('centre_id', $cid && isset($centres[$cid]) ? [$cid] : (array_keys($centres) ?: [0]));
        $request->boolean('archived') ? $q->whereNotNull('archived_at') : $q->whereNull('archived_at');
        $today = $this->today($aid);

        return response()->json(['today' => $today, 'centres' => $this->centreList($centres), 'classes' => HrSafety::WHMIS, 'sds_max_years' => HrSafety::SDS_MAX_YEARS,
            'rows' => $q->orderBy('centre_id')->orderBy('product')->limit(1000)->get()->map(fn ($c) => $this->chemShape($c, $centres, $today, true))->values()]);
    }

    private function chem(Request $request, int $aid, int $id): object
    {
        $c = DB::table('hr_chemicals')->where('id', $id)->where('agency_id', $aid)->first();
        abort_unless($c && isset($this->myCentres($request, $aid)[(int) $c->centre_id]), 404, 'That product was not found.');

        return $c;
    }

    private function chemFields(Request $request, bool $create): array
    {
        $d = $request->validate([
            'centre_id' => [$create ? 'required' : 'prohibited', 'integer'],
            'product' => [$create ? 'required' : 'sometimes', 'string', 'max:200'],
            'supplier' => ['nullable', 'string', 'max:200'],
            'location' => ['nullable', 'string', 'max:200'],
            'hazard_classes' => ['nullable'],
            'quantity' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'sds_date' => ['nullable', 'date_format:Y-m-d'],
            'sds_review_on' => ['nullable', 'date_format:Y-m-d'],
            'sds' => ['nullable', ...HrSafety::FILE_RULE],
        ]);
        if (array_key_exists('hazard_classes', $d)) {
            $raw = $d['hazard_classes'];
            $d['hazard_classes'] = json_encode(HrSafety::classes(is_string($raw) ? (json_decode($raw, true) ?: array_filter(explode(',', $raw))) : (array) $raw));
        }
        if (isset($d['product'])) { $d['product'] = trim($d['product']); abort_if($d['product'] === '', 422, 'Name the product.'); }

        return $d;
    }

    /** POST /hr/safety/chemicals (multipart, optional sds file) */
    public function chemCreate(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedManager($request);
        $d = $this->chemFields($request, true);
        if (! empty($d['sds_date'])) abort_if($d['sds_date'] > $this->today($aid), 422, 'The date on the safety data sheet cannot be in the future.');
        $cid = $this->needCentre($this->myCentres($request, $aid), $d['centre_id']);
        $me = (int) $request->user()->id;
        $row = ['agency_id' => $aid, 'centre_id' => $cid, 'product' => $d['product'], 'supplier' => $d['supplier'] ?? null, 'location' => $d['location'] ?? null,
            'hazard_classes' => $d['hazard_classes'] ?? '[]', 'quantity' => $d['quantity'] ?? null, 'notes' => $d['notes'] ?? null,
            'sds_date' => $d['sds_date'] ?? null, 'sds_review_on' => $d['sds_review_on'] ?? null, 'created_by' => $me, 'updated_by' => $me, 'created_at' => now(), 'updated_at' => now()];
        if ($request->hasFile('sds')) {
            $f = HrSafety::put($aid, $request->file('sds'), 'sds');
            $row += ['sds_path' => $f['path'], 'sds_name' => $f['name'], 'sds_mime' => $f['mime'], 'sds_size' => $f['size']];
        }
        $id = DB::table('hr_chemicals')->insertGetId($row);
        $this->hrNote($aid, 'hr.chemical_added', 'hr_chemical', $id, ['product' => $d['product'], 'sds' => $request->hasFile('sds')]);

        return response()->json(['ok' => true, 'id' => $id]);
    }

    /** POST /hr/safety/chemicals/{id} (multipart) — details, and a new SDS replaces the old one. */
    public function chemUpdate(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedManager($request);
        $c = $this->chem($request, $aid, $id);
        $d = $this->chemFields($request, false);
        if (! empty($d['sds_date'])) abort_if($d['sds_date'] > $this->today($aid), 422, 'The date on the safety data sheet cannot be in the future.');
        $set = ['updated_by' => (int) $request->user()->id, 'updated_at' => now()];
        foreach (['product', 'supplier', 'location', 'hazard_classes', 'quantity', 'notes', 'sds_date', 'sds_review_on'] as $k) if (array_key_exists($k, $d)) $set[$k] = $d[$k];
        if (array_key_exists('sds_review_on', $d) || array_key_exists('sds_date', $d)) $set['reminded_for'] = null;
        if ($request->hasFile('sds')) {
            $f = HrSafety::put($aid, $request->file('sds'), 'sds');
            $set += ['sds_path' => $f['path'], 'sds_name' => $f['name'], 'sds_mime' => $f['mime'], 'sds_size' => $f['size'], 'reminded_for' => null];
        }
        DB::table('hr_chemicals')->where('id', $id)->update($set);
        $this->hrNote($aid, 'hr.chemical_updated', 'hr_chemical', $id, ['product' => $set['product'] ?? $c->product, 'new_sds' => $request->hasFile('sds')]);

        return response()->json(['ok' => true]);
    }

    /** POST /hr/safety/chemicals/{id}/archive {archived} — no longer on site; kept on file. */
    public function chemArchive(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedManager($request);
        $c = $this->chem($request, $aid, $id);
        $on = (bool) $request->validate(['archived' => ['required', 'boolean']])['archived'];
        DB::table('hr_chemicals')->where('id', $id)->update(['archived_at' => $on ? now() : null, 'updated_at' => now()]);
        $this->hrNote($aid, $on ? 'hr.chemical_archived' : 'hr.chemical_restored', 'hr_chemical', $id, ['product' => $c->product]);

        return response()->json(['ok' => true]);
    }

    /** GET /hr/safety/chemicals/{id}/sds — managers of the centre, and the staff who work there (they must be able to read it). */
    public function chemSds(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $c = DB::table('hr_chemicals')->where('id', $id)->where('agency_id', $aid)->first();
        $cid = $c ? (int) $c->centre_id : 0;
        $ok = $c && ((isset($this->myCentres($request, $aid)[$cid]) && $this->hrIsManager($request)) || (! $c->archived_at && isset($this->staffCentres($request, $aid)[$cid])));
        abort_unless($ok, 404, 'That safety data sheet was not found.');

        return response()->json(HrSafety::fileOut($c->sds_path, $c->sds_name, $c->sds_mime) + ['title' => $c->product]);
    }

    // ═══════════════════════════════ H&S DUTIES ═══════════════════════════════

    private function dutyShape(object $d, array $names, array $centres, string $today): array
    {
        $cert = HrSafety::certFor((int) $d->user_id, $d->cert_type, $today);
        $active = ! $d->ended_at && (! $d->ends_on || $d->ends_on >= $today) && (! $d->starts_on || $d->starts_on <= $today);

        return ['id' => (int) $d->id, 'user_id' => (int) $d->user_id, 'name' => $names[(int) $d->user_id] ?? '', 'duty' => $d->duty, 'label' => $d->label,
            'duty_label' => HrSafety::dutyLabel($d), 'centre_id' => $d->centre_id ? (int) $d->centre_id : null,
            'centre_name' => $d->centre_id ? ($centres[(int) $d->centre_id] ?? '') : 'Every centre', 'cert_type' => $d->cert_type, 'cert' => $cert,
            'starts_on' => $d->starts_on, 'ends_on' => $d->ends_on, 'notes' => $d->notes, 'active' => $active, 'ended' => (bool) $d->ended_at];
    }

    /** GET /hr/safety/duties?ended=1 */
    public function dutyIndex(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedManager($request);
        $centres = $this->myCentres($request, $aid);
        $people = $this->hrPeople($request, $aid);
        $admin = CentreScope::ids($request, $aid) === null;
        $q = DB::table('hr_safety_duties')->where('agency_id', $aid)->whereIn('user_id', $people ?: [0])
            ->where(fn ($w) => $w->whereIn('centre_id', array_keys($centres) ?: [0])->when($admin, fn ($x) => $x->orWhereNull('centre_id')));
        $request->boolean('ended') ? $q->whereNotNull('ended_at') : $q->whereNull('ended_at');
        $rows = $q->orderBy('duty')->get();
        $names = $this->hrNames($people);
        $today = $this->today($aid);
        $certTypes = HrOpenShifts::certChoices($aid) + ['Food_Handler' => 'Food Handler', 'Health_Safety_15' => 'Health & Safety'];
        asort($certTypes);

        return response()->json(['today' => $today, 'centres' => $this->centreList($centres), 'is_admin' => $admin,
            'duty_types' => collect(HrSafety::DUTIES)->map(fn ($v, $k) => ['key' => $k, 'label' => $v[0], 'cert_type' => $v[1]])->values(),
            'cert_types' => $certTypes,
            'people' => collect($names)->map(fn ($n, $i) => ['id' => (int) $i, 'name' => $n])->sortBy('name')->values(),
            'rows' => $rows->map(fn ($d) => $this->dutyShape($d, $names, $centres, $today))->values()]);
    }

    private function dutyFields(Request $request, int $aid, bool $create): array
    {
        $d = $request->validate([
            'user_id' => [$create ? 'required' : 'sometimes', 'integer'],
            'duty' => [$create ? 'required' : 'sometimes', 'string', 'in:' . implode(',', array_keys(HrSafety::DUTIES))],
            'label' => ['nullable', 'string', 'max:120'],
            'centre_id' => ['nullable', 'integer'],
            'cert_type' => ['nullable', 'string', 'max:40'],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        if (! empty($d['starts_on']) && ! empty($d['ends_on'])) abort_if($d['ends_on'] < $d['starts_on'], 422, 'The end date is before the start date.');
        if (isset($d['user_id'])) $this->hrNeedPerson($request, $aid, (int) $d['user_id']);
        if (array_key_exists('centre_id', $d)) {
            if ($d['centre_id']) $this->needCentre($this->myCentres($request, $aid), $d['centre_id']);
            else abort_unless(CentreScope::ids($request, $aid) === null, 403, 'Directors name duties at their own centres.');
        }
        if (array_key_exists('cert_type', $d) && $d['cert_type']) {
            $types = DB::select("SHOW COLUMNS FROM staff_certifications LIKE 'cert_type'");
            preg_match_all("/'([^']+)'/", (string) ($types[0]->Type ?? ''), $m);
            abort_unless(in_array($d['cert_type'], $m[1] ?? [], true), 422, 'Pick a certificate type from the list.');
        }
        if (($d['duty'] ?? null) === 'other') abort_if(trim((string) ($d['label'] ?? '')) === '', 422, 'Name the duty.');

        return $d;
    }

    /** POST /hr/safety/duties */
    public function dutyCreate(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedManager($request);
        $d = $this->dutyFields($request, $aid, true);
        if (! array_key_exists('centre_id', $d)) abort_unless(CentreScope::ids($request, $aid) === null, 403, 'Choose one of your centres.');
        $cert = array_key_exists('cert_type', $d) ? ($d['cert_type'] ?: null) : HrSafety::DUTIES[$d['duty']][1];
        $id = DB::table('hr_safety_duties')->insertGetId(['agency_id' => $aid, 'centre_id' => $d['centre_id'] ?? null, 'user_id' => (int) $d['user_id'], 'duty' => $d['duty'],
            'label' => $d['label'] ?? null, 'cert_type' => $cert, 'starts_on' => $d['starts_on'] ?? $this->today($aid), 'ends_on' => $d['ends_on'] ?? null,
            'notes' => $d['notes'] ?? null, 'created_by' => (int) $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->hrNote($aid, 'hr.safety_duty_named', 'user', (int) $d['user_id'], ['duty' => $d['duty'], 'duty_id' => $id]);
        if ((int) $d['user_id'] !== (int) $request->user()->id) {
            Notify::write(['agency_id' => $aid, 'user_id' => (int) $d['user_id'], 'type' => 'hr', 'title' => '🧯 You have been named ' . HrSafety::dutyLabel((object) ['duty' => $d['duty'], 'label' => $d['label'] ?? null]),
                'body' => 'See it in My HR > My H&S duties.', 'data' => json_encode(['hash' => 'my-hr', 'kind' => 'own', 'hr_safety' => 'duty']), 'created_at' => now()]);
        }

        return response()->json(['ok' => true, 'id' => $id]);
    }

    private function duty(Request $request, int $aid, int $id): object
    {
        $d = DB::table('hr_safety_duties')->where('id', $id)->where('agency_id', $aid)->first();
        $admin = CentreScope::ids($request, $aid) === null;
        abort_unless($d && in_array((int) $d->user_id, $this->hrPeople($request, $aid), true)
            && ($d->centre_id ? isset($this->myCentres($request, $aid)[(int) $d->centre_id]) : $admin), 404, 'That duty was not found.');

        return $d;
    }

    /** PUT /hr/safety/duties/{id} */
    public function dutyUpdate(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedManager($request);
        $row = $this->duty($request, $aid, $id);
        $d = $this->dutyFields($request, $aid, false);
        $set = ['updated_at' => now(), 'alerted_for' => null];
        foreach (['user_id', 'duty', 'label', 'centre_id', 'cert_type', 'starts_on', 'ends_on', 'notes'] as $k) if (array_key_exists($k, $d)) $set[$k] = $d[$k] === '' ? null : $d[$k];
        DB::table('hr_safety_duties')->where('id', $id)->update($set);
        $this->hrNote($aid, 'hr.safety_duty_updated', 'user', (int) ($set['user_id'] ?? $row->user_id), ['duty_id' => $id]);

        return response()->json(['ok' => true]);
    }

    /** POST /hr/safety/duties/{id}/end — stops today; kept on file. */
    public function dutyEnd(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedManager($request);
        $row = $this->duty($request, $aid, $id);
        abort_if($row->ended_at, 422, 'This duty has already ended.');
        DB::table('hr_safety_duties')->where('id', $id)->update(['ended_at' => now(), 'ends_on' => $row->ends_on && $row->ends_on < $this->today($aid) ? $row->ends_on : $this->today($aid), 'updated_at' => now()]);
        $this->hrNote($aid, 'hr.safety_duty_ended', 'user', (int) $row->user_id, ['duty_id' => $id, 'duty' => $row->duty]);

        return response()->json(['ok' => true]);
    }

    // ═══════════════════════════════ H&S TASKS ═══════════════════════════════

    private const SOURCES = ['risk' => 'hr_risk_assessments', 'chemical' => 'hr_chemicals', 'hazard' => 'centre_hazards'];

    private function taskShape(object $t, array $names, array $centres, int $me): array
    {
        $src = $t->source && str_starts_with((string) $t->source, 'hs_') ? substr((string) $t->source, 3) : null;

        return ['id' => (int) $t->id, 'title' => $t->title, 'description' => $t->description, 'priority' => $t->priority, 'due_date' => $t->due_date,
            'status' => $t->status, 'completed_at' => $t->completed_at, 'centre_id' => $t->centre_id ? (int) $t->centre_id : null,
            'centre_name' => $t->centre_id ? ($centres[(int) $t->centre_id] ?? '') : '', 'assigned_to' => (int) $t->assigned_to, 'assigned_to_name' => $names[(int) $t->assigned_to] ?? '',
            'assigned_by_name' => $names[(int) $t->assigned_by] ?? '', 'mine' => (int) $t->assigned_to === $me,
            'evidence_required' => (bool) $t->evidence_required, 'has_evidence' => (bool) $t->evidence_path, 'evidence_name' => $t->evidence_name,
            'evidence_note' => $t->evidence_note, 'evidence_by_name' => $names[(int) $t->evidence_by] ?? null, 'evidence_at' => $t->evidence_at,
            'source' => $src, 'source_id' => $t->source_id ? (int) $t->source_id : null];
    }

    private function hsTasks(int $aid)
    {
        return DB::table('tasks')->where('agency_id', $aid)->where('category', 'hs')->whereNull('deleted_at')->where('is_private', 0);
    }

    /** GET /hr/safety/tasks?status=open|done|all */
    public function taskIndex(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedManager($request);
        $centres = $this->myCentres($request, $aid);
        $admin = CentreScope::ids($request, $aid) === null;
        $people = $this->hrPeople($request, $aid);
        $st = in_array($request->input('status'), ['open', 'done', 'all'], true) ? $request->input('status') : 'open';
        $q = $this->hsTasks($aid)->where(fn ($w) => $w->whereIn('centre_id', array_keys($centres) ?: [0])->when($admin, fn ($x) => $x->orWhereNull('centre_id')));
        if ($st === 'open') $q->whereIn('status', ['open', 'in_progress']);
        if ($st === 'done') $q->where('status', 'done');
        $rows = $q->orderByRaw("FIELD(status,'open','in_progress','done')")->orderByRaw('due_date IS NULL, due_date')->limit(500)->get();
        $names = $this->hrNames(array_merge($people, $rows->flatMap(fn ($t) => [(int) $t->assigned_by, (int) $t->evidence_by, (int) $t->assigned_to])->all()));
        $me = (int) $request->user()->id;
        $risks = DB::table('hr_risk_assessments')->where('agency_id', $aid)->whereIn('centre_id', array_keys($centres) ?: [0])->whereNull('archived_at')->orderBy('title')->get(['id', 'title', 'centre_id']);
        $chems = DB::table('hr_chemicals')->where('agency_id', $aid)->whereIn('centre_id', array_keys($centres) ?: [0])->whereNull('archived_at')->orderBy('product')->get(['id', 'product', 'centre_id']);

        return response()->json(['status' => $st, 'today' => $this->today($aid), 'centres' => $this->centreList($centres), 'is_admin' => $admin,
            'people' => collect($people)->map(fn ($i) => ['id' => (int) $i, 'name' => $names[$i] ?? ''])->sortBy('name')->values(),
            'links' => ['risk' => $risks->map(fn ($r) => ['id' => (int) $r->id, 'title' => $r->title, 'centre_id' => (int) $r->centre_id])->values(),
                'chemical' => $chems->map(fn ($c) => ['id' => (int) $c->id, 'title' => $c->product, 'centre_id' => (int) $c->centre_id])->values()],
            'counts' => ['open' => $this->hsTasks($aid)->where(fn ($w) => $w->whereIn('centre_id', array_keys($centres) ?: [0])->when($admin, fn ($x) => $x->orWhereNull('centre_id')))->whereIn('status', ['open', 'in_progress'])->count()],
            'rows' => $rows->map(fn ($t) => $this->taskShape($t, $names, $centres, $me))->values()]);
    }

    /** POST /hr/safety/tasks {assigned_to, centre_id, title, description?, due_date?, priority?, evidence_required?, source?, source_id?} */
    public function taskCreate(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedManager($request);
        $d = $request->validate([
            'assigned_to' => ['required', 'integer'], 'centre_id' => ['required', 'integer'], 'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:4000'], 'due_date' => ['nullable', 'date_format:Y-m-d'], 'priority' => ['nullable', 'in:low,normal,high'],
            'evidence_required' => ['nullable', 'boolean'], 'source' => ['nullable', 'in:risk,chemical,hazard'], 'source_id' => ['nullable', 'integer'],
        ]);
        $centres = $this->myCentres($request, $aid);
        $cid = $this->needCentre($centres, $d['centre_id']);
        $this->hrNeedPerson($request, $aid, (int) $d['assigned_to']);
        $src = null; $sid = null;
        if (! empty($d['source']) && ! empty($d['source_id'])) {
            $ok = DB::table(self::SOURCES[$d['source']])->where('id', (int) $d['source_id'])->where('agency_id', $aid)->where('centre_id', $cid)->exists();
            abort_unless($ok, 422, 'That linked record is not at this centre.');
            $src = 'hs_' . $d['source']; $sid = (int) $d['source_id'];
        }
        $me = (int) $request->user()->id;
        $id = DB::table('tasks')->insertGetId(['agency_id' => $aid, 'centre_id' => $cid, 'assigned_to' => (int) $d['assigned_to'], 'assigned_by' => $me, 'is_private' => 0,
            'title' => trim($d['title']), 'description' => $d['description'] ?? null, 'priority' => $d['priority'] ?? 'normal', 'due_date' => $d['due_date'] ?? null,
            'status' => 'open', 'category' => 'hs', 'evidence_required' => array_key_exists('evidence_required', $d) ? (bool) $d['evidence_required'] : true,
            'source' => $src, 'source_id' => $sid, 'created_at' => now(), 'updated_at' => now()]);
        $this->hrNote($aid, 'hr.hs_task_created', 'task', $id, ['title' => trim($d['title']), 'assigned_to' => (int) $d['assigned_to']]);
        if ((int) $d['assigned_to'] !== $me) {
            Notify::write(['agency_id' => $aid, 'user_id' => (int) $d['assigned_to'], 'type' => 'hr', 'title' => '🧰 Health & safety task: ' . mb_substr(trim($d['title']), 0, 150),
                'body' => (($d['due_date'] ?? null) ? 'Due ' . Carbon::parse($d['due_date'])->format('M j') . '. ' : '') . 'Add your evidence (a photo or file) in My HR > H&S tasks to close it.',
                'data' => json_encode(['hash' => 'my-hr', 'kind' => 'own', 'hr_safety' => 'task', 'task_id' => $id]), 'created_at' => now()]);
        }

        return response()->json(['ok' => true, 'id' => $id]);
    }

    /** The task, for its assignee or a manager of its centre. */
    private function task(Request $request, int $aid, int $id, bool $managerOnly = false): object
    {
        $t = $this->hsTasks($aid)->where('id', $id)->first();
        abort_unless($t, 404, 'That task was not found.');
        $me = (int) $request->user()->id;
        $mgr = $this->hrIsManager($request) && ($t->centre_id ? isset($this->myCentres($request, $aid)[(int) $t->centre_id]) : CentreScope::ids($request, $aid) === null);
        abort_unless($mgr || (! $managerOnly && (int) $t->assigned_to === $me), 404, 'That task was not found.');

        return $t;
    }

    /** POST /hr/safety/tasks/{id}/evidence (multipart file, note?) — the assignee or a manager. */
    public function taskEvidence(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $t = $this->task($request, $aid, $id);
        abort_if($t->status === 'done', 422, 'This task is closed. Reopen it to change the evidence.');
        $d = $request->validate(['file' => ['required', ...HrSafety::FILE_RULE], 'note' => ['nullable', 'string', 'max:1000']]);
        $f = HrSafety::put($aid, $request->file('file'), 'evidence');
        DB::table('tasks')->where('id', $id)->update(['evidence_path' => $f['path'], 'evidence_name' => $f['name'], 'evidence_mime' => $f['mime'],
            'evidence_note' => $d['note'] ?? $t->evidence_note, 'evidence_by' => (int) $request->user()->id, 'evidence_at' => now(), 'updated_at' => now()]);
        $this->hrNote($aid, 'hr.hs_task_evidence', 'task', $id, ['file' => $f['name']]);

        return response()->json(['ok' => true]);
    }

    /** POST /hr/safety/tasks/{id}/close {note?} — only with evidence on file (when the task asks for it). */
    public function taskClose(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $t = $this->task($request, $aid, $id);
        abort_if($t->status === 'done', 422, 'This task is already closed.');
        abort_if($t->evidence_required && ! $t->evidence_path, 422, 'Add the evidence (a photo or file) before closing this task.');
        $note = $request->validate(['note' => ['nullable', 'string', 'max:1000']])['note'] ?? null;
        $set = ['status' => 'done', 'completed_at' => now(), 'updated_at' => now()];
        if ($note) $set['evidence_note'] = $note;
        DB::table('tasks')->where('id', $id)->update($set);
        $this->hrNote($aid, 'hr.hs_task_closed', 'task', $id, ['title' => $t->title]);
        $me = (int) $request->user()->id;
        if ((int) $t->assigned_by && (int) $t->assigned_by !== $me && in_array((int) $t->assigned_by, HrLeave::staffIds($aid), true)) {
            $who = $this->hrNames([$me])[$me] ?? 'Someone';
            Notify::write(['agency_id' => $aid, 'user_id' => (int) $t->assigned_by, 'type' => 'hr', 'title' => '✅ H&S task done: ' . mb_substr($t->title, 0, 150),
                'body' => $who . ' closed it with evidence.', 'data' => json_encode(['hash' => 'hr-safety?tab=hstasks', 'kind' => 'team', 'hr_safety' => 'task', 'task_id' => $id]), 'created_at' => now()]);
        }

        return response()->json(['ok' => true]);
    }

    /** POST /hr/safety/tasks/{id}/reopen — managers. */
    public function taskReopen(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $t = $this->task($request, $aid, $id, true);
        abort_unless($t->status === 'done', 422, 'This task is still open.');
        DB::table('tasks')->where('id', $id)->update(['status' => 'open', 'completed_at' => null, 'updated_at' => now()]);
        $this->hrNote($aid, 'hr.hs_task_reopened', 'task', $id, ['title' => $t->title]);

        return response()->json(['ok' => true]);
    }

    /** POST /hr/safety/tasks/{id}/cancel — managers; the task is removed from every list (kept in the table). */
    public function taskCancel(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $t = $this->task($request, $aid, $id, true);
        DB::table('tasks')->where('id', $id)->update(['deleted_at' => now(), 'updated_at' => now()]);
        $this->hrNote($aid, 'hr.hs_task_cancelled', 'task', $id, ['title' => $t->title]);

        return response()->json(['ok' => true]);
    }

    /** GET /hr/safety/tasks/{id}/evidence — the assignee or a manager. */
    public function taskEvidenceFile(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $t = $this->task($request, $aid, $id);

        return response()->json(HrSafety::fileOut($t->evidence_path, $t->evidence_name, $t->evidence_mime) + ['title' => $t->title]);
    }

    // ═══════════════════════════════ MY HR ═══════════════════════════════

    /** GET /hr/me/safety — my duties (with my certificate's state), my H&S tasks, my centres' chemicals + SDS. */
    public function mine(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $me = (int) $request->user()->id;
        $centres = $this->staffCentres($request, $aid);
        if (! $centres && ! in_array($me, HrLeave::staffIds($aid), true)) return response()->json(['is_staff' => false, 'duties' => [], 'tasks' => [], 'chemicals' => []]);
        $today = $this->today($aid);
        $allNames = DB::table('centres')->where('agency_id', $aid)->pluck('name', 'id')->all();
        $duties = HrSafety::activeDuties($aid, $today)->where('user_id', $me)->get();
        $tasks = $this->hsTasks($aid)->where('assigned_to', $me)->where(fn ($w) => $w->whereIn('status', ['open', 'in_progress'])->orWhere('completed_at', '>=', now()->subDays(30)))
            ->orderByRaw("FIELD(status,'open','in_progress','done')")->orderByRaw('due_date IS NULL, due_date')->limit(100)->get();
        $names = $this->hrNames(array_merge([$me], $tasks->flatMap(fn ($t) => [(int) $t->assigned_by, (int) $t->evidence_by])->all()));
        $chems = DB::table('hr_chemicals')->where('agency_id', $aid)->whereIn('centre_id', array_keys($centres) ?: [0])->whereNull('archived_at')->orderBy('product')->limit(500)->get();

        return response()->json(['is_staff' => true, 'today' => $today, 'classes' => HrSafety::WHMIS,
            'duties' => $duties->map(fn ($d) => $this->dutyShape($d, $names, $allNames, $today))->values(),
            'tasks' => $tasks->map(fn ($t) => $this->taskShape($t, $names, $allNames, $me))->values(),
            'chemicals' => $chems->map(fn ($c) => $this->chemShape($c, $allNames, $today, false))->values()]);
    }
}
