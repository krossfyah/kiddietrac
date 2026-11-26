<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Support\Live;
use App\Support\ReportCardQuarter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * REPORT CARDS FOR PARENTS (2026-11-04). Anthony: "add a report card section for parents to see their childs report
 * cards and a button to request a report card from their educator (should be done each quarter)".
 *
 *   GET  /parent/report-cards           the family's children in the ACTIVE agency, each child's SENT cards and
 *                                       where this quarter's card stands (ready / requested / none)
 *   POST /parent/report-cards/request   ask for this quarter's card: once per child per quarter. The room's
 *                                       educators and the centre director are told (bell + push); with nobody in
 *                                       the room, the agency admins are.
 *
 * Only cards with status 'sent' (approved by a director/admin) ever reach a parent - drafts, cards awaiting review
 * and the de-enrolment leaving drafts stay with staff. Quarters: App\Support\ReportCardQuarter.
 */
final class ParentReportCardController extends Controller
{
    use ResolvesCentreContext;

    /** The caller's children in the active agency, still with us. id => row (first_name, centre_id, room). */
    private function myChildren(Request $request, int $agencyId): array
    {
        $familyIds = DB::table('guardians')->where('user_id', $request->user()->id)->pluck('family_id')->all();
        if (! $familyIds) return [];
        $q = DB::table('children')
            ->join('families as f', 'f.id', '=', 'children.family_id')
            ->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->leftJoin('rooms as r', 'r.id', '=', 'children.primary_room_id')
            ->whereIn('children.family_id', $familyIds)
            ->where('c.agency_id', $agencyId)
            ->select('children.id', 'children.first_name', 'children.preferred_name', 'children.primary_room_id',
                'f.centre_id', 'r.name as room_name')
            ->orderBy('children.first_name');
        Live::children($q, 'children');
        Live::centres($q, 'c');
        return $q->get()->keyBy('id')->all();
    }

    public function index(Request $request): JsonResponse
    {
        $agencyId = (int) $this->resolveAgencyId($request);
        abort_unless($agencyId, 403);
        $quarter = ReportCardQuarter::current($agencyId);
        $kids = $this->myChildren($request, $agencyId);
        $ids = array_keys($kids);

        $cards = DB::table('report_cards')->whereIn('child_id', $ids ?: [0])->where('status', 'sent')->whereNull('replaced_by_id')
            ->orderByDesc('sent_at')->orderByDesc('id')
            ->get(['id', 'child_id', 'term', 'sent_at'])->groupBy('child_id');
        $asks = DB::table('report_card_requests')->whereIn('child_id', $ids ?: [0])
            ->where('quarter_key', $quarter['key'])->get()->keyBy('child_id');

        $out = [];
        foreach ($kids as $id => $k) {
            $mine = isset($cards[$id]) ? $cards[$id]->values() : collect();
            $ready = $mine->first(fn ($c) => ReportCardQuarter::matches($c->term, $quarter));
            $ask = $asks[$id] ?? null;
            $out[] = [
                'id' => (int) $id,
                'first_name' => $k->preferred_name ?: $k->first_name,
                'room' => $k->room_name,
                'cards' => $mine->map(fn ($c) => ['id' => (int) $c->id, 'term' => $c->term, 'sent_at' => $c->sent_at])->all(),
                'this_quarter' => $ready
                    ? ['status' => 'ready', 'card_id' => (int) $ready->id]
                    : ($ask ? ['status' => 'requested', 'requested_at' => $ask->created_at] : ['status' => 'none']),
            ];
        }
        return response()->json(['quarter' => $quarter, 'children' => $out]);
    }

