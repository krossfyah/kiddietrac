<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Late arrivals and late departures, logged by the educator who was there.
 *
 * Distinct from invoices:apply-late-fees, which charges interest on an overdue invoice.
 * This is the other kind of late: a child collected after closing, or arriving after the
 * session started.
 *
 * An educator RECORDS; an admin DECIDES. That separation is the point — the person at the
 * door knows what happened and should not have to judge whether it costs the family
 * money, and the person who bills should not be reconstructing the evening from memory. A
 * logged event is a fact; the fee is a decision made about it afterwards, with a name and
 * a timestamp against it.
 */
final class LateEventController extends Controller
{
    use \App\Http\Concerns\ReportAlerts;

    private const KINDS = ['arrival', 'departure'];

    private function ensureTable(): void
    {
        if (Schema::hasTable('late_events')) return;
        Schema::create('late_events', function ($t) {
            $t->id();
            $t->unsignedBigInteger('child_id')->index();
            $t->unsignedBigInteger('centre_id')->nullable()->index();
            $t->string('kind', 16);                 // arrival | departure
            $t->date('occurred_on');
            $t->unsignedInteger('minutes')->default(0);
            $t->text('note')->nullable();
            $t->unsignedBigInteger('recorded_by_id')->nullable();
            // pending until somebody with the authority to bill has looked at it.
            $t->string('status', 16)->default('pending');
            $t->decimal('fee_amount', 8, 2)->nullable();
            $t->unsignedBigInteger('decided_by_id')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->text('decision_note')->nullable();
            $t->timestamps();
            $t->index(['centre_id', 'occurred_on']);
        });
    }

    /** Centres the caller can see, so nothing here can reach another agency's children. */
    private function centreIds(Request $request): array
    {
        $uid = (int) $request->user()->id;
        $header = (int) $request->header('X-Active-Agency-Id');
        $isPlatform = DB::table('role_assignments')->where('user_id', $uid)->where('active', 1)
            ->where('role', 'platform_admin')->exists();

        $agencyId = null;
        if ($header && ($isPlatform || DB::table('role_assignments')->where('user_id', $uid)
                ->where('active', 1)->where('agency_id', $header)->exists())) {
            $agencyId = $header;
        }
        if (! $agencyId) {
            $agencyId = (int) DB::table('role_assignments')->where('user_id', $uid)->where('active', 1)
                ->whereIn('role', ['agency_admin', 'platform_admin', 'centre_director'])->value('agency_id');
        }

        if ($agencyId) {
            return DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all();
        }
        // An educator without an agency-level role: their own centres only.
        return DB::table('role_assignments')->where('user_id', $uid)->where('active', 1)
            ->whereNotNull('centre_id')->pluck('centre_id')->unique()->values()->all();
    }

