<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Closing a provider down, properly.
 *
 * Archiving a centre is one flag. Everything that ought to happen FIRST — deciding where
 * each child goes, telling their families, settling what is owed, closing the provider's
 * own access — had no support at all, so it was done from memory or not done. Since
 * 2026-08-25 archive at least refuses while children are still enrolled, which turns a
 * silent inconsistency into a stop sign; this is the part that gets you past it.
 *
 * THIS ENDPOINT ONLY READS. It answers "what would closing this provider involve?" and
 * changes nothing. The execution half is deliberately separate: the decisions it needs —
 * withdraw this child, move that one — are irreversible, and they should be made against
 * a plan somebody has actually looked at rather than inferred in the same breath.
 */
final class CentreOffboardController extends Controller
{
    use \App\Http\Controllers\Concerns\AuthorizesTenantAccess;

    use ResolvesCentreContext;

    /**
     * POST /admin/centres/{centre}/offboard
     *
     * Carries out the plan. Decisions are per FAMILY, matching plan(), because a family
     * moves as one — the transfer endpoint refuses a split family, so offering a
     * per-child choice here would only manufacture invalid states.
     *
     * NOTHING IS REIMPLEMENTED. Transfers are driven through the real
     * ChildController::transferChild() endpoint, and withdrawals through
     * WithdrawalController::applyWithdrawal() — the same method the nightly cron calls.
     * That matters more than tidiness: those two paths carry the capacity checks, the
     * cross-agency refusal, the sibling room-name matching, the guardian deactivation and
     * the parent emails. A second copy here would drift from them within a month, which
     * is exactly the failure this codebase keeps finding in itself.
     *
     * Without `confirm` it reports what it WOULD do and writes nothing.
     */
    public function execute(Request $request, int $centreId): JsonResponse
    {
        $actor = $request->user();
        $userId = (int) $actor->id;
        $this->assertCentre($userId, $centreId);

        $data = $request->validate([
            'last_day' => ['required', 'date'],
            'decisions' => ['required', 'array', 'min:1'],
            'decisions.*.family_id' => ['required', 'integer'],
            'decisions.*.action' => ['required', 'in:transfer,withdraw'],
            'decisions.*.to_room_id' => ['nullable', 'integer'],
            'decisions.*.reason' => ['nullable', 'string', 'max:300'],
            'close_staff' => ['nullable', 'boolean'],
            'archive' => ['nullable', 'boolean'],
            'confirm' => ['nullable', 'boolean'],
        ]);

        $centre = DB::table('centres')->where('id', $centreId)->first();
        if (! $centre) {
            return response()->json(['message' => 'Centre not found'], 404);
        }
        $effective = \Illuminate\Support\Carbon::parse($data['last_day'])->toDateString();

        // Every family still at this centre must have a decision. A missing one is not a
        // "leave it for now" — it is a child who would be stranded at a closed provider.
        $liveFamilies = DB::table('children as ch')->join('families as f', 'f.id', '=', 'ch.family_id')
            ->where('f.centre_id', $centreId)->where('ch.enrollment_status', 'enrolled')
            ->whereNull('ch.deleted_at')->distinct()->pluck('f.id')->map(fn ($v) => (int) $v)->all();

        $decided = array_map(fn ($d) => (int) $d['family_id'], $data['decisions']);
        $missing = array_values(array_diff($liveFamilies, $decided));
        if ($missing) {
            $names = DB::table('families')->whereIn('id', $missing)->pluck('family_name')->all();

            return response()->json([
                'message' => count($missing).' '.(count($missing) === 1 ? 'family has' : 'families have')
                    .' no decision. Every family must be transferred or withdrawn before this provider closes.',
                'undecided_family_ids' => $missing,
                'undecided_families' => $names,
            ], 422);
        }

        // Transfers need somewhere to go.
        foreach ($data['decisions'] as $d) {
            if ($d['action'] === 'transfer' && empty($d['to_room_id'])) {
                return response()->json([
                    'message' => 'A transfer needs a destination room (family '.$d['family_id'].').',
                ], 422);
            }
        }

        if (empty($data['confirm'])) {
            return response()->json([
                'preview' => true,
                'centre' => $centre->name,
                'effective_date' => $effective,
                'families' => count($data['decisions']),
                'transfers' => count(array_filter($data['decisions'], fn ($d) => $d['action'] === 'transfer')),
                'withdrawals' => count(array_filter($data['decisions'], fn ($d) => $d['action'] === 'withdraw')),
                'will_close_staff' => (bool) ($data['close_staff'] ?? false),
                'will_archive' => (bool) ($data['archive'] ?? false),
                'message' => 'Nothing has been changed. Send confirm=true to carry this out.',
            ]);
        }

        $report = ['transferred' => [], 'withdrawn' => [], 'staff_closed' => [], 'errors' => []];

        foreach ($data['decisions'] as $d) {
            $familyId = (int) $d['family_id'];
            $kids = DB::table('children')->where('family_id', $familyId)
                ->where('enrollment_status', 'enrolled')->whereNull('deleted_at')
                ->pluck('id')->map(fn ($v) => (int) $v)->all();
            if (! $kids) {
                continue;
            }

            try {
                if ($d['action'] === 'transfer') {
                    /* Drive the REAL endpoint so every guard it carries runs here too. */
                    $sub = Request::create('/offboard-transfer', 'POST', [
                        'to_room_id' => (int) $d['to_room_id'],
                        'effective_date' => $effective,
                        'move_siblings' => true,
                        'reason' => $d['reason'] ?? ($centre->name.' is closing.'),
                    ]);
                    $sub->setUserResolver(fn () => $actor);
                    $resp = app(ChildController::class)->transferChild($sub, $kids[0]);
                    $payload = json_decode($resp->getContent(), true) ?: [];

                    if ($resp->getStatusCode() >= 300) {
                        $report['errors'][] = ['family_id' => $familyId, 'stage' => 'transfer',
                            'message' => $payload['message'] ?? 'Transfer refused'];
                    } else {
                        $report['transferred'][] = ['family_id' => $familyId,
                            'children' => $payload['children_moved'] ?? count($kids),
                            'to' => $payload['to_centre'] ?? null];
                    }
                } else {
                    foreach ($kids as $cid) {
                        $wid = DB::table('withdrawal_requests')->insertGetId([
                            'child_id' => $cid,
                            'family_id' => $familyId,
                            'requested_by_id' => $userId,
                            'reason' => $d['reason'] ?? ($centre->name.' is closing.'),
                            'last_day' => $effective,
                            'effective_date' => $effective,
                            'status' => 'approved',
                            'decided_by_id' => $userId,
                            'decided_at' => now(),
                            'admin_note' => 'Provider off-boarding',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        /* Apply NOW only if the last day has arrived. A provider closing
                           on 30 September must not withdraw children today: the row is
                           left approved with its effective_date and the nightly
                           kiddietrac:apply-withdrawals picks it up on the day. This
                           mirrors WithdrawalController::decide() exactly - calling
                           applyWithdrawal() unconditionally, as this first did, would
                           have ended care weeks early. */
                        if (\Illuminate\Support\Carbon::parse($effective)->startOfDay()
                                ->lte(now()->startOfDay())) {
                            app(WithdrawalController::class)->applyWithdrawal($wid);
                            $appliedNow = true;
                        } else {
                            $appliedNow = false;
                        }
                    }
                    $report['withdrawn'][] = ['family_id' => $familyId, 'children' => count($kids),
                        'applied' => $appliedNow ?? false,
                        'note' => ($appliedNow ?? false) ? 'applied now' : 'scheduled for '.$effective];
                }
            } catch (\Throwable $e) {
                $report['errors'][] = ['family_id' => $familyId, 'stage' => $d['action'],
                    'message' => $e->getMessage()];
            }
        }

        // ── the provider's own people ───────────────────────────────────────
        if (! empty($data['close_staff'])) {
            $staffIds = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
                ->where('ra.centre_id', $centreId)->where('ra.active', true)
                ->whereNull('u.deleted_at')->distinct()->pluck('u.id')->all();
            foreach ($staffIds as $sid) {
                if ((int) $sid === $userId) {
                    continue;   // never close the account doing the closing
                }
                try {
                    $sub = Request::create('/offboard-staff', 'DELETE');
                    $sub->setUserResolver(fn () => $actor);
                    $sub->headers->set('X-Active-Agency-Id', (string) $centre->agency_id);
                    $resp = app(AdminController::class)->destroyUser($sub, (int) $sid);
                    $payload = json_decode($resp->getContent(), true) ?: [];
                    if ($resp->getStatusCode() >= 300) {
                        $report['errors'][] = ['user_id' => (int) $sid, 'stage' => 'staff',
                            'message' => $payload['message'] ?? 'Refused'];
                    } else {
                        $report['staff_closed'][] = (int) $sid;
                    }
                } catch (\Throwable $e) {
                    $report['errors'][] = ['user_id' => (int) $sid, 'stage' => 'staff', 'message' => $e->getMessage()];
                }
            }
        }

        // ── archive LAST, and only if it is genuinely empty ─────────────────
        $archived = false;
        $archiveMessage = null;
        if (! empty($data['archive'])) {
            $stillEnrolled = DB::table('children as ch')->join('families as f', 'f.id', '=', 'ch.family_id')
                ->where('f.centre_id', $centreId)->where('ch.enrollment_status', 'enrolled')
                ->whereNull('ch.deleted_at')->count();
            if ($stillEnrolled > 0) {
                $archiveMessage = $stillEnrolled.' child(ren) are still enrolled, so the centre was left open.';
            } else {
                DB::table('centres')->where('id', $centreId)->update(['deleted_at' => now(), 'status' => 'closed']);
                $archived = true;
                $archiveMessage = 'Centre archived.';
            }
        }

        \App\Support\Audit::write([
            'user_id' => $userId,
            'agency_id' => (int) $centre->agency_id,
            'action' => 'centre.offboarded',
            'entity_type' => 'centre',
            'entity_id' => $centreId,
            'payload' => json_encode([
                'summary' => $centre->name.' off-boarded effective '.$effective.' — '
                    .count($report['transferred']).' family transfer(s), '
                    .count($report['withdrawn']).' withdrawal(s), '
                    .count($report['staff_closed']).' staff closed'
                    .($archived ? ', centre archived' : ''),
                'effective_date' => $effective,
                'report' => $report,
                'archived' => $archived,
            ]),
            'ip_address' => substr((string) $request->ip(), 0, 45),
            'created_at' => now(),
        ]);

        return response()->json([
            'ok' => empty($report['errors']),
            'centre' => $centre->name,
            'effective_date' => $effective,
            'transferred_families' => count($report['transferred']),
            'withdrawn_families' => count($report['withdrawn']),
            'staff_closed' => count($report['staff_closed']),
            'archived' => $archived,
            'archive_message' => $archiveMessage,
            /* Said plainly because the two halves behave differently and it matters:
               a withdrawal can be dated and the cron honours it, a transfer cannot be -
               there is no scheduler for moves, so they take effect the moment this runs. */
            'timing' => \Illuminate\Support\Carbon::parse($effective)->startOfDay()->lte(now()->startOfDay())
                ? 'Everything took effect now.'
                : 'Transfers took effect NOW; withdrawals are scheduled for '.$effective.'.',
            'report' => $report,
        ]);
    }

    /** GET /admin/centres/{centre}/offboard-plan */
    public function plan(Request $request, int $centreId): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $this->assertCentre($userId, $centreId);

        $centre = DB::table('centres')->where('id', $centreId)->first();
        if (! $centre) {
            return response()->json(['message' => 'Centre not found'], 404);
        }
        $agencyId = (int) $centre->agency_id;

        // ── the children who have to go somewhere ───────────────────────────
        $children = DB::table('children as ch')
            ->join('families as f', 'f.id', '=', 'ch.family_id')
            ->leftJoin('enrollments as e', function ($j) {
                $j->on('e.child_id', '=', 'ch.id')->whereNull('e.end_date');
            })
            ->leftJoin('rooms as r', 'r.id', '=', 'e.room_id')
            ->where('f.centre_id', $centreId)
            ->where('ch.enrollment_status', 'enrolled')
            ->whereNull('ch.deleted_at')
            ->orderBy('f.family_name')->orderBy('ch.first_name')
            ->get([
                'ch.id', 'ch.first_name', 'ch.last_name', 'ch.preferred_name',
                'f.id as family_id', 'f.family_name',
                'e.room_id', 'r.name as room_name',
            ]);

        /* Grouped BY FAMILY, because that is the unit that actually moves. A family
           belongs to one provider, so siblings cannot be split between destinations —
           presenting them child-by-child would invite a decision the transfer endpoint
           will then refuse. */
        $families = [];
        foreach ($children as $c) {
            $fid = (int) $c->family_id;
            if (! isset($families[$fid])) {
                $guardians = DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')
                    ->where('g.family_id', $fid)->whereNull('u.deleted_at')
                    ->get(['u.id', 'u.email', 'u.first_name', 'u.last_name']);
                $families[$fid] = [
                    'family_id' => $fid,
                    'family_name' => $c->family_name,
                    'children' => [],
                    'guardians' => $guardians->map(fn ($g) => [
                        'user_id' => (int) $g->id,
                        'name' => trim(($g->first_name ?? '').' '.($g->last_name ?? '')),
                        'email' => $g->email,
                    ])->values(),
                    'outstanding_cents' => 0,
                ];
            }
            $families[$fid]['children'][] = [
                'child_id' => (int) $c->id,
                'name' => trim(($c->preferred_name ?: $c->first_name).' '.($c->last_name ?? '')),
                'room_id' => $c->room_id ? (int) $c->room_id : null,
                'room_name' => $c->room_name,
            ];
        }

        // What each family still owes — you do not want to discover this after they leave.
        foreach (array_keys($families) as $fid) {
            try {
                $families[$fid]['outstanding_cents'] = (int) round(((float) DB::table('invoices')
                    ->where('family_id', $fid)
                    ->whereNotIn('status', ['Paid', 'Void'])
                    ->sum('total')) * 100);
            } catch (\Throwable $e) {
                $families[$fid]['outstanding_cents'] = null;   // schema varies; never guess
            }
        }

        // ── where those children could go ───────────────────────────────────
        $destinations = [];
        foreach (DB::table('rooms as r')->join('centres as c', 'c.id', '=', 'r.centre_id')
            ->where('c.agency_id', $agencyId)->where('c.id', '!=', $centreId)
            ->whereNull('c.deleted_at')
            ->orderBy('c.name')
            ->get(['r.id as room_id', 'r.name as room_name', 'r.capacity',
                   'c.id as centre_id', 'c.name as centre_name']) as $r) {

            if (! $this->mayAccessCentre($userId, (int) $r->centre_id)) {
                continue;
            }
            $occupied = DB::table('enrollments')
                ->join('children as ch', 'ch.id', '=', 'enrollments.child_id')
                ->where('enrollments.room_id', $r->room_id)->whereNull('enrollments.end_date')
                ->where('ch.enrollment_status', 'enrolled')->whereNull('ch.deleted_at')->count();
            $cap = (int) ($r->capacity ?: 0);
            $destinations[] = [
                'centre_id' => (int) $r->centre_id,
                'centre_name' => $r->centre_name,
                'room_id' => (int) $r->room_id,
                'room_name' => $r->room_name,
                'capacity' => $cap,
                'occupied' => $occupied,
                'places_left' => max(0, $cap - $occupied),
            ];
        }

        // ── the provider's own people ───────────────────────────────────────
        $staff = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.centre_id', $centreId)->where('ra.active', true)
            ->whereNull('u.deleted_at')
            ->distinct()
            ->get(['u.id', 'u.first_name', 'u.last_name', 'u.email', 'ra.role'])
            ->map(fn ($s) => [
                'user_id' => (int) $s->id,
                'name' => trim(($s->first_name ?? '').' '.($s->last_name ?? '')),
                'email' => $s->email,
                'role' => $s->role,
            ])->values();

        $childCount = $children->count();
        $placesAvailable = array_sum(array_column($destinations, 'places_left'));

        return response()->json([
            'centre' => [
                'id' => (int) $centre->id,
                'name' => $centre->name,
                'agency_id' => $agencyId,
                'archived' => $centre->deleted_at !== null,
            ],
            'families' => array_values($families),
            'destinations' => $destinations,
            'staff' => $staff,
            'summary' => [
                'children_to_place' => $childCount,
                'families_affected' => count($families),
                'places_available_elsewhere' => $placesAvailable,
                'enough_room_in_agency' => $placesAvailable >= $childCount,
                'staff_to_close' => $staff->count(),
                'total_outstanding_cents' => array_sum(array_map(
                    fn ($f) => (int) ($f['outstanding_cents'] ?? 0), $families)),
            ],
            /* Said out loud rather than implied, because the order is the whole point:
               archive LAST. The guard added on 25 Aug will refuse it until the roster is
               empty, so getting this wrong is a 422 rather than a silent mess. */
            'steps' => [
                'Decide each family\'s destination — transfer to another provider, or withdraw.',
                'Apply transfers (families move together; siblings cannot be split).',
                'Raise withdrawals with the provider\'s last operating day as the effective date.',
                'Settle anything outstanding on the affected families.',
                'Close the provider\'s own account, which also frees their rooms.',
                'Archive the centre — only once no child is enrolled here.',
            ],
        ]);
    }
}
