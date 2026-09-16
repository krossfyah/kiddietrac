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

        /* Staff: the same question update() asks -- "can I see this child" rather than
           "is this child at my one centre". Matching a single resolved centre meant an
           agency admin could read the health record of children at one of their nine
           centres and got a 403 for the rest, and any enrolled child not yet placed in a
           room was unreachable from either side. canAccessChildScoped() honours the active
           agency, a director's centres and an educator's rooms, and fails closed.
           (Anthony, 2026-09-10) */
        if (! $isGuardian && ! $this->canAccessChildScoped($request, $childId)) {
            return response()->json(['message' => 'You do not have access to that child.'], 403);
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
        /* "CAN I SEE THIS CHILD", NOT "IS THIS CHILD AT MY ONE CENTRE".

           This used to resolve a SINGLE centre for the caller and require the child to be
           enrolled in a room there. For a director of one centre that is the same
           question. For an agency admin it is not: resolveCentreId() hands back one centre
           out of the nine iLearn runs, so eight centres' children were unreachable —
           saving an allergy for a child at any other centre answered "Child not in your
           centre" with no way forward. It also refused any enrolled child who has not been
           placed in a room yet, which is exactly when somebody is filling in their health
           details.

           canAccessChildScoped() is the guard the rest of the portal uses for a child's
           records: it honours the active agency, a director's centres, and an educator's
           rooms, and it fails closed. Same answer as before for a director; the right one
           for everybody else. (Anthony, 2026-09-10) */
        if (! $this->canAccessChildScoped($request, $childId)) {
            return response()->json(['message' => 'You do not have access to that child.'], 403);
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