    /** POST /provider/late-events — the educator's quick log. */
    public function store(Request $request): JsonResponse
    {
        $this->ensureTable();
        $data = $request->validate([
            'child_id' => ['required', 'integer'],
            'kind' => ['required', 'string', 'in:' . implode(',', self::KINDS)],
            'minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'note' => ['nullable', 'string', 'max:1000'],
            'occurred_on' => ['nullable', 'date'],
        ]);

        // The child must be one this person can actually see.
        $centreIds = $this->centreIds($request);
        $centreId = DB::table('children as ch')->join('families as f', 'f.id', '=', 'ch.family_id')
            ->where('ch.id', $data['child_id'])->whereIn('f.centre_id', $centreIds)
            ->value('f.centre_id');
        abort_unless($centreId, 404, 'Child not found');

        $tz = AgencyTime::tzForCentre((int) $centreId) ?: 'America/Toronto';
        // Agency time, not server time: an event logged at 18:10 in Toronto belongs to
        // that day, not to whatever tomorrow UTC thinks it is.
        // ?? not ?:, and the key may be absent entirely — a `nullable` rule does not put
        // an omitted field into the validated array.
        $on = ($data['occurred_on'] ?? null) ?: Carbon::now($tz)->toDateString();

        $id = DB::table('late_events')->insertGetId([
            'child_id' => $data['child_id'],
            'centre_id' => $centreId,
            'kind' => $data['kind'],
            'occurred_on' => $on,
            'minutes' => $data['minutes'],
            'note' => $data['note'] ?? null,
            'recorded_by_id' => $request->user()->id,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // Waiting on a director or admin to decide — tell them (never fails the log).
        try { $this->emailPending($request, $id, (int) $centreId, $data, $on); }
        catch (\Throwable $e) { \Illuminate\Support\Facades\Log::warning('late event alert failed', ['id' => $id, 'error' => $e->getMessage()]); }
        return response()->json(['ok' => true, 'id' => $id], 201);
    }

    /** Email the centre's directors and the agency admins that a late event needs a decision. */
    private function emailPending(Request $request, int $id, int $centreId, array $data, string $on): void
    {
        $agencyId = (int) DB::table('centres')->where('id', $centreId)->value('agency_id');
        if (! $agencyId) return;
        $recipients = $this->alertRecipients($agencyId, $centreId);
        if (! $recipients) return;
        $child = DB::table('children')->where('id', $data['child_id'])->first(['first_name', 'last_name']);
        $childName = trim(($child->first_name ?? '') . ' ' . ($child->last_name ?? ''));
        $by = $request->user();
        $byName = trim(($by->first_name ?? '') . ' ' . ($by->last_name ?? '')) ?: 'A staff member';
        $kind = $data['kind'] === 'arrival' ? 'late arrival' : 'late pickup';
        $tz = AgencyTime::tz($agencyId);
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $line = fn ($k, $v) => $v === null || $v === '' ? '' : '<tr><td style="padding:6px 12px 6px 0;color:#64748b;font-size:13px;white-space:nowrap;">' . $k . '</td><td style="padding:6px 0;font-weight:700;color:#0f172a;font-size:13px;">' . $e($v) . '</td></tr>';
        $rows = $line('Child', $childName) . $line('Centre', DB::table('centres')->where('id', $centreId)->value('name'))
            . $line('What', ucfirst($kind)) . $line('Date', Carbon::parse($on)->format('D M j, Y')) . $line('Minutes', $data['minutes'])
            . $line('Recorded by', $byName) . $line('Recorded at', now()->setTimezone($tz)->format('M j, Y g:i A')) . $line('Note', $data['note'] ?? null);
        $body = '<p style="margin:0 0 12px;font-size:14.5px;color:#243244;">A ' . $kind . ' was recorded and is waiting for a decision — approve a fee, waive it, or decline.</p>'
            . '<table style="border-collapse:collapse;margin:8px 0 4px;">' . $rows . '</table>'
            . '<p style="margin:14px 0 0;font-size:12.5px;color:#64748b;">Decide it under <strong>Late arrivals &amp; pickups</strong> in your portal.</p>';
        $html = \App\Services\EmailTemplate::wrap($agencyId, $body, [
            'eyebrow' => strtoupper($kind), 'title' => ucfirst($kind) . ' needs a decision — ' . $childName,
            'subtitle' => Carbon::parse($on)->format('M j, Y') . ' · ' . $data['minutes'] . ' min',
            'preheader' => $byName . ' recorded a ' . $kind . ' for ' . $childName . '; it needs your decision.',
        ]);
        $this->sendReportAlert($agencyId, $recipients, ucfirst($kind) . ' to review: ' . $childName . ' (' . $data['minutes'] . ' min)', $html);
    }

    /** GET /provider/late-events — the review list. */
    public function index(Request $request): JsonResponse
    {
        $this->ensureTable();
        $centreIds = $this->centreIds($request);
        if (! $centreIds) {
            return response()->json(['events' => [], 'counts' => ['pending' => 0]]);
        }

        $q = DB::table('late_events as l')
            ->join('children as ch', 'ch.id', '=', 'l.child_id')
            ->leftJoin('families as f', 'f.id', '=', 'ch.family_id')
            ->leftJoin('centres as c', 'c.id', '=', 'l.centre_id')
            ->leftJoin('users as u', 'u.id', '=', 'l.recorded_by_id')
            ->leftJoin('users as d', 'd.id', '=', 'l.decided_by_id')
            ->whereIn('l.centre_id', $centreIds);

        $status = strtolower(trim((string) $request->query('status', '')));
        if ($status !== '' && $status !== 'all') {
            $q->where('l.status', $status);
        }
        if ($from = $request->query('from')) { $q->whereDate('l.occurred_on', '>=', $from); }
        if ($to = $request->query('to')) { $q->whereDate('l.occurred_on', '<=', $to); }

        $rows = $q->orderByDesc('l.occurred_on')->orderByDesc('l.id')->limit(500)
            ->get([
                'l.id', 'l.kind', 'l.occurred_on', 'l.minutes', 'l.note', 'l.status',
                'l.fee_amount', 'l.decided_at', 'l.decision_note',
                'ch.first_name', 'ch.last_name', 'f.family_name', 'c.name as centre_name',
                DB::raw("CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) as recorded_by"),
                DB::raw("CONCAT(COALESCE(d.first_name,''),' ',COALESCE(d.last_name,'')) as decided_by"),
            ]);

        return response()->json([
            'events' => $rows->map(fn ($r) => [
                'id' => $r->id,
                'kind' => $r->kind,
                'child_name' => trim($r->first_name . ' ' . $r->last_name),
                'family_name' => $r->family_name,
                'centre_name' => $r->centre_name,
                'occurred_on' => $r->occurred_on,
                'minutes' => (int) $r->minutes,
                'note' => $r->note,
                'status' => $r->status,
                'fee_amount' => $r->fee_amount !== null ? (float) $r->fee_amount : null,
                'recorded_by' => trim((string) $r->recorded_by) ?: null,
                'decided_by' => trim((string) $r->decided_by) ?: null,
                'decided_at' => $r->decided_at,
                'decision_note' => $r->decision_note,
            ]),
            'counts' => [
                'pending' => DB::table('late_events')->whereIn('centre_id', $centreIds)->where('status', 'pending')->count(),
            ],
        ]);
    }

    /** POST /provider/late-events/{id}/decide — approve with a fee, waive, or decline. */
    public function decide(Request $request, int $id): JsonResponse
    {
        $this->ensureTable();
        $data = $request->validate([
            'status' => ['required', 'string', 'in:approved,waived,declined'],
            'fee_amount' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'decision_note' => ['nullable', 'string', 'max:1000'],
        ]);

        // Only somebody who can bill decides. An educator records and stops there.
        $canDecide = DB::table('role_assignments')->where('user_id', $request->user()->id)
            ->where('active', 1)->whereIn('role', ['agency_admin', 'platform_admin', 'centre_director'])
            ->exists();
        abort_unless($canDecide, 403, 'Directors and admins only');

        $centreIds = $this->centreIds($request);
        $row = DB::table('late_events')->where('id', $id)->whereIn('centre_id', $centreIds)->first();
        abort_unless($row, 404, 'Not found');

        DB::table('late_events')->where('id', $id)->update([
            'status' => $data['status'],
            // A waived or declined event carries no fee, whatever was typed in the box.
            'fee_amount' => $data['status'] === 'approved' ? ($data['fee_amount'] ?? 0) : null,
            'decision_note' => $data['decision_note'] ?? null,
            'decided_by_id' => $request->user()->id,
            'decided_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }
}
