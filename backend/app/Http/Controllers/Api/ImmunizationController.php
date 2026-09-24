<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Models\Immunization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ImmunizationController v22p1.
 *
 * Per-child immunization records. Ontario daycare licensing (CCEYA) requires up-to-date
 * records or a documented medical / religious exemption.
 *
 * Director endpoints:
 *   GET    /director/immunizations               list (?child_id=N or ?overdue=1)
 *   POST   /director/immunizations               add record
 *   PATCH  /director/immunizations/{id}          update
 *   DELETE /director/immunizations/{id}          remove
 *
 * Parent endpoints:
 *   GET    /parent/children/{child}/immunizations records for their child
 */
class ImmunizationController extends Controller
{
    use ResolvesCentreContext;

    public function index(Request $request): JsonResponse
    {
        $centreId = $this->resolveCentreId($request->user());
        if (! $centreId) {
            return response()->json(['immunizations' => []]);
        }

        // Scope to children enrolled at this centre. enrollments has room_id, not centre_id,
        // so go through rooms.
        $roomIds = DB::table('rooms')->where('centre_id', $centreId)->pluck('id');
        $centreChildIds = DB::table('enrollments')
            ->whereIn('room_id', $roomIds)
            ->pluck('child_id')
            ->unique();

        $q = Immunization::query()
            ->whereIn('child_id', $centreChildIds)
            ->orderByDesc('administered_on');

        if ($childId = (int) $request->query('child_id')) {
            $q->where('child_id', $childId);
        }
        if ($request->boolean('overdue')) {
            $q->whereNotNull('next_due_on')
              ->where('next_due_on', '<', now()->toDateString())
              ->where('exempt', false);
        }

        $rows = $q->limit(500)->get();
        $childNames = DB::table('children')
            ->whereIn('id', $rows->pluck('child_id')->unique())
            ->pluck(DB::raw("CONCAT(first_name, ' ', last_name)"), 'id');

        $out = $rows->map(function ($r) use ($childNames) {
            $arr = $r->toArray();
            $arr['child_name'] = $childNames[$r->child_id] ?? '?';
            return $arr;
        });

        return response()->json(['immunizations' => $out]);
    }

    public function store(Request $request): JsonResponse
    {
        $centreId = $this->resolveCentreId($request->user());
        if (! $centreId) {
            return response()->json(['message' => 'No centre access'], 403);
        }

        $data = $request->validate([
            'child_id'         => 'required|integer|exists:children,id',
            'vaccine'          => 'required|string|max:100',
            'dose_label'       => 'nullable|string|max:40',
            'administered_on'  => 'nullable|date',
            'lot_number'       => 'nullable|string|max:80',
            'site'             => 'nullable|string|max:80',
            'administered_by'  => 'nullable|string|max:160',
            'clinic_name'      => 'nullable|string|max:160',
            'next_due_on'      => 'nullable|date',
            'exempt'           => 'nullable|boolean',
            'exemption_reason' => 'nullable|string|max:200',
        ]);

        /* THE CHILD HAS TO BE ONE OF YOURS (2026-09-24).

           update() and destroy() both check this; store() never did. `exists:children,id`
           proves a child exists, not that they are in your centre, so any director could
           write a dose - or an exemption - onto any child in the platform by id. Noticed
           while adding the exemption endpoint below, which needed the same guard. */
        if (! $this->childIsInCentre((int) $data['child_id'], $centreId)) {
            return response()->json(['message' => 'Child not in your centre'], 403);
        }

        $data['recorded_by_id'] = $request->user()->id;
        $row = Immunization::create($data);

        return response()->json(['immunization' => $row], 201);
    }

    /** Is this child enrolled at that centre? enrollments carries room_id, so go via rooms. */
    private function childIsInCentre(int $childId, int $centreId): bool
    {
        return DB::table('enrollments')
            ->join('rooms', 'rooms.id', '=', 'enrollments.room_id')
            ->where('enrollments.child_id', $childId)
            ->where('rooms.centre_id', $centreId)
            ->exists();
    }

