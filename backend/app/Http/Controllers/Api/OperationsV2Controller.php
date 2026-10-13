<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v22p56 — Procare-parity operations:
 *   - Closure / holiday calendar
 *   - Late pickup fee logging
 *   - Real-time room ratio compliance check
 *   - Bus routes + transportation rosters
 *   - Auto room rotation (age-band promotions)
 */
final class OperationsV2Controller extends Controller
{
    // =========================================================
    // Closure / holiday calendar
    // =========================================================
    public function closures(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        /* Operational rather than personal, but still not theirs: a centre-18
           director was shown centre 16's closure calendar. */
        $centreIds = \App\Support\Visibility::centreIds((int) $agencyId, $request->user());
        // Who scheduled it, joined here rather than looked up per row on the screen.
        // Both columns already existed; nothing ever read them.
        $rows = DB::table('centre_closures as cc')
            ->join('centres as c', 'c.id', '=', 'cc.centre_id')
            ->leftJoin('users as u', 'u.id', '=', 'cc.created_by_id')
            ->whereIn('cc.centre_id', $centreIds)
            ->orderBy('cc.closure_date')
            ->select('cc.*', 'c.name as centre_name',
                DB::raw("NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),'') as added_by"))
            ->get();
        return response()->json(['data' => $rows]);
    }

