<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v22p59 — Parent-teacher conferences. Teachers/directors publish
 * available slots; parents self-book one slot per child.
 */
final class ConferenceController extends Controller
{
    public function listOpenSlots(Request $request): JsonResponse
    {
        $u = $request->user();
        $familyId = DB::table('guardians')->where('user_id', $u->id)->value('family_id');
        if ($familyId && ! $this->isStaffUser($request)) {
            $family = DB::table('families')->where('id', $familyId)->first();
            /* A parent sees the open slots at their centre AND their own family's bookings (2026-11-27): a booked
               slot used to vanish from the parent's list, so they could neither see nor cancel it. Another
               family's booking is never shown. */
            $kids = DB::table('children')->where('family_id', $familyId)->pluck('id')->all();
            $rows = DB::table('conference_slots as cs')
                ->leftJoin('users as u', 'u.id', '=', 'cs.teacher_user_id')
                ->leftJoin('children as c', 'c.id', '=', 'cs.booked_child_id')
                ->where('cs.centre_id', $family->centre_id)
                ->where('cs.slot_at', '>=', now())
                ->where(fn ($q) => $q->where('cs.status', 'open')
                    ->orWhere(fn ($w) => $w->where('cs.status', 'booked')->whereIn('cs.booked_child_id', $kids ?: [0])))
                ->orderBy('cs.slot_at')
                ->select('cs.id', 'cs.centre_id', 'cs.slot_at', 'cs.duration_minutes', 'cs.location', 'cs.status',
                    DB::raw("CONCAT(u.first_name,' ',u.last_name) as teacher_name"),
                    DB::raw("IF(cs.status = 'booked', c.first_name, NULL) as booked_child_name"),
                    DB::raw("IF(cs.status = 'booked', 1, 0) as mine"))
                ->get();
            $children = DB::table('children')->where('family_id', $familyId)->whereNull('deleted_at')
                ->orderBy('first_name')->get(['id', 'first_name']);
            return response()->json(['data' => $rows, 'children' => $children]);
        }
        // Staff view: all upcoming slots in agency
        $agencyId = $this->resolveAgencyId($request);
        $rows = DB::table('conference_slots as cs')
            ->leftJoin('users as u', 'u.id', '=', 'cs.teacher_user_id')
            ->leftJoin('users as b', 'b.id', '=', 'cs.booked_by_user_id')
            ->leftJoin('children as c', 'c.id', '=', 'cs.booked_child_id')
            ->where('cs.agency_id', $agencyId)
            ->whereIn('cs.centre_id', $this->staffCentres($request, $agencyId) ?: [0])   // their own centres
            ->where('cs.slot_at', '>=', now()->subDays(7))
            ->orderBy('cs.slot_at')
            ->select('cs.*',
                DB::raw("CONCAT(u.first_name,' ',u.last_name) as teacher_name"),
                DB::raw("CONCAT(b.first_name,' ',b.last_name) as booked_by_name"),
                DB::raw("CONCAT(c.first_name,' ',c.last_name) as booked_child_name"))
            ->get();
        // The people a slot can be with (2026-11-27): educators and directors placed at the caller's centres.
        $centres = $this->staffCentres($request, $agencyId);
        $teachers = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
            ->whereNull('u.deleted_at')->where('ra.active', 1)
            ->whereIn('ra.role', ['educator', 'centre_director'])->whereIn('ra.centre_id', $centres ?: [0])
            ->select('u.id', DB::raw("TRIM(CONCAT(u.first_name,' ',u.last_name)) as name"), 'ra.centre_id')
            ->orderBy('u.first_name')->get()
            ->groupBy('id')->map(fn ($g) => ['id' => (int) $g[0]->id, 'name' => $g[0]->name,
                'centre_ids' => $g->pluck('centre_id')->map(fn ($v) => (int) $v)->unique()->values()->all()])
            ->values();
        return response()->json(['data' => $rows, 'teachers' => $teachers]);
    }

    private function isStaffUser(Request $request): bool
    {
        return (bool) array_intersect(\App\Support\AgencyRole::names($request), ['educator', 'centre_director', 'agency_admin', 'platform_admin']);
    }

    /** A teacher for a slot: an educator or director placed at that centre. */
    private function teacherFits(int $userId, int $centreId): bool
    {
        return DB::table('role_assignments')->where('user_id', $userId)->where('active', 1)
            ->whereIn('role', ['educator', 'centre_director'])->where('centre_id', $centreId)->exists();
    }

