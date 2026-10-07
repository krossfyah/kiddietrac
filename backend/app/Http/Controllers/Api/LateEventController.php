<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use App\Support\LatePickups;
use App\Support\Visibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Late pickups and late arrivals — THE one place for them (2026-10-06).
 *
 * Staff LOG what happened (the pickup time against the centre's close time, or minutes
 * late); the agency's per-minute rate and grace period give a SUGGESTED fee. A director
 * or admin DECIDES: approve (the fee goes on the invoice — App\Support\LatePickups::charge),
 * waive, or decline. A director or admin may log and approve in one step.
 *
 * This replaced two separate sections: #late-pickups, which charged on logging, and
 * #late-events, the record-then-decide list. Existing charges were migrated in as
 * approved records (2026_10_06_210000_late_pickups_one_flow).
 */
final class LateEventController extends Controller
{
    private const KINDS = ['arrival', 'departure'];

    private function ensureTable(): void
    {
        if (Schema::hasTable('late_events')) return;
        abort(503, 'Late pickups are being set up — try again in a minute.');
    }

    /** The agency in play: the selected one if the caller may use it, else their own. */
    private function agencyId(Request $request): int
    {
        $uid = (int) $request->user()->id;
        $header = (int) $request->header('X-Active-Agency-Id');
        $isPlatform = DB::table('role_assignments')->where('user_id', $uid)->where('active', 1)
            ->where('role', 'platform_admin')->exists();
        if ($header && ($isPlatform || DB::table('role_assignments')->where('user_id', $uid)
                ->where('active', 1)->where('agency_id', $header)->exists())) {
            return $header;
        }
        if ($isPlatform) return 0;   // a platform admin must pick an agency first
        return (int) DB::table('role_assignments')->where('user_id', $uid)->where('active', 1)
            ->whereNotNull('agency_id')->orderBy('id')->value('agency_id');
    }

    /** Centres the caller may see in that agency: all of it for an admin, their own
        centre(s) for a director or educator (Visibility — fails closed). */
    private function centreIds(Request $request): array
    {
        $agencyId = $this->agencyId($request);
        return $agencyId ? Visibility::centreIds($agencyId, $request->user()) : [];
    }

    /** A director or admin IN THIS AGENCY (a platform admin anywhere). */
    private function canDecide(Request $request): bool
    {
        $agencyId = $this->agencyId($request);
        if (! $agencyId) return false;
        return DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', 1)
            ->where(fn ($q) => $q->where('role', 'platform_admin')
                ->orWhere(fn ($w) => $w->whereIn('role', ['agency_admin', 'centre_director'])->where('agency_id', $agencyId)))
            ->exists();
    }

    /** Staff who log late pickups IN THIS AGENCY: educators, directors, admins (a platform
        admin anywhere). Home visitors and auditors no longer log them (Anthony, 2026-10-06,
        permissions step 1: "Late pickups for home visitors and auditors: remove"). */
    private function canLog(Request $request): bool
    {
        $agencyId = $this->agencyId($request);
        if (! $agencyId) return false;
        return DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', 1)
            ->where(fn ($q) => $q->where('role', 'platform_admin')
                ->orWhere(fn ($w) => $w->whereIn('role', ['agency_admin', 'centre_director', 'educator'])->where('agency_id', $agencyId)))
            ->exists();
    }