    public function addClosure(Request $request): JsonResponse
    {
        $data = $request->validate([
            'centre_id' => 'required|integer',
            'closure_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:closure_date',
            'closure_type' => 'required|in:holiday,pd_day,emergency,renovation,other',
            'reason' => 'nullable|string|max:200',
            'affects_billing' => 'nullable|boolean',
        ]);
        $this->assertCentreAccess($request, (int) $data['centre_id']);
        $id = DB::table('centre_closures')->insertGetId([
            'centre_id' => $data['centre_id'],
            'closure_date' => $data['closure_date'],
            'end_date' => $data['end_date'] ?? null,
            'closure_type' => $data['closure_type'],
            'reason' => $data['reason'] ?? null,
            'affects_billing' => $data['affects_billing'] ?? true,
            'created_by_id' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // Telling everyone the moment a closure is entered is a choice, not a given:
        // an agency drafting next year's calendar does not want every family emailed as
        // each date is keyed in. The in-app notice to admins always goes out.
        $centreAgency = (int) DB::table('centres')->where('id', $data['centre_id'])->value('agency_id');
        $aSettings = json_decode((string) DB::table('agencies')->where('id', $centreAgency)->value('settings'), true) ?: [];
        $remindersOn = ($aSettings['closure_reminders_enabled'] ?? true) !== false;
        if ($remindersOn && ($aSettings['closure_reminder_immediate'] ?? true) !== false) {
            $this->announceClosure((int) $id, (int) $data['centre_id']);
        }
        // announceClosure covers families and centre staff; agency admins and directors
        // who hold no role AT the centre were never told a closure had been added.
        $this->announceClosureChange((int) $id, (int) $data['centre_id'], 'added');

        return response()->json(['id' => $id], 201);
    }

    /**
     * Tell everyone the centre is closed — families AND the staff who work there.
     *
     * The original notified guardians only, by in-app bell only, and titled it with
     * closure_date alone, so a week-long closure announced a single day of itself and the
     * educators due in on those days were never told at all.
     *
     * A bell is what you see next time you open the app. A closure is something you need
     * to know BEFORE you set off, so it goes by email too.
     */
    private function announceClosure(int $closureId, int $centreId): void
    {
        $row = DB::table('centre_closures')->where('id', $closureId)->first();
        if (! $row) {
            return;
        }

        $centre = DB::table('centres')->where('id', $centreId)->first();
        $agencyId = $centre->agency_id ?? null;
        $dates = \App\Support\Closures::dateLabel($row);
        $reason = \App\Support\Closures::reason($row);
        $centreName = $centre->name ?? 'Your centre';

        // Families at the centre, and the staff assigned to it. Both need to know; only
        // one of them was ever told.
        $familyIds = DB::table('families')->where('centre_id', $centreId)->whereNull('deleted_at')->pluck('id');
        $guardianIds = DB::table('guardians')->whereIn('family_id', $familyIds)->pluck('user_id');
        $staffIds = DB::table('role_assignments')->where('centre_id', $centreId)->where('active', true)->pluck('user_id');
        $userIds = $guardianIds->merge($staffIds)->map(fn ($i) => (int) $i)->unique()->values();

        $recipients = DB::table('users')->whereIn('id', $userIds)->whereNull('deleted_at')
            ->select('id', 'email', 'first_name', 'last_name')->get();

        // One blind copy of this announcement for the people accountable for it.
        $__bcc = \App\Support\MailOversight::bccFor($agencyId, $centreId, $recipients->pluck('email')->all());
        $__bccIndex = 0;

        /* Where TODAY sits relative to this closure, in the CENTRE's timezone.
           A closure can be entered before it starts, while it is running, or after it has
           finished, and the notice was written as though it were always the first:
           "will be closed". Mareena Matthew's closure of 11-26 Aug was entered on the
           18th, so her families were told a closure they were already living through
           "will" happen. A notice that describes the wrong point in time reads as a
           mistake and costs the next one its credibility. (2026-08-25) */
        $__tz = \App\Support\AgencyTime::tzForCentre($centreId);
        $__today = \Illuminate\Support\Carbon::today($__tz);
        $__start = \Illuminate\Support\Carbon::parse(substr((string) $row->closure_date, 0, 10), $__tz)->startOfDay();
        $__end = $row->end_date
            ? \Illuminate\Support\Carbon::parse(substr((string) $row->end_date, 0, 10), $__tz)->startOfDay()
            : $__start->copy();
        $__phase = $__today->lt($__start) ? 'upcoming' : ($__today->gt($__end) ? 'past' : 'current');

        foreach ($recipients as $u) {
            \App\Support\Notify::write([
                'user_id' => $u->id, 'type' => 'closure',
                'title' => $centreName . ' closed: ' . $dates,
                'body' => $reason,
                'data' => json_encode(['link' => '#closures', 'closure_id' => $closureId]),
                'created_at' => now(),
            ]);

            if (! filter_var((string) $u->email, FILTER_VALIDATE_EMAIL)
                || \App\Support\Suppression::isUser((int) $u->id)) {
                continue;
            }

            // One recipient's bad address must not abort the announcement for everybody
            // after them in the loop.
            try {
                $this->mailClosure($agencyId, $u, $centreName, $dates, $reason,
                    \App\Support\MailOversight::firstOnly($__bcc, $__bccIndex++), $__phase);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Closure email failed', [
                    'user' => $u->id, 'closure' => $closureId, 'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function mailClosure(?int $agencyId, object $u, string $centreName, string $dates,
                                string $reason, array $bcc = [], string $phase = 'upcoming'): void
    {
        /* Composed by TemplatedEmails::closureNotice — the same builder Settings → Email
           templates previews and tests, so an agency can reword it there (2026-10-06).
           The three tenses (upcoming / current / past) live there too. */
        $mail = \App\Support\TemplatedEmails::closureNotice($agencyId, $centreName, $dates, $reason, $phase);
        $html = $mail['html'];
        $subject = $mail['subject'];
        $name = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? ''));
        \App\Services\AgencyMailer::forAgency($agencyId)->html($html, function ($m) use ($u, $name, $subject, $bcc) {
            $m->to($u->email, $name ?: null)->subject($subject);
            // The director and agency admin see what their families were told, as it is
            // sent. The reminder path has always done this; the announcement never did.
            if ($bcc) {
                $m->bcc($bcc);
            }
        });
    }

    /**
     * Correct a closure in place. Without this a wrong date could only be fixed by
     * deleting and re-adding, which announces the same closure to every family twice.
     */
    public function updateClosure(Request $request, int $id): JsonResponse
    {
        $row = DB::table('centre_closures')->where('id', $id)->first();
        abort_unless($row, 404);
        $this->assertCentreAccess($request, (int) $row->centre_id);

        $data = $request->validate([
            'closure_date' => 'sometimes|required|date',
            'end_date' => 'nullable|date|after_or_equal:closure_date',
            'closure_type' => 'sometimes|required|in:holiday,pd_day,emergency,renovation,other',
            'reason' => 'nullable|string|max:200',
            'affects_billing' => 'nullable|boolean',
        ]);
        if (! $data) {
            return response()->json(['status' => 'unchanged']);
        }

        $data['updated_at'] = now();
        DB::table('centre_closures')->where('id', $id)->update($data);

        // Announced as a change, not as a new closure: telling somebody a centre is
        // closing when they already knew, and the only news is the date moved, is how
        // people learn to ignore the notice.
        $this->announceClosureChange((int) $id, (int) $row->centre_id, 'updated');

        return response()->json(['status' => 'updated']);
    }

    public function removeClosure(Request $request, int $id): JsonResponse
    {
        $row = DB::table('centre_closures')->where('id', $id)->first();
        abort_unless($row, 404);
        $this->assertCentreAccess($request, (int) $row->centre_id);

        // Announce BEFORE deleting — the row is the only source of what it said.
        $this->announceClosureChange($id, (int) $row->centre_id, 'removed');

        DB::table('centre_closures')->where('id', $id)->delete();
        return response()->json(['status' => 'removed']);
    }

    /**
     * Tell the people accountable for a centre that a closure was changed or withdrawn.
     *
     * announceClosure() emails families and centre staff when one is created. Neither an
     * edit nor a deletion said anything to anybody, and agency admins and directors were
     * never told at all unless they happened to hold a role AT that centre — which most
     * do not. This is the in-app feed only: a correction does not warrant another email
     * to every family, but the people running the agency do need to see it happened.
     */
    private function announceClosureChange(int $closureId, int $centreId, string $verb): void
    {
        $row = DB::table('centre_closures')->where('id', $closureId)->first();
        if (! $row) {
            return;
        }
        $centre = DB::table('centres')->where('id', $centreId)->first(['name', 'agency_id']);
        if (! $centre) {
            return;
        }

        $actor = DB::table('users')->where('id', request()->user()->id ?? 0)->first(['first_name', 'last_name']);
        $actorName = $actor ? trim(($actor->first_name ?? '') . ' ' . ($actor->last_name ?? '')) : '';
        $dates = \App\Support\Closures::dateLabel($row);
        $reason = \App\Support\Closures::reason($row);

        foreach ($this->closureWatchers((int) $centre->agency_id, $centreId) as $uid) {
            \App\Support\Notify::write([
                'user_id' => $uid,
                'type' => 'closure',
                'title' => ($verb === 'removed' ? 'Closure removed: '
                        : ($verb === 'added' ? 'Closure added: ' : 'Closure updated: '))
                    . ($centre->name ?? 'Centre') . ' — ' . $dates,
                'body' => trim(($reason ? $reason . '. ' : '') . ($actorName !== '' ? 'By ' . $actorName . '.' : '')),
                'data' => json_encode(['link' => '#closures', 'closure_id' => $closureId, 'action' => $verb]),
                'created_at' => now(),
            ]);
        }
    }

    /**
     * Who should hear about a closure changing: the agency's admins and its directors,
     * plus anyone holding a role at the centre itself. Scoped to the agency that owns the
     * centre — a director of another agency has no business seeing this.
     */
    private function closureWatchers(int $agencyId, int $centreId): array
    {
        $agencyWide = DB::table('role_assignments')
            ->where('active', true)->where('agency_id', $agencyId)
            ->whereIn('role', ['agency_admin', 'centre_director'])
            ->pluck('user_id');

        $atCentre = DB::table('role_assignments')
            ->where('active', true)->where('centre_id', $centreId)
            ->whereIn('role', ['agency_admin', 'centre_director'])
            ->pluck('user_id');

        return $agencyWide->merge($atCentre)
            ->map(fn ($i) => (int) $i)->unique()->values()->all();
    }

    // =========================================================
    // Late pickup fee — director logs the pickup
    // =========================================================
    /** Centres + children for the late-pickup log picker, scoped to the caller's agency. */
    public function latePickupOptions(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        if (! $agencyId) return response()->json(['centres' => [], 'children' => []]);
        /* Open centres and enrolled children only (2026-10-09: the picker offered the two
           closed providers and every withdrawn child — App\Support\Live is the one rule). */
        $centres = \App\Support\Live::centres(DB::table('centres'))->where('agency_id', $agencyId)->orderBy('name')->get(['id', 'name']);
        $children = \App\Support\Live::enrolledChildren(DB::table('children as c'), 'c')
            ->join('families as f', 'f.id', '=', 'c.family_id')
            ->whereIn('f.centre_id', $centres->pluck('id'))
            ->orderBy('c.first_name')->get(['c.id', 'c.first_name', 'c.last_name', 'f.centre_id']);
        return response()->json(['centres' => $centres, 'children' => $children]);
    }

    /**
     * POST /operations/late-pickup — the OLD one-step "log and charge", kept for any client
     * that still calls it. Since 2026-10-06 it goes through the one late-pickup flow
     * (App\Support\LatePickups): a late_events record is written and approved at once with
     * the per-minute fee, which writes the invoice line, the late_pickup_charges row and the
     * parents' notice, and sends ONE "logged and charged" email to the directors/admins.
     * Within the grace period it still answers within_grace and records nothing.
     */
    public function logLatePickup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'centre_id' => 'required|integer',
            'child_id' => 'required|integer',
            'pickup_at' => 'required|date',
            'close_time' => 'required',
            'notes' => 'nullable|string|max:500',
        ]);
        $this->assertCentreAccess($request, (int) $data['centre_id']);
        $centre = DB::table('centres')->where('id', $data['centre_id'])->first();
        abort_unless($centre && \App\Support\Live::centreOpen((int) $centre->id), 422, 'That centre is closed.');
        $child = \App\Support\Live::enrolledChildren(DB::table('children as c'), 'c')->join('families as f', 'f.id', '=', 'c.family_id')
            ->where('c.id', $data['child_id'])->where('f.centre_id', $centre->id)->first(['c.id']);
        abort_unless($child, 404, 'Child not found at that centre (or no longer enrolled)');
        $policy = \App\Support\LatePickups::policy((int) $centre->agency_id);