    /** Who hears about a booking: the slot's teacher, or with none named the centre's director(s) and agency admins. */
    private function slotPeople(object $slot): array
    {
        if ($slot->teacher_user_id) return [(int) $slot->teacher_user_id];
        return \App\Support\CentreScope::managers((int) $slot->agency_id, [(int) $slot->centre_id])
            ->pluck('u.id')->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    /** DELETE /conferences/slots/{id} - staff of the slot's centre remove a slot; a booked family is told. */
    public function destroy(Request $request, int $slotId): JsonResponse
    {
        $this->assertStaff($request);
        $slot = DB::table('conference_slots')->where('id', $slotId)->first();
        abort_unless($slot, 404);
        $agencyId = $this->resolveAgencyId($request);
        abort_unless((int) $slot->agency_id === $agencyId
            && in_array((int) $slot->centre_id, $this->staffCentres($request, $agencyId), true), 404);
        DB::table('conference_slots')->where('id', $slotId)->delete();
        if ($slot->status === 'booked' && $slot->booked_by_user_id) {
            \App\Support\Notify::write([
                'user_id' => (int) $slot->booked_by_user_id, 'type' => 'conference',
                'title' => 'Conference cancelled by the centre',
                'body' => \App\Support\AgencyTime::fmt($slot->slot_at, \App\Support\AgencyTime::tz($agencyId), 'D M j \a\t g:i A') . '. Please book another time.',
                'data' => json_encode(['link' => '#conferences']),
                'created_at' => now(),
            ]);
        }
        return response()->json(['status' => 'deleted']);
    }

    public function createSlots(Request $request): JsonResponse
    {
        $data = $request->validate([
            'centre_id' => 'required|integer',
            'teacher_user_id' => 'nullable|integer',
            'date' => 'required|date',
            'start_time' => 'required',
            'end_time' => 'required',
            'duration_minutes' => 'required|integer|min:10|max:120',
            'location' => 'nullable|string|max:120',
        ]);
        $this->assertStaff($request);
        /* The centre must be one the caller works at, in their active agency (2026-10-05).
           The agency used to be read off whatever centre_id was sent, so staff of ANY
           agency could publish slots at any centre on the platform. */
        $agencyId = $this->resolveAgencyId($request);
        abort_unless(in_array((int) $data['centre_id'], $this->staffCentres($request, $agencyId), true), 403, 'That centre is not one of yours.');
        if (! empty($data['teacher_user_id'])) {
            // An educator or director of THIS centre (2026-11-27): any active role in the agency passed, a parent too.
            abort_unless($this->teacherFits((int) $data['teacher_user_id'], (int) $data['centre_id']), 422, 'Choose a teacher who works at this centre.');
        }
        /* The times typed are the CENTRE's wall clock (2026-11-27): parsed in the agency timezone and stored as UTC
           like every other instant, so "15:00" is 3 PM where the centre is and every screen shows it that way. */
        $tz = \App\Support\AgencyTime::tz($agencyId);
        $start = Carbon::parse($data['date'] . ' ' . $data['start_time'], $tz)->utc();
        $end = Carbon::parse($data['date'] . ' ' . $data['end_time'], $tz)->utc();
        abort_unless($end->gt($start), 422, 'The end time has to be after the start time.');
        $created = 0;
        for ($t = $start->copy(); $t->lt($end); $t->addMinutes($data['duration_minutes'])) {
            DB::table('conference_slots')->insert([
                'agency_id' => $agencyId,
                'centre_id' => $data['centre_id'],
                'teacher_user_id' => $data['teacher_user_id'] ?? null,
                'slot_at' => $t,
                'duration_minutes' => $data['duration_minutes'],
                'location' => $data['location'] ?? null,
                'status' => 'open',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $created++;
        }
        return response()->json(['created' => $created]);
    }

    public function book(Request $request, int $slotId): JsonResponse
    {
        $data = $request->validate(['child_id' => 'required|integer']);
        $u = $request->user();
        $familyId = DB::table('guardians')->where('user_id', $u->id)->value('family_id');
        abort_unless($familyId, 403);
        $child = DB::table('children')->where('id', $data['child_id'])->where('family_id', $familyId)->first();
        abort_unless($child, 403);
        // Only a slot at the family's own centre.
        $slot = DB::table('conference_slots')->where('id', $slotId)->where('status', 'open')
            ->where('centre_id', DB::table('families')->where('id', $familyId)->value('centre_id'))->first();
        abort_unless($slot, 422, 'Slot no longer available');
        DB::table('conference_slots')->where('id', $slotId)->update([
            'status' => 'booked',
            'booked_by_user_id' => $u->id,
            'booked_child_id' => $data['child_id'],
            'booked_at' => now(),
            'updated_at' => now(),
        ]);
        /* Tell the teacher, or with none named the centre's director(s) and admins (2026-11-27): slots had no teacher
           field, so the booking notice never fired. Time in the agency's zone, not UTC. */
        $when = \App\Support\AgencyTime::fmt($slot->slot_at, \App\Support\AgencyTime::tz((int) $slot->agency_id), 'D M j \a\t g:i A');
        foreach ($this->slotPeople($slot) as $pid) {
            \App\Support\Notify::write([
                'user_id' => $pid, 'type' => 'conference',
                'title' => 'Conference booked: ' . $child->first_name,
                'body' => $when,
                'data' => json_encode(['link' => '#conferences', 'slot_id' => $slotId]),
                'created_at' => now(),
            ]);
        }
        return response()->json(['status' => 'booked']);
    }

    public function cancel(Request $request, int $slotId): JsonResponse
    {
        $u = $request->user();
        $slot = DB::table('conference_slots')->where('id', $slotId)->first();
        abort_unless($slot, 404);
        // The booker, another parent of the booked child's family, or staff of the slot's own centre, can cancel.
        $byFamily = $slot->booked_child_id && DB::table('children as c')->join('guardians as g', 'g.family_id', '=', 'c.family_id')
            ->where('c.id', (int) $slot->booked_child_id)->where('g.user_id', $u->id)->exists();
        if ((int) $slot->booked_by_user_id !== (int) $u->id && ! $byFamily) {
            $this->assertStaff($request);
            abort_unless(in_array((int) $slot->centre_id, $this->staffCentres($request, $this->resolveAgencyId($request)), true), 404);
        } elseif ($slot->status === 'booked') {
            // A family cancelling: the teacher (or the centre's managers) hear about it.
            $when = \App\Support\AgencyTime::fmt($slot->slot_at, \App\Support\AgencyTime::tz((int) $slot->agency_id), 'D M j \a\t g:i A');
            foreach ($this->slotPeople($slot) as $pid) {
                \App\Support\Notify::write([
                    'user_id' => $pid, 'type' => 'conference',
                    'title' => 'Conference cancelled by the family',
                    'body' => $when . '. The time is open again.',
                    'data' => json_encode(['link' => '#conferences', 'slot_id' => $slotId]),
                    'created_at' => now(),
                ]);
            }
        }
        DB::table('conference_slots')->where('id', $slotId)->update([
            'status' => 'open',
            'booked_by_user_id' => null, 'booked_child_id' => null, 'booked_at' => null,
            'updated_at' => now(),
        ]);
        return response()->json(['status' => 'cancelled']);
    }

    private function resolveAgencyId(Request $request): int
    {
        $activeId = (int) $request->header('X-Active-Agency-Id');
        // SECURITY (v22p94): only honour the header if the user is platform_admin
        // or holds an active role for that exact agency (else fall back below).
        if ($activeId && DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)->where(function ($w) use ($activeId) { $w->where('agency_id', $activeId)->orWhere('role', 'platform_admin'); })->exists()) return $activeId;
        // SECURITY (v22p98): a platform_admin with no valid SELECTED agency must NOT
        // fall through to their first role's agency (iLearn) — require an explicit
        // choice, else agency-scoped data leaked to a super-admin on a header-less call.
        if (\App\Support\AgencyRole::constrain(DB::table('role_assignments'))->where('user_id', $request->user()->id)->where('role', 'platform_admin')->where('active', true)->exists()) abort(400, 'Select an agency first.');
        $first = DB::table('role_assignments')
            ->where('user_id', $request->user()->id)->where('active', true)
            ->value('agency_id');
        abort_unless($first, 400);
        return (int) $first;
    }

    /** The centres this staff member may manage conferences for (agency-wide for an admin). */
    private function staffCentres(Request $request, int $agencyId): array
    {
        return \App\Support\Visibility::centreIds($agencyId, $request->user());
    }

    private function assertStaff(Request $request): void
    {
        $u = $request->user();
        $isStaff = \App\Support\AgencyRole::constrain(DB::table('role_assignments'))->where('user_id', $u->id)
            ->whereIn('role', ['educator', 'centre_director', 'agency_admin', 'platform_admin'])
            ->where('active', 1)->exists();
        abort_unless($isStaff, 403);
    }
}
