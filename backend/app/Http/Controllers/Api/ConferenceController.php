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
        if ($familyId) {
            $family = DB::table('families')->where('id', $familyId)->first();
            $rows = DB::table('conference_slots as cs')
                ->leftJoin('users as u', 'u.id', '=', 'cs.teacher_user_id')
                ->where('cs.centre_id', $family->centre_id)
                ->where('cs.status', 'open')
                ->where('cs.slot_at', '>=', now())
                ->orderBy('cs.slot_at')
                ->select('cs.*', DB::raw("CONCAT(u.first_name,' ',u.last_name) as teacher_name"))
                ->get();
            return response()->json(['data' => $rows]);
        }
        // Staff view: all upcoming slots in agency
        $agencyId = $this->resolveAgencyId($request);
        $rows = DB::table('conference_slots as cs')
            ->leftJoin('users as u', 'u.id', '=', 'cs.teacher_user_id')
            ->leftJoin('users as b', 'b.id', '=', 'cs.booked_by_user_id')
            ->leftJoin('children as c', 'c.id', '=', 'cs.booked_child_id')
            ->where('cs.agency_id', $agencyId)
            ->where('cs.slot_at', '>=', now()->subDays(7))
            ->orderBy('cs.slot_at')
            ->select('cs.*',
                DB::raw("CONCAT(u.first_name,' ',u.last_name) as teacher_name"),
                DB::raw("CONCAT(b.first_name,' ',b.last_name) as booked_by_name"),
                DB::raw("CONCAT(c.first_name,' ',c.last_name) as booked_child_name"))
            ->get();
        return response()->json(['data' => $rows]);
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
        $agencyId = (int) DB::table('centres')->where('id', $data['centre_id'])->value('agency_id');
        $start = Carbon::parse($data['date'] . ' ' . $data['start_time']);
        $end = Carbon::parse($data['date'] . ' ' . $data['end_time']);
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
        $slot = DB::table('conference_slots')->where('id', $slotId)->where('status', 'open')->first();
        abort_unless($slot, 422, 'Slot no longer available');
        DB::table('conference_slots')->where('id', $slotId)->update([
            'status' => 'booked',
            'booked_by_user_id' => $u->id,
            'booked_child_id' => $data['child_id'],
            'booked_at' => now(),
            'updated_at' => now(),
        ]);
        // Notify teacher
        if ($slot->teacher_user_id) {
            DB::table('notifications')->insert([
                'user_id' => $slot->teacher_user_id, 'type' => 'conference',
                'title' => 'Conference booked: ' . $child->first_name,
                'body' => Carbon::parse($slot->slot_at)->format('M j \a\t g:i A'),
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
        // Either booker or staff can cancel
        if ($slot->booked_by_user_id !== $u->id) $this->assertStaff($request);
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
        if (DB::table('role_assignments')->where('user_id', $request->user()->id)->where('role', 'platform_admin')->where('active', true)->exists()) abort(400, 'Select an agency first.');
        $first = DB::table('role_assignments')
            ->where('user_id', $request->user()->id)->where('active', true)
            ->value('agency_id');
        abort_unless($first, 400);
        return (int) $first;
    }

    private function assertStaff(Request $request): void
    {
        $u = $request->user();
        $isStaff = DB::table('role_assignments')->where('user_id', $u->id)
            ->whereIn('role', ['educator', 'centre_director', 'agency_admin', 'platform_admin'])
            ->where('active', 1)->exists();
        abort_unless($isStaff, 403);
    }
}
