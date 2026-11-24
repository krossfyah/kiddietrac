<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Support\Supplies;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Supplies from home (2026-10-23). The rules live in App\Support\Supplies. */
class SupplyController extends Controller
{
    use ResolvesCentreContext;

    private function agencyAdmin(Request $request, ?int $agencyId): bool
    {
        return $agencyId && DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)
            ->where(fn ($q) => $q->where('role', 'platform_admin')->orWhere(fn ($w) => $w->where('role', 'agency_admin')->where('agency_id', $agencyId)))->exists();
    }

    /** GET /provider/supplies/catalogue */
    public function catalogue(Request $request): JsonResponse
    {
        $agencyId = (int) ($this->resolveAgencyId($request) ?: 0) ?: null;
        return response()->json(['groups' => Supplies::catalogue($agencyId), 'can_edit' => $this->agencyAdmin($request, $agencyId)]);
    }

    /** PUT /provider/supplies/catalogue {groups:[{name, items[]}]} — agency admins. An empty list restores the default. */
    public function saveCatalogue(Request $request): JsonResponse
    {
        $agencyId = (int) ($this->resolveAgencyId($request) ?: 0) ?: null;
        abort_unless($this->agencyAdmin($request, $agencyId), 403, 'Only an agency admin can change the supply list.');
        $data = $request->validate(['groups' => ['present', 'array', 'max:12'], 'groups.*.name' => ['required', 'string', 'max:40'],
            'groups.*.items' => ['required', 'array', 'max:30'], 'groups.*.items.*' => ['string', 'max:40']]);
        $groups = Supplies::saveCatalogue($agencyId, $data['groups']);
        \App\Support\Audit::write(['user_id' => $request->user()->id, 'action' => 'supplies.list_changed', 'entity_type' => 'agency', 'entity_id' => $agencyId,
            'payload' => json_encode(['summary' => 'Changed the supply list parents can be asked for.', 'groups' => $groups]), 'created_at' => now()]);
        return response()->json(['groups' => $groups]);
    }

    /** GET /provider/supplies?child_id=&room_id=&all=1 — pending ones unless all=1 (then the last 30 days too). */
    public function index(Request $request): JsonResponse
    {
        $visible = $this->visibleChildIds($request);
        $q = DB::table('supply_requests as s')->leftJoin('users as u', 'u.id', '=', 's.requested_by')
            ->whereIn('s.child_id', $visible ?: [-1]);
        if ($cid = (int) $request->query('child_id')) $q->where('s.child_id', $cid);
        if ($rid = (int) $request->query('room_id')) {
            $inRoom = DB::table('enrollments')->where('room_id', $rid)
                ->where(fn ($w) => $w->whereNull('end_date')->orWhere('end_date', '>=', \App\Support\AgencyTime::today()))->pluck('child_id')->all();
            $q->whereIn('s.child_id', $inRoom ?: [-1]);
        }
        if ($request->boolean('all')) $q->where(fn ($w) => $w->whereIn('s.status', Supplies::PENDING)->orWhere('s.updated_at', '>=', now()->subDays(30)));
        else $q->whereIn('s.status', array_merge(Supplies::PENDING, ['dropped']));
        $rows = $q->orderByDesc('s.created_at')->limit(300)->get(['s.*', DB::raw("TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) as requested_by_name")]);
        $names = DB::table('children')->whereIn('id', $rows->pluck('child_id')->unique())->get(['id', 'first_name', 'preferred_name'])
            ->mapWithKeys(fn ($c) => [$c->id => Supplies::childName($c)])->all();
        return response()->json(['requests' => $rows->map(fn ($r) => Supplies::shape($r, $names))->values()]);
    }

    /** POST /provider/supplies {child_ids[], items[{label, note}], note?, needed_by?} */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_ids' => ['required', 'array', 'min:1', 'max:60'], 'child_ids.*' => ['integer'],
            'items' => ['required', 'array', 'min:1', 'max:20'], 'items.*.label' => ['required', 'string', 'max:60'], 'items.*.note' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:1000'], 'needed_by' => ['nullable', 'date'],
        ]);
        $ids = array_values(array_unique(array_map('intval', $data['child_ids'])));
        foreach ($ids as $cid) {
            abort_unless($this->canAccessChildScoped($request, $cid), 403, 'You can only ask for supplies for children in your care.');
        }
        $items = array_map(fn ($i) => ['label' => trim($i['label']), 'note' => isset($i['note']) && trim((string) $i['note']) !== '' ? trim($i['note']) : null], $data['items']);
        $made = Supplies::create($ids, $items, $data['note'] ?? null, $data['needed_by'] ?? null, $request->user());
        \App\Support\Audit::write(['user_id' => $request->user()->id, 'action' => 'supplies.requested', 'entity_type' => 'supply_request', 'entity_id' => $made[0] ?? null,
            'payload' => json_encode(['summary' => 'Asked ' . count($ids) . ' famil' . (count($ids) === 1 ? 'y' : 'ies') . ' to bring: ' . Supplies::itemsText($items), 'child_ids' => $ids, 'needed_by' => $data['needed_by'] ?? null]),
            'created_at' => now()]);
        return response()->json(['ok' => true, 'ids' => $made], 201);
    }

    private function staffRow(Request $request, int $id): object
    {
        $r = DB::table('supply_requests')->where('id', $id)->first();
        abort_unless($r && $this->canAccessChildScoped($request, (int) $r->child_id), 404, 'Not found.');
        return $r;
    }

    /** POST /provider/supplies/{id}/received */
    public function received(Request $request, int $id): JsonResponse
    {
        $r = $this->staffRow($request, $id);
        Supplies::setStatus([$r->id], 'received', (int) $request->user()->id);
        \App\Support\Audit::write(['user_id' => $request->user()->id, 'action' => 'supplies.received', 'entity_type' => 'supply_request', 'entity_id' => $r->id,
            'payload' => json_encode(['summary' => 'Marked supplies received: ' . Supplies::itemsText(json_decode($r->items, true) ?: [])]), 'created_at' => now()]);
        return response()->json(['ok' => true]);
    }

    /** POST /provider/supplies/{id}/cancel */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $r = $this->staffRow($request, $id);
        Supplies::setStatus([$r->id], 'cancelled', (int) $request->user()->id);
        \App\Support\Audit::write(['user_id' => $request->user()->id, 'action' => 'supplies.cancelled', 'entity_type' => 'supply_request', 'entity_id' => $r->id,
            'payload' => json_encode(['summary' => 'Cancelled a supply request: ' . Supplies::itemsText(json_decode($r->items, true) ?: [])]), 'created_at' => now()]);
        return response()->json(['ok' => true]);
    }

    /** GET /parent/supplies — this parent's families: pending, plus the last 14 days. */
    public function parentIndex(Request $request): JsonResponse
    {
        $fams = DB::table('guardians')->where('user_id', $request->user()->id)->pluck('family_id')->all();
        $rows = DB::table('supply_requests as s')->leftJoin('users as u', 'u.id', '=', 's.requested_by')
            ->whereIn('s.family_id', $fams ?: [-1])->where('s.status', '!=', 'cancelled')
            ->where(fn ($w) => $w->whereIn('s.status', Supplies::PENDING)->orWhere('s.updated_at', '>=', now()->subDays(14)))
            ->orderByDesc('s.created_at')->limit(100)
            ->get(['s.*', DB::raw("COALESCE(u.first_name,'') as requested_by_name")]);   // staff first names only to families
        $names = DB::table('children')->whereIn('id', $rows->pluck('child_id')->unique())->get(['id', 'first_name', 'preferred_name'])
            ->mapWithKeys(fn ($c) => [$c->id => Supplies::childName($c)])->all();
        return response()->json(['requests' => $rows->map(fn ($r) => Supplies::shape($r, $names))->values()]);
    }

    /** POST /parent/supplies/respond {ids[], response: coming|dropped|cant, note?} */
    public function respond(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:50'], 'ids.*' => ['integer'],
            'response' => ['required', 'in:coming,dropped,cant'], 'note' => ['nullable', 'string', 'max:500']]);
        $fams = DB::table('guardians')->where('user_id', $request->user()->id)->pluck('family_id')->all();
        $ids = DB::table('supply_requests')->whereIn('id', $data['ids'])->whereIn('family_id', $fams ?: [-1])
            ->whereIn('status', array_merge(Supplies::PENDING, ['dropped']))->pluck('id')->all();
        abort_unless($ids, 404, 'That request is no longer open.');
        Supplies::setStatus($ids, $data['response'], (int) $request->user()->id, $data['note'] ?? null);
        $said = ['coming' => 'will bring', 'dropped' => 'dropped off', 'cant' => 'can\'t bring'][$data['response']];
        \App\Support\Audit::write(['user_id' => $request->user()->id, 'action' => 'supplies.parent_' . $data['response'], 'entity_type' => 'supply_request', 'entity_id' => $ids[0],
            'payload' => json_encode(['summary' => 'Parent said they ' . $said . ' the requested supplies.' . (! empty($data['note']) ? ' "' . $data['note'] . '"' : ''), 'ids' => $ids]), 'created_at' => now()]);
        return response()->json(['ok' => true]);
    }
}