        $pickup = Carbon::parse($data['pickup_at']);
        $close = Carbon::parse($pickup->toDateString() . ' ' . $data['close_time']);
        $minutesLate = max(0, (int) $close->diffInMinutes($pickup, false));
        if ($minutesLate <= $policy['grace']) {
            return response()->json(['status' => 'within_grace', 'minutes_late' => $minutesLate]);
        }
        $chargeable = $minutesLate - $policy['grace'];
        $fee = \App\Support\LatePickups::suggest((int) $centre->agency_id, $minutesLate);

        $id = DB::table('late_events')->insertGetId([
            'child_id' => $child->id, 'centre_id' => $centre->id, 'kind' => 'departure',
            'occurred_on' => $pickup->toDateString(), 'pickup_at' => $pickup->format('Y-m-d H:i:s'),
            'close_time' => $data['close_time'], 'minutes' => $minutesLate, 'suggested_fee' => $fee,
            'note' => $data['notes'] ?? null, 'recorded_by_id' => $request->user()->id,
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $ev = DB::table('late_events')->where('id', $id)->first();
        $res = DB::transaction(fn () => \App\Support\LatePickups::charge($ev, $fee, (int) $request->user()->id));
        (new \App\Support\LatePickups())->emailCharged($ev, $fee, $res['invoice'], $request->user());

        return response()->json(['id' => $res['charge_id'], 'late_event_id' => $id, 'fee' => $fee, 'minutes_late' => $minutesLate, 'chargeable' => $chargeable], 201);
    }

    public function lateHistory(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $rows = DB::table('late_pickup_charges as lpc')
            ->join('children as c', 'c.id', '=', 'lpc.child_id')
            ->leftJoin('users as lb', 'lb.id', '=', 'lpc.logged_by_id')
            ->where('lpc.agency_id', $agencyId)
            ->orderByDesc('lpc.pickup_at')
            ->select('lpc.*',
                DB::raw("CONCAT(c.first_name,' ',c.last_name) as child_name"),
                DB::raw("TRIM(CONCAT(COALESCE(lb.first_name,''),' ',COALESCE(lb.last_name,''))) as logged_by"))
            ->limit(200)
            ->get();
        return response()->json(['data' => $rows]);
    }

    // =========================================================
    // Real-time room ratio compliance
    // =========================================================
    public function ratioStatus(Request $request): JsonResponse
    {
        /* LIVE (2026-10-13). This screen used to count ENROLLED children against every active
           staff role at the centre, agency-wide for any director, with its own copy of the
           Ontario table (preschool max 16 — the regulation says 24). It now asks the ratio
           engine (App\Support\RatioRules — Ontario licensed centres via OntarioRatios) about
           the children signed in and the staff on the floor right now, for the centres this
           person can see. */
        $agencyId = $this->resolveAgencyId($request);
        $centreIds = \App\Support\Visibility::centreIds((int) $agencyId, $request->user());
        $code = \App\Support\Jurisdictions::codeFor((int) $agencyId);

        $centres = DB::table('centres as c')->where('c.agency_id', $agencyId)->whereIn('c.id', $centreIds ?: [0])
            ->where(fn ($q) => \App\Support\Live::centres($q, 'c'))->orderBy('c.name')
            ->get(['c.id', 'c.name', 'c.license_type', 'c.settings', 'c.open_time', 'c.close_time']);
        $rooms = DB::table('rooms')->whereIn('centre_id', $centres->pluck('id')->all() ?: [0])->where('active', true)
            ->orderBy('centre_id')->orderBy('name')->get();
        $byId = $centres->keyBy('id');

        $rows = $rooms->map(function ($room) use ($byId) {
            $v = \App\Support\RatioRules::evaluate($room);
            $have = (float) $v['educators_present'];
            $need = (int) $v['required_educators'];
            $kids = (int) $v['children_present'];
            $overGroup = ! empty($v['over_group']) || ! empty($v['home']['over_cap']);
            $issues = (array) ($v['compliance_issues'] ?? []);
            $status = match (true) {
                $have < $need => 'breach',
                $overGroup => 'over_group',
                (bool) $issues => 'attention',
                $kids > 0 && $have - $need < 1 => 'tight',
                default => 'ok',
            };
            $on = $v['ontario'] ?? null;

            return [
                'room_id' => $room->id,
                'room_name' => $room->name,
                'centre_id' => (int) $room->centre_id,
                'centre_name' => $byId[$room->centre_id]->name ?? '',
                'licensed_age_group' => $room->licensed_age_group ?? null,
                'age_group' => $room->age_group,
                'standard' => [
                    'label' => $on['group_label'] ?? ucfirst(str_replace('_', ' ', (string) $room->age_group)),
                    'max_group' => $v['group_limit'] ?? ((int) $room->capacity ?: null),
                    'ratio' => $v['ratio_target'],
                ],
                'children_present' => $kids,
                'staff_present' => $have,
                'required_staff' => $need,
                'compliant' => $have >= $need && ! $overGroup && ! $issues,
                'status' => $status,
                'over_max_group' => $overGroup,
                'rule' => $v['rule'],
                'reason' => $v['reason'],
                'warnings' => $v['warnings'],
                'compliance_issues' => $issues,
                'ontario' => $on,
                'educator_source' => $v['educator_source'],
            ];
        })->values();

        $centreOut = $centres->map(fn ($c) => [
            'id' => (int) $c->id, 'name' => $c->name, 'license_type' => $c->license_type,
            'ontario_rules' => $code === 'CA-ON' && $c->license_type === 'on_centre',
            'settings' => $code === 'CA-ON' ? \App\Support\OntarioRatios::settings($c) : null,
        ])->values();

        return response()->json([
            'data' => $rows,
            'centres' => $centreOut,
            'jurisdiction' => $code,
            'groups' => $code === 'CA-ON' ? \App\Support\OntarioRatios::GROUPS : null,
            'as_of' => now()->toIso8601String(),
        ]);
    }

    /**
     * PUT /operations/ratio-settings/{centre} — Ontario only (2026-10-13). The location's licence
     * type (Ontario centre rules apply to 'on_centre' only), its program hours and the reduced-
     * ratio windows it uses (s.8(4)), outdoor play times (never reduced), and each room's
     * licensed age group (Schedule 1, or a family age group, s.8.1).
     */
    public function saveRatioSettings(Request $request, int $centre): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        abort_unless(\App\Support\Jurisdictions::codeFor((int) $agencyId) === 'CA-ON', 422, 'Ontario ratio settings apply to Ontario agencies only.');
        $row = DB::table('centres')->where('id', $centre)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        abort_unless($row, 404);
        abort_unless(in_array($centre, \App\Support\Visibility::centreIds((int) $agencyId, $request->user()), true), 403);

        $hm = 'regex:/^([01]\d|2[0-3]):[0-5]\d$/';
        $d = $request->validate([
            'license_type' => ['nullable', 'in:on_centre,on_hcc_agency,on_hcc_home'],
            'reduced_enabled' => ['required', 'boolean'],
            'program_start' => ['required', $hm],
            'program_end' => ['required', $hm],
            'arrival_minutes' => ['required', 'integer', 'min:0', 'max:90'],
            'departure_minutes' => ['required', 'integer', 'min:0', 'max:60'],
            'rest_start' => ['nullable', $hm],
            'rest_minutes' => ['nullable', 'integer', 'min:0', 'max:120'],
            'outdoor' => ['nullable', 'array', 'max:8'],
            'outdoor.*.from' => ['required', $hm],
            'outdoor.*.to' => ['required', $hm],
            'rooms' => ['nullable', 'array', 'max:60'],
            'rooms.*.id' => ['required', 'integer'],
            'rooms.*.licensed_age_group' => ['nullable', 'in:' . implode(',', array_keys(\App\Support\OntarioRatios::GROUPS))],
        ]);
        $toMin = fn ($v) => (int) substr($v, 0, 2) * 60 + (int) substr($v, 3, 2);
        if ($toMin($d['program_end']) <= $toMin($d['program_start'])) abort(422, 'The program must end after it starts.');
        foreach ((array) ($d['outdoor'] ?? []) as $w) {
            if ($toMin($w['to']) <= $toMin($w['from'])) abort(422, 'Each outdoor play time must end after it starts.');
        }
        // Programs under 6 hours: 30-minute arrival and departure periods at most (s.8(4)3).
        $long = $toMin($d['program_end']) - $toMin($d['program_start']) >= 360;
        $arr = min((int) $d['arrival_minutes'], $long ? 90 : 30);
        $dep = min((int) $d['departure_minutes'], $long ? 60 : 30);

        $settings = $row->settings ? (json_decode((string) $row->settings, true) ?: []) : [];
        $settings['ratio_on'] = [
            'reduced_enabled' => (bool) $d['reduced_enabled'],
            'program_start' => $d['program_start'], 'program_end' => $d['program_end'],
            'arrival_minutes' => $arr, 'departure_minutes' => $dep,
            'rest_start' => $d['rest_start'] ?? null, 'rest_minutes' => (int) ($d['rest_minutes'] ?? 0),
            'outdoor' => array_values(array_map(fn ($w) => ['from' => $w['from'], 'to' => $w['to']], (array) ($d['outdoor'] ?? []))),
        ];

        DB::transaction(function () use ($centre, $d, $settings) {
            DB::table('centres')->where('id', $centre)->update([
                'settings' => json_encode($settings), 'license_type' => $d['license_type'] ?? null, 'updated_at' => now(),
            ]);
            if (\Illuminate\Support\Facades\Schema::hasColumn('rooms', 'licensed_age_group')) {
                foreach ((array) ($d['rooms'] ?? []) as $r) {
                    DB::table('rooms')->where('id', (int) $r['id'])->where('centre_id', $centre)
                        ->update(['licensed_age_group' => $r['licensed_age_group'] ?? null, 'updated_at' => now()]);
                }
            }
        });

        try {
            \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => (int) $agencyId, 'action' => 'ratio_settings.updated',
                'entity_type' => 'centre', 'entity_id' => $centre,
                'payload' => json_encode(['summary' => 'Ontario ratio settings for ' . $row->name, 'license_type' => $d['license_type'] ?? null,
                    'ratio_on' => $settings['ratio_on'], 'rooms' => $d['rooms'] ?? []]),
                'ip_address' => substr((string) $request->ip(), 0, 45), 'created_at' => now()]);
        } catch (\Throwable $e) {}

        $fresh = DB::table('centres')->where('id', $centre)->first();

        return response()->json(['ok' => true, 'settings' => \App\Support\OntarioRatios::settings($fresh), 'license_type' => $fresh->license_type]);
    }

    // =========================================================
    // Bus routes + transportation
    // =========================================================
    public function busRoutes(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $rows = DB::table('bus_routes as br')
            ->leftJoin('users as u', 'u.id', '=', 'br.driver_id')
            ->where('br.agency_id', $agencyId)
            ->select('br.*', DB::raw("CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) as driver_name"))
            ->get();
        return response()->json(['data' => $rows]);
    }

    public function createBusRoute(Request $request): JsonResponse
    {
        $data = $request->validate([
            'centre_id' => 'required|integer',
            'route_name' => 'required|string|max:120',
            'driver_id' => 'nullable|integer',
            'vehicle_label' => 'nullable|string|max:80',
        ]);
        $this->assertCentreAccess($request, (int) $data['centre_id']);
        $centre = DB::table('centres')->where('id', $data['centre_id'])->first();
        $id = DB::table('bus_routes')->insertGetId([
            'agency_id' => $centre->agency_id,
            'centre_id' => $data['centre_id'],
            'route_name' => $data['route_name'],
            'driver_id' => $data['driver_id'] ?? null,
            'vehicle_label' => $data['vehicle_label'] ?? null,
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json(['id' => $id], 201);
    }

    public function routeChildren(Request $request, int $routeId): JsonResponse
    {
        // SECURITY (v22p96): scope the bus roster to the caller's active agency
        // (bus_routes.agency_id). Was readable by route id for any caller.
        $routeAgency = (int) DB::table('bus_routes')->where('id', $routeId)->value('agency_id');
        abort_unless($routeAgency, 404);
        abort_unless($routeAgency === (int) $this->resolveAgencyId($request), 403);
        $rows = DB::table('bus_route_children as brc')
            ->join('children as c', 'c.id', '=', 'brc.child_id')
            ->where('brc.bus_route_id', $routeId)
            ->orderBy('brc.stop_order')
            ->select('brc.*',
                DB::raw("CONCAT(c.first_name,' ',c.last_name) as child_name"))
            ->get();
        return response()->json(['data' => $rows]);
    }

    public function addChildToRoute(Request $request, int $routeId): JsonResponse
    {
        $data = $request->validate([
            'child_id' => 'required|integer',
            'pickup_address' => 'nullable|string|max:255',
            'pickup_time' => 'nullable',
            'dropoff_time' => 'nullable',
            'stop_order' => 'nullable|integer',
        ]);
        DB::table('bus_route_children')->updateOrInsert(
            ['bus_route_id' => $routeId, 'child_id' => $data['child_id']],
            [
                'pickup_address' => $data['pickup_address'] ?? null,
                'pickup_time' => $data['pickup_time'] ?? null,
                'dropoff_time' => $data['dropoff_time'] ?? null,
                'stop_order' => $data['stop_order'] ?? 0,
                'active' => 1,
            ]
        );
        return response()->json(['status' => 'added']);
    }

    // =========================================================
    // Auto room rotation — children aging into the next band
    // =========================================================
    public function rotationCandidates(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        // Children turning 18/30/72/156 months in the next 60 days
        $thresholds = [18, 30, 72, 156];
        $now = Carbon::now();
        $rows = DB::table('children as ch')
            ->join('families as f', 'f.id', '=', 'ch.family_id')
            ->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('c.agency_id', $agencyId)
            ->where(fn ($q) => \App\Support\Live::centres($q, 'c'))
            ->where('ch.enrollment_status', 'enrolled')
            ->whereNull('ch.deleted_at')
            ->select('ch.id', 'ch.first_name', 'ch.last_name', 'ch.date_of_birth',
                'ch.primary_room_id', 'c.id as centre_id', 'c.name as centre_name')
            ->get();
        $candidates = [];
        foreach ($rows as $ch) {
            $dob = Carbon::parse($ch->date_of_birth);
            $ageMonths = $dob->diffInMonths($now);
            foreach ($thresholds as $t) {
                $reachAt = $dob->copy()->addMonths($t);
                $daysOut = (int) $now->diffInDays($reachAt, false);
                if ($daysOut >= 0 && $daysOut <= 60) {
                    $candidates[] = [
                        'child_id' => $ch->id,
                        'child_name' => $ch->first_name . ' ' . $ch->last_name,
                        'current_age_months' => $ageMonths,
                        'threshold' => $t,
                        'reach_date' => $reachAt->toDateString(),
                        'days_until' => $daysOut,
                        'centre_id' => $ch->centre_id,
                        'centre_name' => $ch->centre_name,
                    ];
                    break;
                }
            }
        }
        usort($candidates, fn ($a, $b) => $a['days_until'] - $b['days_until']);
        return response()->json(['data' => $candidates]);
    }

    public function planRotation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_id' => 'required|integer',
            'to_room_id' => 'required|integer',
            'rotation_date' => 'required|date',
            'reason' => 'nullable|string|max:120',
        ]);
        $child = DB::table('children')->where('id', $data['child_id'])->first();
        abort_unless($child, 404);
        $agencyId = (int) DB::table('families')->where('id', $child->family_id)
            ->join('centres', 'centres.id', '=', 'families.centre_id')
            ->value('centres.agency_id');
        $id = DB::table('room_rotations')->insertGetId([
            'agency_id' => $agencyId,
            'child_id' => $data['child_id'],
            'from_room_id' => $child->primary_room_id,
            'to_room_id' => $data['to_room_id'],
            'rotation_date' => $data['rotation_date'],
            'reason' => $data['reason'] ?? 'age_progression',
            'status' => 'planned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // notify family
        $gids = DB::table('guardians')->where('family_id', $child->family_id)->pluck('user_id');
        foreach ($gids as $gid) {
            \App\Support\Notify::write([
                'user_id' => $gid, 'type' => 'room_rotation',
                'title' => "{$child->first_name} moving rooms on " . Carbon::parse($data['rotation_date'])->format('M j'),
                'body' => "Your child is being moved to the next age room.",
                'data' => json_encode(['link' => '#room-rotations', 'rotation_id' => $id]),
                'created_at' => now(),
            ]);
        }
        return response()->json(['id' => $id], 201);
    }

    // helpers
    private function resolveAgencyId(Request $request): int
    {
        $activeId = (int) $request->header('X-Active-Agency-Id');
        // SECURITY (v22p94): only honour the header if the user is platform_admin
        // or holds an active role for that exact agency (else fall back below).
        if ($activeId && DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)->where(function ($w) use ($activeId) { $w->where('agency_id', $activeId)->orWhere('role', 'platform_admin'); })->exists()) return $activeId;
        // SECURITY (v22p98): a platform_admin with no valid SELECTED agency must NOT
        // fall through to their first role's agency (iLearn) — require an explicit
        // choice, else agency-scoped data leaked to a super-admin on a header-less call.
        if (DB::table('role_assignments')->where('user_id', $request->user()->id)->where('role', 'platform_admin')->where('active', true)->exists()) abort(400, 'Select an agency first.');
        $first = DB::table('role_assignments')
            ->where('user_id', $request->user()->id)
            ->where('active', true)
            ->value('agency_id');
        abort_unless($first, 400);
        return (int) $first;
    }

    /**
     * GET /operations/closure-targets — who a holiday can be applied to.
     *
     * Returns the agency's centres and its staff, each staff member carrying the centres
     * they belong to, so the picker can list PEOPLE (which is how an administrator thinks
     * about a provider holiday) while the closure is still written per CENTRE.
     *
     * A staff member belongs to a centre by their role row, or by an explicit room
     * assignment — the same two facts used everywhere else to decide where somebody works.
     */
    public function closureTargets(Request $request): JsonResponse
    {
        $agencyId = (int) $request->header('X-Active-Agency-Id');
        if (! $agencyId) {
            $agencyId = (int) DB::table('role_assignments')->where('user_id', $request->user()->id)
                ->where('active', true)->whereNotNull('agency_id')->value('agency_id');
        }
        abort_unless($agencyId, 403);

        $centres = \App\Support\Live::centres(DB::table('centres'))->where('agency_id', $agencyId)
            ->orderBy('name')->get(['id', 'name']);
        $centreIds = $centres->pluck('id')->all();
        if (! $centreIds) {
            return response()->json(['centres' => [], 'staff' => []]);
        }

        $roles = ['educator', 'home_visitor', 'centre_director', 'agency_admin'];
        $rows = DB::table('role_assignments as ra')
            ->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.active', true)
            ->whereIn('ra.role', $roles)
            ->where('u.status', 'active')
            ->whereNull('u.deleted_at')
            ->where(function ($w) use ($agencyId, $centreIds) {
                $w->where('ra.agency_id', $agencyId)->orWhereIn('ra.centre_id', $centreIds);
            })
            ->orderBy('u.first_name')->orderBy('u.last_name')
            ->get(['ra.user_id', 'ra.role', 'ra.centre_id', 'u.first_name', 'u.last_name']);

        // Centres reached by an explicit room assignment, for the agency-attached roles
        // whose own row carries centre_id NULL.
        $viaRooms = DB::table('educator_rooms as er')
            ->join('rooms as r', 'r.id', '=', 'er.room_id')
            ->whereIn('r.centre_id', $centreIds)
            ->get(['er.user_id', 'r.centre_id'])
            ->groupBy('user_id');

        $staff = [];
        foreach ($rows as $r) {
            $uid = (int) $r->user_id;
            if (! isset($staff[$uid])) {
                $staff[$uid] = [
                    'user_id' => $uid,
                    'name' => trim($r->first_name.' '.$r->last_name),
                    'roles' => [],
                    'centre_ids' => [],
                ];
            }
            if (! in_array($r->role, $staff[$uid]['roles'], true)) {
                $staff[$uid]['roles'][] = $r->role;
            }
            if ($r->centre_id && in_array((int) $r->centre_id, $centreIds, true)) {
                $staff[$uid]['centre_ids'][] = (int) $r->centre_id;
            }
            foreach (($viaRooms[$uid] ?? collect()) as $rm) {
                $staff[$uid]['centre_ids'][] = (int) $rm->centre_id;
            }
        }
        foreach ($staff as $uid => $row) {
            $staff[$uid]['centre_ids'] = array_values(array_unique($row['centre_ids']));
        }

        return response()->json([
            'centres' => $centres,
            'staff' => array_values($staff),
        ]);
    }

    /**
     * POST /operations/closures/bulk — one holiday across several centres.
     *
     * Writes exactly what addClosure() writes, once per DISTINCT centre, and runs the same
     * announcement path — a holiday is a closure, so the existing routine already covers
     * schedule bands, autofill, educator hours and closures:remind.
     *
     * A centre whose date already carries a closure is SKIPPED, not doubled — the same
     * rule holidays:sync follows, which is what makes this safe to run twice and what
     * stops a generated stat holiday being overwritten by a hand-made one.
     */
    public function addClosureBulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'centre_ids' => 'required|array|min:1',
            'centre_ids.*' => 'integer',
            'closure_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:closure_date',
            'closure_type' => 'required|in:holiday,pd_day,emergency,renovation,other',
            'reason' => 'nullable|string|max:200',
            'affects_billing' => 'nullable|boolean',
            'notify' => 'nullable|boolean',
        ]);

        $centreIds = array_values(array_unique(array_map('intval', $data['centre_ids'])));

        // EVERY centre is checked before ANYTHING is written, so a partial write cannot
        // leave some centres closed after a refusal half way down the list.
        foreach ($centreIds as $cid) {
            $this->assertCentreAccess($request, $cid);
        }

        $start = $data['closure_date'];
        $end = $data['end_date'] ?? $data['closure_date'];

        $created = [];
        $skipped = [];

        foreach ($centreIds as $cid) {
            /* Already closed on any of these days? Leave it alone. Overlap, not equality:
               an existing multi-day closure covering this date counts. */
            $clash = DB::table('centre_closures')
                ->where('centre_id', $cid)
                ->where('closure_date', '<=', $end)
                ->where(function ($q) use ($start) {
                    $q->where('end_date', '>=', $start)
                        ->orWhere(function ($q2) use ($start) {
                            $q2->whereNull('end_date')->where('closure_date', '>=', $start);
                        });
                })
                ->first(['id', 'closure_type']);

            if ($clash) {
                $skipped[] = [
                    'centre_id' => $cid,
                    'centre_name' => DB::table('centres')->where('id', $cid)->value('name'),
                    'reason' => 'Already closed on that date',
                ];
                continue;
            }

            $id = DB::table('centre_closures')->insertGetId([
                'centre_id' => $cid,
                'closure_date' => $start,
                'end_date' => $data['end_date'] ?? null,
                'closure_type' => $data['closure_type'],
                'reason' => $data['reason'] ?? null,
                'affects_billing' => $data['affects_billing'] ?? true,
                'created_by_id' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $created[] = [
                'id' => (int) $id,
                'centre_id' => $cid,
                'centre_name' => DB::table('centres')->where('id', $cid)->value('name'),
            ];
        }

        /* The SAME notification decision addClosure() makes, asked once for the agency
           rather than per centre — telling everyone the moment a closure is entered is a
           choice, and an agency drafting a year of dates does not want each keystroke
           mailed out. `notify:false` from the caller suppresses it regardless. */
        if ($created) {
            $agencyId = (int) DB::table('centres')->where('id', $created[0]['centre_id'])->value('agency_id');
            $aSettings = json_decode((string) DB::table('agencies')->where('id', $agencyId)->value('settings'), true) ?: [];
            $remindersOn = ($aSettings['closure_reminders_enabled'] ?? true) !== false;
            $immediate = ($aSettings['closure_reminder_immediate'] ?? true) !== false;
            $wants = ($data['notify'] ?? true) !== false;

            foreach ($created as $row) {
                if ($wants && $remindersOn && $immediate) {
                    $this->announceClosure($row['id'], $row['centre_id']);
                }
                // Admins and directors who hold no role AT the centre are told either way.
                $this->announceClosureChange($row['id'], $row['centre_id'], 'added');
            }
        }

        return response()->json([
            'created' => $created,
            'skipped' => $skipped,
            'created_count' => count($created),
            'skipped_count' => count($skipped),
        ], 201);
    }

    private function assertCentreAccess(Request $request, int $centreId): void
    {
        $u = $request->user();
        $agencyId = (int) DB::table('centres')->where('id', $centreId)->value('agency_id');
        abort_unless($agencyId, 404);
        $isPlatform = DB::table('role_assignments')->where('user_id', $u->id)
            ->where('role', 'platform_admin')->where('active', true)->exists();
        if ($isPlatform) {
            // v22p98: scope to the agency they've switched into (was an unconditional
            // grant that let a super-admin reach any centre in any tenant).
            abort_unless($agencyId === (int) $request->header('X-Active-Agency-Id'), 403);
            return;
        }
        $hasRole = DB::table('role_assignments')->where('user_id', $u->id)
            ->where('agency_id', $agencyId)
            ->whereIn('role', ['agency_admin', 'centre_director', 'educator'])
            ->where('active', true)->exists();
        abort_unless($hasRole, 403);
    }
}