    /** GET /provider/late-events/options — children, centres with close times, the fee policy. */
    public function options(Request $request): JsonResponse
    {
        abort_unless($this->canLog($request), 403, 'Late pickups are logged by educators, directors and admins.');
        $agencyId = $this->agencyId($request);
        $centreIds = $agencyId ? Visibility::centreIds($agencyId, $request->user()) : [];
        $centres = DB::table('centres')->whereIn('id', $centreIds)->orderBy('name')->get(['id', 'name', 'close_time']);
        $children = $centreIds ? DB::table('children as c')->join('families as f', 'f.id', '=', 'c.family_id')
            ->whereIn('f.centre_id', $centreIds)->whereNull('c.deleted_at')
            ->where(fn ($q) => $q->whereNull('c.enrollment_status')->orWhereNotIn('c.enrollment_status', ['withdrawn', 'waitlist', 'graduated']))
            ->orderBy('c.first_name')->get(['c.id', 'c.first_name', 'c.last_name', 'f.centre_id']) : collect();
        return response()->json([
            'centres' => $centres, 'children' => $children,
            'policy' => $agencyId ? LatePickups::policy($agencyId) : ['per_minute' => 0, 'grace' => 0],
            'can_decide' => $this->canDecide($request),
            'today' => Carbon::now(AgencyTime::tz($agencyId ?: null))->format('Y-m-d\TH:i'),
        ]);
    }