    public function request(Request $request): JsonResponse
    {
        $data = $request->validate(['child_id' => 'required|integer']);
        $agencyId = (int) $this->resolveAgencyId($request);
        abort_unless($agencyId, 403);
        $kids = $this->myChildren($request, $agencyId);
        $child = $kids[(int) $data['child_id']] ?? null;
        abort_unless($child, 404, 'That child is not on your account.');
        $quarter = ReportCardQuarter::current($agencyId);
        $name = $child->preferred_name ?: $child->first_name;

        $ready = DB::table('report_cards')->where('child_id', $child->id)->where('status', 'sent')->pluck('term')
            ->first(fn ($t) => ReportCardQuarter::matches($t, $quarter));
        if ($ready) {
            return response()->json(['message' => "{$name}'s {$quarter['label']} report card is already here."], 422);
        }
        $have = DB::table('report_card_requests')->where('child_id', $child->id)->where('quarter_key', $quarter['key'])->first();
        if ($have) {
            return response()->json(['status' => 'requested', 'requested_at' => $have->created_at, 'already' => true]);
        }
        $id = DB::table('report_card_requests')->insertGetId([
            'child_id' => $child->id, 'agency_id' => $agencyId, 'quarter_key' => $quarter['key'], 'term' => $quarter['label'],
            'requested_by_user_id' => $request->user()->id, 'status' => 'requested',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $parent = trim((string) ($request->user()->first_name ?? '')) ?: 'A parent';
        $rows = [];
        foreach (self::writersFor((int) $child->id, $agencyId) as $uid) {
            $rows[] = [
                'user_id' => $uid, 'type' => 'report_card_request',
                'title' => "Report card requested for {$name}",
                'body' => "{$parent} asked for {$name}'s {$quarter['label']} report card.",
                'data' => json_encode(['link' => '#report-cards', 'request_id' => $id, 'child_id' => (int) $child->id]),
                'created_at' => now(),
            ];
        }
        \App\Support\Notify::write($rows);
        return response()->json(['status' => 'requested', 'requested_at' => now()->toDateTimeString()]);
    }

    /** A 15-minute signed link to a SENT card of one of the caller's children (phones open it directly). */
    public function link(Request $request, int $id): JsonResponse
    {
        $agencyId = (int) $this->resolveAgencyId($request);
        $card = DB::table('report_cards')->where('id', $id)->where('status', 'sent')->first();
        abort_unless($card && isset($this->myChildren($request, $agencyId)[(int) $card->child_id]), 404);
        return response()->json(['url' => \Illuminate\Support\Facades\URL::temporarySignedRoute('rc.signed', now()->addMinutes(15),
            ['id' => $id, 'u' => $request->user()->id])]);
    }

    /** Session-less, signed: the card shown inline. Access re-checked from the signed `u`: a guardian of the family. */
    public function signedPdf(Request $request, int $id)
    {
        $uid = (int) $request->query('u');
        $card = DB::table('report_cards')->where('id', $id)->where('status', 'sent')->first();
        abort_unless($card && $uid, 404);
        $ok = DB::table('guardians as g')->join('children as ch', 'ch.family_id', '=', 'g.family_id')
            ->where('g.user_id', $uid)->where('ch.id', $card->child_id)->exists();
        abort_unless($ok, 403);
        return app(ReportCardController::class)->pdfResponse($card, true);
    }

    /**
     * Who writes this child's card: the educators of the child's room, plus the centre's directors. Nobody there
     * (no room, an empty room) -> the agency admins, so a request is never dropped.
     * @return int[]
     */
    public static function writersFor(int $childId, int $agencyId): array
    {
        $c = DB::table('children as ch')->join('families as f', 'f.id', '=', 'ch.family_id')
            ->where('ch.id', $childId)->first(['ch.primary_room_id', 'f.centre_id']);
        if (! $c) return [];
        $ids = [];
        if ($c->primary_room_id) {
            $ids = DB::table('educator_rooms as er')
                ->join('role_assignments as ra', 'ra.user_id', '=', 'er.user_id')
                ->where('er.room_id', $c->primary_room_id)
                ->where('ra.role', 'educator')->where('ra.active', 1)->where('ra.agency_id', $agencyId)
                ->pluck('er.user_id')->all();
        }
        $ids = array_merge($ids, DB::table('role_assignments')->where('role', 'centre_director')->where('active', 1)
            ->where('agency_id', $agencyId)
            ->where(fn ($q) => $q->where('centre_id', $c->centre_id)->orWhereNull('centre_id'))
            ->pluck('user_id')->all());
        if (! $ids) {
            $ids = DB::table('role_assignments')->where('role', 'agency_admin')->where('active', 1)
                ->where('agency_id', $agencyId)->pluck('user_id')->all();
        }
        return array_values(array_unique(array_map('intval', $ids)));
    }
}