    /**
     * POST /director/children/{child}/immunization-exemption
     *
     * EXEMPT A DOSE THAT WAS NEVER GIVEN (2026-09-24).
     *
     * Anthony: "add an exempt option to the list of doses/vaccines to be chosen to
     * exempt from immunizations as some children/parents has religious exempt from
     * their province/country."
     *
     * Everything underneath this already worked: `immunizations.exempt` has existed
     * since v22p1, a matched record carrying it reads as `exempt` rather than `done`,
     * an exempt dose is never overdue, never chased in a parent reminder, and the
     * compliance report prints "Exempt" with its reason. What there was no way to do
     * was SET it against a dose on the schedule - the flag could only be attached to a
     * record somebody added by hand, which meant spelling the vaccine and dose exactly
     * as the schedule spells them and hoping they matched. Zero of the 37 rows on file
     * carried it.
     *
     * An exemption is a statement about a dose, so it is keyed by vaccine and dose the
     * same way the schedule matches one - case- and space-insensitively - and it is
     * IDEMPOTENT. Exempting twice updates one row rather than leaving two, because two
     * rows for one dose is how a "done" and an "exempt" end up disagreeing about the
     * same vaccine.
     *
     * Clearing one REMOVES the row when the row exists only to carry the exemption. A
     * row that also records an administered date is a real record and keeps it; only
     * the flag comes off. Otherwise "un-exempt" would leave an empty dose behind that
     * reads as neither given nor due.
     *
     * Directors and agency admins only (the route group): whether a child is exempt is
     * a licensing position, not a note an educator takes at the door. A guardian cannot
     * reach it at all.
     */
    public function setExemption(Request $request, int $childId): JsonResponse
    {
        $centreId = $this->resolveCentreId($request->user());
        if (! $centreId) {
            return response()->json(['message' => 'No centre access'], 403);
        }
        if (! $this->childIsInCentre($childId, $centreId)) {
            return response()->json(['message' => 'Child not in your centre'], 403);
        }

        $data = $request->validate([
            'vaccine' => 'required|string|max:100',
            'dose_label' => 'nullable|string|max:40',
            'exempt' => 'required|boolean',
            'exemption_reason' => 'nullable|string|max:200',
        ]);

        $vaccine = trim((string) $data['vaccine']);
        $dose = trim((string) ($data['dose_label'] ?? ''));
        $wantExempt = (bool) $data['exempt'];
        $reason = trim((string) ($data['exemption_reason'] ?? ''));

        /* A REASON IS NOT OPTIONAL WHEN EXEMPTING. The licensing requirement is a
           documented exemption; an undocumented one is just a missing dose wearing a
           better label, and it would silence the reminder with nothing on file to
           justify it. */
        if ($wantExempt && $reason === '') {
            return response()->json([
                'message' => 'Give the reason for the exemption - it is what makes it a record.',
                'errors' => ['exemption_reason' => ['A reason is required.']],
            ], 422);
        }

        /* The matching and the write both live in ImmunizationExemption, because the
           exemption-form path in ParentImmunizationRecordController does exactly this
           too and the two must not drift on what happens when a row already exists. */
        $E = \App\Support\ImmunizationExemption::class;

        if (! $wantExempt) {
            $outcome = $E::clear($childId, $vaccine, $dose !== '' ? $dose : null);
            if ($outcome === $E::NOTHING) {
                return response()->json(['ok' => true, 'message' => 'That dose was not exempt.']);
            }
            $action = 'immunization.exemption_removed';
            $result = null;
        } else {
            $outcome = $E::set($childId, $vaccine, $dose !== '' ? $dose : null,
                $reason, (int) $request->user()->id);

            /* A dose somebody already wrote a DATE against is not quietly turned into
               an exemption - that would destroy the more specific record - so say so
               rather than reporting a success that did not happen. */
            if ($outcome === $E::SKIPPED_GIVEN) {
                return response()->json([
                    'message' => 'That dose is already recorded as given, with a date. '
                        . 'Remove the recorded dose first if it was entered in error.',
                ], 422);
            }
            $action = 'immunization.exemption_set';
            $result = null;
        }

        /* A health record changed, so the log has to name WHAT - the child and the dose,
           not a count. This is compliance evidence and somebody will be asked who
           decided it and when. */
        try {
            $childName = DB::table('children')->where('id', $childId)
                ->selectRaw("TRIM(CONCAT(COALESCE(NULLIF(preferred_name,''),first_name),' ',last_name)) as n")
                ->value('n');
            \App\Support\Audit::write([
                'user_id' => $request->user()->id,
                'agency_id' => \App\Support\AuditScope::resolve((int) $request->user()->id, $request),
                'action' => $action,
                'entity_type' => 'child',
                'entity_id' => $childId,
                'payload' => json_encode([
                    'child' => $childName,
                    'vaccine' => $vaccine,
                    'dose_label' => $dose !== '' ? $dose : null,
                    'exemption_reason' => $wantExempt ? $reason : null,
                    'summary' => $wantExempt
                        ? ($childName . ' was recorded EXEMPT from ' . $vaccine
                            . ($dose !== '' ? ' (' . $dose . ')' : '') . ' - ' . $reason)
                        : ('The exemption for ' . $vaccine . ($dose !== '' ? ' (' . $dose . ')' : '')
                            . ' was removed for ' . $childName . '; the dose is due again.'),
                ]),
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Immunization exemption audit failed', ['e' => $e->getMessage()]);
        }

        return response()->json([
            'ok' => true,
            'immunization' => $result,
            'message' => $wantExempt ? 'Exemption recorded.' : 'Exemption removed.',
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $centreId = $this->resolveCentreId($request->user());
        if (! $centreId) {
            return response()->json(['message' => 'No centre access'], 403);
        }
        $row = Immunization::find($id);
        if (! $row) {
            return response()->json(['message' => 'Not found'], 404);
        }
        // Soft scope check: the child must be enrolled at the user's centre.
        // enrollments has room_id, not centre_id — join through rooms.
        $childEnrolled = DB::table('enrollments')
            ->join('rooms', 'rooms.id', '=', 'enrollments.room_id')
            ->where('enrollments.child_id', $row->child_id)
            ->where('rooms.centre_id', $centreId)
            ->exists();
        if (! $childEnrolled) {
            return response()->json(['message' => 'Child not in your centre'], 403);
        }

        $data = $request->validate([
            'vaccine'          => 'sometimes|string|max:100',
            'dose_label'       => 'nullable|string|max:40',
            'administered_on'  => 'nullable|date',
            'lot_number'       => 'nullable|string|max:80',
            'site'             => 'nullable|string|max:80',
            'administered_by'  => 'nullable|string|max:160',
            'clinic_name'      => 'nullable|string|max:160',
            'next_due_on'      => 'nullable|date',
            'exempt'           => 'nullable|boolean',
            'exemption_reason' => 'nullable|string|max:200',
        ]);
        $row->update($data);
        return response()->json(['immunization' => $row->fresh()]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $centreId = $this->resolveCentreId($request->user());
        $row = Immunization::find($id);
        if (! $row) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $childEnrolled = DB::table('enrollments')
            ->join('rooms', 'rooms.id', '=', 'enrollments.room_id')
            ->where('enrollments.child_id', $row->child_id)
            ->where('rooms.centre_id', $centreId)
            ->exists();
        if (! $childEnrolled) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $row->delete();
        return response()->json(['ok' => true]);
    }

    public function parentList(Request $request, int $childId): JsonResponse
    {
        $user = $request->user();
        $isGuardian = DB::table('guardians')
            ->join('children', 'children.family_id', '=', 'guardians.family_id')
            ->where('guardians.user_id', $user->id)
            ->where('children.id', $childId)
            ->exists();
        if (! $isGuardian) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $rows = Immunization::where('child_id', $childId)->orderByDesc('administered_on')->get();
        return response()->json(['immunizations' => $rows]);
    }
}