    /**
     * POST /provider/late-events — log a late pickup or arrival.
     *   pickup_at + close_time (a pickup: minutes worked out), or minutes (either kind),
     *   charge=true (director/admin only): approve at once with fee_amount (default: the
     *   suggested fee).
     */
    public function store(Request $request): JsonResponse
    {
        $this->ensureTable();
        abort_unless($this->canLog($request), 403, 'Late pickups are logged by educators, directors and admins.');
        $data = $request->validate([
            'child_id' => ['required', 'integer'],
            'kind' => ['nullable', 'string', 'in:' . implode(',', self::KINDS)],
            'pickup_at' => ['nullable', 'date'],
            'close_time' => ['nullable', 'date_format:H:i,H:i:s'],
            'minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'note' => ['nullable', 'string', 'max:1000'],
            'occurred_on' => ['nullable', 'date'],
            'charge' => ['nullable', 'boolean'],
            'fee_amount' => ['nullable', 'numeric', 'min:0', 'max:10000'],
        ]);
        $kind = $data['kind'] ?? 'departure';

        // The child must be one this person can actually see.
        $centreIds = $this->centreIds($request);
        $centreId = $centreIds ? DB::table('children as ch')->join('families as f', 'f.id', '=', 'ch.family_id')
            ->where('ch.id', $data['child_id'])->whereIn('f.centre_id', $centreIds)
            ->value('f.centre_id') : null;
        abort_unless($centreId, 404, 'Child not found');
        $agencyId = (int) DB::table('centres')->where('id', $centreId)->value('agency_id');
        $tz = AgencyTime::tzForCentre((int) $centreId) ?: 'America/Toronto';

        // Wall clock as typed: "2026-10-06T18:32" is 6:32 PM at the centre, stored as such.
        $pickupAt = ! empty($data['pickup_at']) ? Carbon::parse($data['pickup_at'])->format('Y-m-d H:i:s') : null;
        $close = $data['close_time'] ?? null;
        if (! $close && $pickupAt) {
            $close = DB::table('centres')->where('id', $centreId)->value('close_time');
        }
        $minutes = (int) ($data['minutes'] ?? 0);
        if ($pickupAt && $close && $kind === 'departure') {
            $closeAt = Carbon::parse(substr($pickupAt, 0, 10) . ' ' . $close);
            $minutes = (int) $closeAt->diffInMinutes(Carbon::parse($pickupAt), false);
            if ($minutes < 1) {
                return response()->json(['message' => 'The pickup time is not after the centre\'s close time (' . Carbon::parse($close)->format('g:i A') . ').'], 422);
            }
        }
        if ($minutes < 1) {
            return response()->json(['message' => 'Enter the pickup time (or how many minutes late).'], 422);
        }
        $on = $pickupAt ? substr($pickupAt, 0, 10) : (($data['occurred_on'] ?? null) ?: Carbon::now($tz)->toDateString());
        $suggested = $kind === 'departure' ? LatePickups::suggest($agencyId, $minutes) : null;

        $charge = ! empty($data['charge']);
        if ($charge) {
            abort_unless($this->canDecide($request), 403, 'Only directors and admins can charge a fee.');
        }

        $id = DB::table('late_events')->insertGetId([
            'child_id' => $data['child_id'],
            'centre_id' => $centreId,
            'kind' => $kind,
            'occurred_on' => $on,
            'pickup_at' => $pickupAt,
            'close_time' => $close,
            'minutes' => $minutes,
            'suggested_fee' => $suggested,
            'note' => $data['note'] ?? null,
            'recorded_by_id' => $request->user()->id,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $ev = DB::table('late_events')->where('id', $id)->first();
        $mail = new LatePickups();

        if ($charge) {
            $fee = array_key_exists('fee_amount', $data) && $data['fee_amount'] !== null ? (float) $data['fee_amount'] : (float) ($suggested ?? 0);
            if ($fee <= 0) {
                // Nothing to charge (within the grace period): record it as waived, not as a $0 charge.
                DB::table('late_events')->where('id', $id)->update(['status' => 'waived', 'fee_amount' => null,
                    'decided_by_id' => $request->user()->id, 'decided_at' => now(), 'decision_note' => 'Within the grace period — no fee.', 'updated_at' => now()]);
                return response()->json(['ok' => true, 'id' => $id, 'status' => 'waived', 'minutes' => $minutes, 'suggested_fee' => $suggested, 'fee' => 0], 201);
            }
            $res = DB::transaction(fn () => LatePickups::charge($ev, $fee, (int) $request->user()->id));
            // One email: logged AND charged.
            $mail->emailCharged($ev, $fee, $res['invoice'], $request->user());
            return response()->json(['ok' => true, 'id' => $id, 'status' => 'approved', 'minutes' => $minutes, 'suggested_fee' => $suggested,
                'fee' => $fee, 'charge_id' => $res['charge_id'], 'invoice_line_id' => $res['invoice_line_id']], 201);
        }

        // Waiting on a director or admin to decide — tell them (never fails the log).
        $mail->emailPending($ev, $request->user());
        return response()->json(['ok' => true, 'id' => $id, 'status' => 'pending', 'minutes' => $minutes, 'suggested_fee' => $suggested], 201);
    }

    /** GET /provider/late-events — the one list. ?status=pending|approved|waived|declined|all */
    public function index(Request $request): JsonResponse
    {
        abort_unless($this->canLog($request), 403, 'Late pickups are for educators, directors and admins.');
        $this->ensureTable();
        $centreIds = $this->centreIds($request);
        $empty = ['pending' => 0, 'approved' => 0, 'waived' => 0, 'declined' => 0, 'all' => 0];
        if (! $centreIds) {
            return response()->json(['events' => [], 'counts' => $empty, 'can_decide' => $this->canDecide($request)]);
        }
        $q = DB::table('late_events as l')
            ->join('children as ch', 'ch.id', '=', 'l.child_id')
            ->leftJoin('families as f', 'f.id', '=', 'ch.family_id')
            ->leftJoin('centres as c', 'c.id', '=', 'l.centre_id')
            ->leftJoin('users as u', 'u.id', '=', 'l.recorded_by_id')
            ->leftJoin('users as d', 'd.id', '=', 'l.decided_by_id')
            ->leftJoin('invoice_lines as il', 'il.id', '=', 'l.invoice_line_id')
            ->leftJoin('invoices as inv', 'inv.id', '=', 'il.invoice_id')
            ->whereIn('l.centre_id', $centreIds);
        $status = strtolower(trim((string) $request->query('status', '')));
        if ($status !== '' && $status !== 'all') {
            $q->where('l.status', $status);
        }
        if ($from = $request->query('from')) { $q->whereDate('l.occurred_on', '>=', $from); }
        if ($to = $request->query('to')) { $q->whereDate('l.occurred_on', '<=', $to); }

        $rows = $q->orderByDesc('l.occurred_on')->orderByDesc('l.id')->limit(500)
            ->get([
                'l.id', 'l.kind', 'l.occurred_on', 'l.pickup_at', 'l.close_time', 'l.minutes', 'l.suggested_fee', 'l.note', 'l.status',
                'l.fee_amount', 'l.decided_at', 'l.decision_note', 'l.created_at', 'l.charge_id', 'l.invoice_line_id',
                'inv.id as invoice_id', 'inv.invoice_number',
                'ch.first_name', 'ch.last_name', 'f.family_name', 'c.name as centre_name',
                DB::raw("TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) as recorded_by"),
                DB::raw("TRIM(CONCAT(COALESCE(d.first_name,''),' ',COALESCE(d.last_name,''))) as decided_by"),
            ]);

        $counts = DB::table('late_events')->whereIn('centre_id', $centreIds)
            ->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status')->all();
        $out = $empty;
        foreach ($counts as $k => $n) { $out[$k] = (int) $n; $out['all'] += (int) $n; }

        return response()->json([
            'events' => $rows->map(fn ($r) => [
                'id' => $r->id,
                'kind' => $r->kind,
                'child_name' => trim($r->first_name . ' ' . $r->last_name),
                'family_name' => $r->family_name,
                'centre_name' => $r->centre_name,
                'occurred_on' => $r->occurred_on,
                'pickup_at' => $r->pickup_at,             // wall clock, as typed
                'close_time' => $r->close_time,
                'minutes' => (int) $r->minutes,
                'suggested_fee' => $r->suggested_fee !== null ? (float) $r->suggested_fee : null,
                'note' => $r->note,
                'status' => $r->status,
                'fee_amount' => $r->fee_amount !== null ? (float) $r->fee_amount : null,
                'recorded_by' => $r->recorded_by ?: null,
                'logged_at' => $r->created_at,            // UTC instant
                'decided_by' => $r->decided_by ?: null,
                'decided_at' => $r->decided_at,           // UTC instant
                'decision_note' => $r->decision_note,
                'charge_id' => $r->charge_id,
                'invoice_id' => $r->invoice_id,
                'invoice_number' => $r->invoice_number,
            ]),
            'counts' => $out,
            'can_decide' => $this->canDecide($request),
        ]);
    }

    /** POST /provider/late-events/{id}/decide — approve (charge), waive, or decline. Pending only. */
    public function decide(Request $request, int $id): JsonResponse
    {
        $this->ensureTable();
        $data = $request->validate([
            'status' => ['required', 'string', 'in:approved,waived,declined'],
            'fee_amount' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'decision_note' => ['nullable', 'string', 'max:1000'],
        ]);
        // Only somebody who can bill decides. An educator records and stops there.
        abort_unless($this->canDecide($request), 403, 'Directors and admins only');
        $centreIds = $this->centreIds($request);
        abort_unless($centreIds, 404, 'Not found');

        return DB::transaction(function () use ($data, $id, $centreIds, $request) {
            // Locked, and pending only: a second click (or a second person) must not charge twice.
            $row = DB::table('late_events')->where('id', $id)->whereIn('centre_id', $centreIds)->lockForUpdate()->first();
            abort_unless($row, 404, 'Not found');
            if ($row->status !== 'pending') {
                return response()->json(['message' => 'This one has already been decided (' . $row->status . ').'], 409);
            }
            if ($data['status'] === 'approved') {
                $fee = (float) ($data['fee_amount'] ?? 0);
                if ($fee <= 0) {
                    return response()->json(['message' => 'Enter a fee, or waive it instead.'], 422);
                }
                $res = LatePickups::charge($row, $fee, (int) $request->user()->id, $data['decision_note'] ?? null);
                return response()->json(['ok' => true, 'charge_id' => $res['charge_id'], 'invoice_line_id' => $res['invoice_line_id'],
                    'billed' => (bool) $res['invoice']]);
            }
            DB::table('late_events')->where('id', $id)->update([
                'status' => $data['status'],
                // A waived or declined event carries no fee, whatever was typed in the box.
                'fee_amount' => null,
                'decision_note' => $data['decision_note'] ?? null,
                'decided_by_id' => $request->user()->id,
                'decided_at' => now(),
                'updated_at' => now(),
            ]);
            return response()->json(['ok' => true]);
        });
    }
}
