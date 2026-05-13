<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Models\Immunization;
use App\Models\Medication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ChildHealthController v22p1.
 *
 * Aggregates allergies, dietary restrictions, current meds, and immunization summary
 * for a single child. Director can update structured allergy / dietary fields;
 * educator and parent can read.
 *
 *   GET   /director/children/{child}/health
 *   PATCH /director/children/{child}/health
 *   GET   /provider/children/{child}/health
 *   GET   /parent/children/{child}/health
 */
class ChildHealthController extends Controller
{
    use ResolvesCentreContext;

    public function show(Request $request, int $childId): JsonResponse
    {
        $centreId = $this->resolveCentreId($request->user());
        $isGuardian = false;
        if (! $centreId) {
            // No centre context — must be a guardian. Verify.
            $isGuardian = DB::table('guardians')
                ->join('children', 'children.family_id', '=', 'guardians.family_id')
                ->where('guardians.user_id', $request->user()->id)
                ->where('children.id', $childId)
                ->exists();
            if (! $isGuardian) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        $child = DB::table('children')
            ->where('id', $childId)
            ->select(
                'id', 'first_name', 'last_name', 'date_of_birth', 'photo_url',
                'medical_notes', 'dietary_notes', 'allergies', 'dietary_restrictions',
                'health_alerts', 'doctor_name', 'doctor_phone'
            )
            ->first();

        if (! $child) {
            return response()->json(['message' => 'Child not found'], 404);
        }

        // For staff: confirm child is enrolled at user's centre.
        // enrollments has room_id, not centre_id - join through rooms.
        if (! $isGuardian) {
            $enrolled = DB::table('enrollments')
                ->join('rooms', 'rooms.id', '=', 'enrollments.room_id')
                ->where('enrollments.child_id', $childId)
                ->where('rooms.centre_id', $centreId)
                ->exists();
            if (! $enrolled) {
                return response()->json(['message' => 'Child not in your centre'], 403);
            }
        }

        $activeMeds = Medication::where('child_id', $childId)
            ->where('status', 'active')
            ->orderByDesc('starts_on')
            ->get();

        $immunizations = Immunization::where('child_id', $childId)
            ->orderByDesc('administered_on')
            ->limit(50)
            ->get();

        $overdueCount = $immunizations
            ->filter(fn ($i) => $i->next_due_on && ! $i->exempt && $i->next_due_on->isPast())
            ->count();

        return response()->json([
            'child' => [
                'id'                    => $child->id,
                'name'                  => trim($child->first_name . ' ' . $child->last_name),
                'date_of_birth'         => $child->date_of_birth,
                'photo_url'             => $child->photo_url,
                'medical_notes'         => $child->medical_notes,
                'dietary_notes'         => $child->dietary_notes,
                'allergies'             => json_decode($child->allergies ?? '[]', true),
                'dietary_restrictions'  => json_decode($child->dietary_restrictions ?? '[]', true),
                'health_alerts'         => json_decode($child->health_alerts ?? '[]', true),
                'doctor_name'           => $child->doctor_name,
                'doctor_phone'          => $child->doctor_phone,
            ],
            'active_medications' => $activeMeds,
            'immunizations'      => $immunizations,
            'immunizations_overdue_count' => $overdueCount,
        ]);
    }

    public function update(Request $request, int $childId): JsonResponse
    {
        $centreId = $this->resolveCentreId($request->user());
        if (! $centreId) {
            return response()->json(['message' => 'No centre access'], 403);
        }
        $enrolled = DB::table('enrollments')
            ->join('rooms', 'rooms.id', '=', 'enrollments.room_id')
            ->where('enrollments.child_id', $childId)
            ->where('rooms.centre_id', $centreId)
            ->exists();
        if (! $enrolled) {
            return response()->json(['message' => 'Child not in your centre'], 403);
        }

        $data = $request->validate([
            'allergies'            => 'nullable|array',
            'allergies.*.allergen' => 'required_with:allergies|string|max:120',
            'allergies.*.reaction' => 'nullable|string|max:300',
            'allergies.*.severity' => 'nullable|in:mild,moderate,anaphylactic',
            'allergies.*.action_plan' => 'nullable|string|max:1000',
            'allergies.*.epipen_required' => 'nullable|boolean',
            'allergies.*.epipen_location' => 'nullable|string|max:120',

            'dietary_restrictions'                => 'nullable|array',
            'dietary_restrictions.*.restriction'  => 'required_with:dietary_restrictions|string|max:120',
            'dietary_restrictions.*.details'      => 'nullable|string|max:500',

            'health_alerts'           => 'nullable|array',
            'health_alerts.*.label'   => 'required_with:health_alerts|string|max:80',
            'health_alerts.*.detail'  => 'nullable|string|max:300',

            'medical_notes' => 'nullable|string|max:5000',
            'dietary_notes' => 'nullable|string|max:5000',
            'doctor_name'   => 'nullable|string|max:160',
            'doctor_phone'  => 'nullable|string|max:40',
        ]);

        $update = [];
        if (array_key_exists('allergies', $data)) {
            $update['allergies'] = json_encode($data['allergies'] ?? []);
        }
        if (array_key_exists('dietary_restrictions', $data)) {
            $update['dietary_restrictions'] = json_encode($data['dietary_restrictions'] ?? []);
        }
        if (array_key_exists('health_alerts', $data)) {
            $update['health_alerts'] = json_encode($data['health_alerts'] ?? []);
        }
        foreach (['medical_notes', 'dietary_notes', 'doctor_name', 'doctor_phone'] as $f) {
            if (array_key_exists($f, $data)) {
                $update[$f] = $data[$f];
            }
        }
        $update['updated_at'] = now();

        DB::table('children')->where('id', $childId)->update($update);

        return $this->show($request, $childId);
    }
}
