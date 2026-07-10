<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ChildController extends Controller
{
    use ResolvesCentreContext;

    public function enrollmentList(Request $request): JsonResponse
    {
        // v22p5: allow agency_admin to scope to any centre they own via ?centre_id=
        // v22p88: agency_admin / platform_admin see EVERY child across ALL their
        // agency's centres by default (was wrongly limited to one resolved centre,
        // so most children were missing). Educators/directors stay scoped to their
        // own centre. The centre filter still narrows when supplied.
        $user = $request->user();
        $requestedCentre = $request->integer("centre_id");
        $isAgencyLevel = DB::table('role_assignments')->where('user_id', $user->id)
            ->where('active', 1)->whereIn('role', ['agency_admin', 'platform_admin'])->exists();

        if ($requestedCentre && $this->authorizeCentreAccess($user, $requestedCentre)) {
            $centreIds = [$requestedCentre];
        } elseif ($isAgencyLevel) {
            // SECURITY (v22p94): resolve via the validated helper — never trust the
            // raw X-Active-Agency-Id header, or an admin of one agency could list
            // another agency's children by forging it.
            $agencyId = $this->resolveAgencyId($request);
            $centreIds = $agencyId ? DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all() : [];
        } else {
            $cid = $this->resolveCentreId($user);
            $centreIds = $cid ? [$cid] : [];
        }

        if (empty($centreIds)) {
            return response()->json(['children' => []]);
        }

        $q = DB::table('children')
            ->select(
                'children.*',
                'families.family_name',
                'enrollments.room_id',
                'enrollments.monthly_fee',
                'enrollments.start_date as enrollment_start',
                'rooms.name as room_name',
                'rooms.color_hex as room_color',
                'rooms.age_group as room_age_group',
            )
            ->join('families', 'families.id', '=', 'children.family_id')
            ->leftJoin('enrollments', fn ($j) => $j
                ->on('enrollments.child_id', '=', 'children.id')
                ->whereNull('enrollments.end_date'))
            ->leftJoin('rooms', 'rooms.id', '=', 'enrollments.room_id')
            ->whereIn('families.centre_id', $centreIds)
            ->whereNull('children.deleted_at');

        if ($roomId = $request->input('room_id')) {
            $q->where('enrollments.room_id', $roomId);
        }

        if ($status = $request->input('status')) {
            $q->where('children.enrollment_status', $status);
        }

        if ($search = $request->input('search')) {
            $term = '%'.$search.'%';
            $q->where(fn ($w) => $w
                ->where('children.first_name', 'LIKE', $term)
                ->orWhere('children.last_name', 'LIKE', $term)
                ->orWhere('families.family_name', 'LIKE', $term));
        }

        $children = $q->orderBy('children.first_name')->get();

        $presentChildIds = DB::table('check_events as ci')
            ->whereDate('ci.occurred_at', now())
            ->where('ci.event_type', 'check_in')
            ->whereNotExists(fn ($qq) => $qq->select(DB::raw(1))
                ->from('check_events as co')
                ->whereColumn('co.child_id', 'ci.child_id')
                ->where('co.event_type', 'check_out')
                ->where('co.occurred_at', '>', DB::raw('ci.occurred_at')))
            ->pluck('ci.child_id')
            ->all();

        return response()->json([
            'children' => $children
                ->map(fn ($c) => $this->formatChildListItem($c, in_array($c->id, $presentChildIds, true)))
                ->all(),
        ]);
    }

    public function show(Request $request, int $childId): JsonResponse
    {
        $child = DB::table('children')->where('id', $childId)->whereNull('deleted_at')->first();

        if (! $child) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $this->authorizeChild($request->user(), $child);

        $family = DB::table('families')->where('id', $child->family_id)->first();
        $enrollment = DB::table('enrollments')
            ->where('child_id', $childId)
            ->whereNull('end_date')
            ->first();
        $room = $enrollment
            ? DB::table('rooms')->where('id', $enrollment->room_id)->first()
            : null;

        $healthFlags = DB::table('child_health_flags')
            ->where('child_id', $childId)
            ->where('active', true)
            ->get();

        $guardians = DB::table('guardians')
            ->join('users', 'users.id', '=', 'guardians.user_id')
            ->where('guardians.family_id', $child->family_id)
            ->select(
                'users.id', 'users.first_name', 'users.last_name', 'users.email',
                'guardians.relationship', 'guardians.is_primary', 'guardians.can_pickup',
            )
            ->get();

        $subsidies = DB::table('subsidies')
            ->where('child_id', $childId)
            ->where('active', true)
            ->get();

        $lastCheck = DB::table('check_events')
            ->where('child_id', $childId)
            ->whereDate('occurred_at', now())
            ->orderByDesc('occurred_at')
            ->first();
        $isAtCentre = $lastCheck && $lastCheck->event_type === 'check_in';

        // v22p88: centre + agency + full enrollment history for the detail tabs.
        $centre = $family ? DB::table('centres')->where('id', $family->centre_id)->first(['id', 'name', 'city', 'agency_id']) : null;
        $agency = $centre ? DB::table('agencies')->where('id', $centre->agency_id)->first(['id', 'name']) : null;
        $enrollmentHistory = DB::table('enrollments')
            ->leftJoin('rooms', 'rooms.id', '=', 'enrollments.room_id')
            ->where('enrollments.child_id', $childId)
            ->orderByDesc('enrollments.start_date')
            ->select('enrollments.id', 'enrollments.start_date', 'enrollments.end_date', 'enrollments.monthly_fee', 'rooms.name as room_name')
            ->get();

        return response()->json([
            'centre' => $centre,
            'agency' => $agency,
            'enrollment_history' => $enrollmentHistory,
            'school_name' => property_exists($child, 'school_name') ? $child->school_name : null,
            'school_grade' => property_exists($child, 'school_grade') ? $child->school_grade : null,
            'id' => $child->id,
            'first_name' => $child->first_name,
            'last_name' => $child->last_name,
            'preferred_name' => $child->preferred_name,
            'display_name' => $child->preferred_name ?: $child->first_name,
            'full_name' => trim($child->first_name.' '.$child->last_name),
            'date_of_birth' => $child->date_of_birth,
            'age' => $this->formatAge($child->date_of_birth),
            'gender' => $child->gender,
            'photo_url' => $child->photo_url,
            'medical_notes' => $child->medical_notes,
            'dietary_notes' => $child->dietary_notes,
            'family' => $family,
            'guardians' => $guardians,
            'room' => $room,
            'enrollment' => $enrollment,
            'health_flags' => $healthFlags,
            'subsidies' => $subsidies,
            'is_at_centre' => $isAtCentre,
            'arrived_at' => $isAtCentre ? $lastCheck->occurred_at : null,
        ]);
    }

    public function enroll(Request $request): JsonResponse
    {
        $centreId = $this->resolveCentreId($request->user());

        if (! $centreId) {
            return response()->json(['message' => 'No centre access'], 403);
        }

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'preferred_name' => ['nullable', 'string', 'max:80'],
            'date_of_birth' => ['required', 'date', 'before:today'],
            'gender' => ['nullable', 'in:female,male,non_binary,prefer_not_to_say,other'],
            'family_id' => ['required', 'integer'],
            'room_id' => ['required', 'integer'],
            'start_date' => ['required', 'date'],
            'monthly_fee' => ['required', 'numeric', 'min:0'],
            'cwelcc_eligible' => ['boolean'],
            'medical_notes' => ['nullable', 'string'],
            'dietary_notes' => ['nullable', 'string'],
        ]);

        $family = DB::table('families')->where('id', $data['family_id'])->first();
        if (! $family || (int) $family->centre_id !== $centreId) {
            return response()->json(['message' => 'Family not found in your centre'], 422);
        }

        $room = DB::table('rooms')->where('id', $data['room_id'])->first();
        if (! $room || (int) $room->centre_id !== $centreId) {
            return response()->json(['message' => 'Room not found in your centre'], 422);
        }

        $childId = DB::transaction(function () use ($data) {
            $cid = DB::table('children')->insertGetId([
                'family_id' => $data['family_id'],
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'preferred_name' => $data['preferred_name'] ?? null,
                'date_of_birth' => $data['date_of_birth'],
                'gender' => $data['gender'] ?? 'prefer_not_to_say',
                'medical_notes' => $data['medical_notes'] ?? null,
                'dietary_notes' => $data['dietary_notes'] ?? null,
                'enrollment_status' => 'enrolled',
                'enrolled_at' => $data['start_date'],
                'preferred_lang' => 'en-CA',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('enrollments')->insert([
                'child_id' => $cid,
                'room_id' => $data['room_id'],
                'start_date' => $data['start_date'],
                'monthly_fee' => $data['monthly_fee'],
                'cwelcc_eligible' => $data['cwelcc_eligible'] ?? true,
                'created_at' => now(),
            ]);

            return $cid;
        });

        return response()->json([
            'child_id' => $childId,
            'message' => 'Child enrolled successfully',
        ], 201);
    }

    public function updateEnrollment(Request $request, int $enrollmentId): JsonResponse
    {
        $enrollment = DB::table('enrollments')->where('id', $enrollmentId)->first();

        if (! $enrollment) {
            return response()->json(['message' => 'Not found'], 404);
        }
        // SECURITY (v22p94): a director may only edit enrollments for children at
        // their own centre — not any enrollment id in any agency.
        abort_unless($this->canAccessChildId($request->user(), (int) $enrollment->child_id), 403);

        $data = $request->validate([
            'room_id' => ['integer'],
            'end_date' => ['nullable', 'date'],
            'monthly_fee' => ['numeric', 'min:0'],
            'cwelcc_eligible' => ['boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        DB::table('enrollments')->where('id', $enrollmentId)->update($data);

        return response()->json(['message' => 'Enrollment updated']);
    }

    public function waitlist(Request $request): JsonResponse
    {
        $centreId = $this->resolveCentreId($request->user());

        if (! $centreId) {
            return response()->json(['waitlist' => []]);
        }

        $children = DB::table('children')
            ->join('families', 'families.id', '=', 'children.family_id')
            ->where('families.centre_id', $centreId)
            ->where('children.enrollment_status', 'waitlist')
            ->select('children.*', 'families.family_name')
            ->orderBy('children.created_at')
            ->get();

        return response()->json([
            'waitlist' => $children->map(fn ($c) => $this->formatChildListItem($c, false))->all(),
        ]);
    }

    public function update(Request $request, int $childId): JsonResponse
    {
        $child = DB::table("children")->where("id", $childId)->whereNull("deleted_at")->first();
        if (! $child) {
            return response()->json(["message" => "Not found"], 404);
        }

        $family = DB::table("families")->where("id", $child->family_id)->first();
        if (! $family || ! $this->authorizeCentreAccess($request->user(), (int) $family->centre_id)) {
            return response()->json(["message" => "Forbidden"], 403);
        }

        $data = $request->validate([
            "first_name" => ["sometimes", "string", "max:80"],
            "last_name" => ["sometimes", "string", "max:80"],
            "preferred_name" => ["sometimes", "nullable", "string", "max:80"],
            "pronouns" => ["sometimes", "nullable", "string", "max:40"],
            "date_of_birth" => ["sometimes", "date", "before:today"],
            "gender" => ["sometimes", "nullable", "in:female,male,non_binary,prefer_not_to_say,other"],
            "photo_url" => ["sometimes", "nullable", "string", "max:255"],
            "health_card_last4" => ["sometimes", "nullable", "string", "size:4"],
            "doctor_name" => ["sometimes", "nullable", "string", "max:120"],
            "doctor_phone" => ["sometimes", "nullable", "string", "max:40"],
            "medical_notes" => ["sometimes", "nullable", "string"],
            "dietary_notes" => ["sometimes", "nullable", "string"],
            "cultural_notes" => ["sometimes", "nullable", "string"],
            "preferred_lang" => ["sometimes", "nullable", "string", "max:10"],
            "enrollment_status" => ["sometimes", "in:enrolled,withdrawn,waitlist,inactive"],
        ]);

        if (empty($data)) {
            return response()->json(["message" => "No changes"], 200);
        }

        $data["updated_at"] = now();
        DB::table("children")->where("id", $childId)->update($data);

        return response()->json(["message" => "Child updated", "id" => $childId]);
    }

    public function destroy(Request $request, int $childId): JsonResponse
    {
        $child = DB::table("children")->where("id", $childId)->whereNull("deleted_at")->first();
        if (! $child) {
            return response()->json(["message" => "Not found"], 404);
        }

        $family = DB::table("families")->where("id", $child->family_id)->first();
        if (! $family || ! $this->authorizeCentreAccess($request->user(), (int) $family->centre_id)) {
            return response()->json(["message" => "Forbidden"], 403);
        }

        DB::transaction(function () use ($childId) {
            DB::table("enrollments")
                ->where("child_id", $childId)
                ->whereNull("end_date")
                ->update(["end_date" => now()->toDateString()]);
            DB::table("children")->where("id", $childId)->update([
                "deleted_at" => now(),
                "enrollment_status" => "withdrawn",
                "withdrawn_at" => now()->toDateString(),
                "updated_at" => now(),
            ]);
        });

        return response()->json(["message" => "Child archived", "id" => $childId]);
    }

    // ─── helpers ────────────────────────────────────────────────────

    /** v22p88: GET /director/children/{child}/daily-events — recent day events + check-ins. */
    public function dailyEvents(Request $request, int $childId): JsonResponse
    {
        $child = DB::table('children')->where('id', $childId)->whereNull('deleted_at')->first();
        if (! $child) return response()->json(['message' => 'Not found'], 404);
        $this->authorizeChild($request->user(), $child);
        $days = max(1, min(90, (int) $request->query('days', 14)));
        $since = now()->subDays($days)->startOfDay();
        $events = DB::table('daily_events')->where('child_id', $childId)->where('occurred_at', '>=', $since)->orderByDesc('occurred_at')->limit(200)->get();
        $checks = DB::table('check_events')->where('child_id', $childId)->where('occurred_at', '>=', $since)->orderByDesc('occurred_at')->limit(200)->get();
        return response()->json(['events' => $events, 'checks' => $checks]);
    }

    /** v22p88: GET /director/children/{child}/feed — photos/media + observations. */
    public function feed(Request $request, int $childId): JsonResponse
    {
        $child = DB::table('children')->where('id', $childId)->whereNull('deleted_at')->first();
        if (! $child) return response()->json(['message' => 'Not found'], 404);
        $this->authorizeChild($request->user(), $child);
        $media = [];
        if (Schema::hasTable('media_child_tags')) {
            $media = DB::table('media')
                ->join('media_child_tags', 'media_child_tags.media_id', '=', 'media.id')
                ->where('media_child_tags.child_id', $childId)
                ->orderByDesc('media.created_at')->limit(60)->select('media.*')->get();
        }
        $observations = DB::table('observations')->where('child_id', $childId)->orderByDesc('created_at')->limit(40)->get();
        return response()->json(['media' => $media, 'observations' => $observations]);
    }

    /** v22p88: GET /director/children/{child}/documents — attachments scoped to the child. */
    public function documents(Request $request, int $childId): JsonResponse
    {
        $child = DB::table('children')->where('id', $childId)->whereNull('deleted_at')->first();
        if (! $child) return response()->json(['message' => 'Not found'], 404);
        $this->authorizeChild($request->user(), $child);
        $docs = DB::table('documents')->where('scope_type', 'child')->where('scope_id', $childId)
            ->orderByDesc('created_at')->get(['id', 'title', 'category', 'file_url', 'file_type', 'file_size', 'created_at', 'expires_at']);
        return response()->json(['documents' => $docs]);
    }

    private function authorizeChild($user, object $child): void
    {
        if (DB::table('guardians')
            ->where('user_id', $user->id)
            ->where('family_id', $child->family_id)
            ->exists()) {
            return;
        }

        $family = DB::table('families')->where('id', $child->family_id)->first();
        if ($family && $this->authorizeCentreAccess($user, (int) $family->centre_id)) {
            return;
        }

        abort(403);
    }

    private function formatChildListItem(object $c, bool $isAtCentre): array
    {
        return [
            'id' => $c->id,
            'first_name' => $c->first_name,
            'last_name' => $c->last_name,
            'preferred_name' => $c->preferred_name,
            'display_name' => $c->preferred_name ?: $c->first_name,
            'full_name' => trim($c->first_name.' '.$c->last_name),
            'date_of_birth' => $c->date_of_birth,
            'age' => $this->formatAge($c->date_of_birth),
            'photo_url' => $c->photo_url,
            'family_id' => $c->family_id,
            'family_name' => $c->family_name ?? null,
            'enrollment_status' => $c->enrollment_status,
            'room_id' => $c->room_id ?? null,
            'room_name' => $c->room_name ?? null,
            'room_color' => $c->room_color ?? null,
            'room_age_group' => $c->room_age_group ?? null,
            'monthly_fee' => $c->monthly_fee ?? null,
            'is_at_centre' => $isAtCentre,
        ];
    }

    private function formatAge(?string $dob): array
    {
        if (! $dob) {
            return ['human' => '—', 'total_months' => 0];
        }

        $totalMonths = (int) Carbon::parse($dob)->diffInMonths(now());
        $years = intdiv($totalMonths, 12);
        $months = $totalMonths % 12;

        return [
            'years' => $years,
            'months' => $months,
            'total_months' => $totalMonths,
            'human' => $years > 0 ? "{$years}y {$months}m" : "{$months} months",
        ];
    }
}
