<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What a parent tells us about their own child during onboarding.
 *
 * A photo, what the child is allergic to, and which immunisations they have had. All
 * three already have somewhere to live and somebody reading them — children.photo_url is
 * the face on every roster, children.allergies drives the allergy alerts an educator sees
 * at snack time, and the immunizations table is what the immunisation report measures
 * against the agency's schedule. None of it was ever asked for at the point the parent
 * actually knows the answers.
 *
 * EVERY read and write here is scoped through the guardians table to the signed-in
 * user's own family. A child id arriving from the client is never trusted: ownership is
 * proved before anything is returned or written.
 */
class ParentChildOnboardingController extends Controller
{
    /** Child ids this user is a guardian of. The only children they may ever touch. */
    private function ownChildIds(int $userId): array
    {
        return DB::table('children as c')
            ->join('guardians as g', 'g.family_id', '=', 'c.family_id')
            ->where('g.user_id', $userId)
            ->whereNull('c.deleted_at')
            ->pluck('c.id')->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    /**
     * A JSON array for the columns that demand one, or null.
     *
     * children.allergies and .dietary_restrictions are longtext behind a json_valid()
     * CHECK and hold values like ["Peanuts"]. Null is allowed — a CHECK passes on NULL —
     * and is the right answer for "nothing to declare", because an empty array would
     * read to the allergy screens as a list that exists and happens to be empty.
     */
    private static function jsonList(?array $items): ?string
    {
        if (! $items) {
            return null;
        }
        $clean = array_values(array_filter(array_map(
            fn ($v) => trim((string) $v),
            $items
        ), fn ($v) => $v !== ''));

        return $clean ? json_encode($clean) : null;
    }

    /** GET /parent/onboarding/children — my children, and what is still missing. */
    public function index(Request $request): JsonResponse
    {
        $ids = $this->ownChildIds((int) $request->user()->id);
        if (! $ids) {
            return response()->json(['children' => []]);
        }

        $children = DB::table('children')->whereIn('id', $ids)->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'preferred_name', 'date_of_birth',
                   'photo_url', 'allergies', 'dietary_restrictions', 'medical_notes']);

        $shots = DB::table('immunizations')->whereIn('child_id', $ids)
            ->orderBy('administered_on')
            ->get(['id', 'child_id', 'vaccine', 'dose_label', 'administered_on', 'exempt', 'exemption_reason'])
            ->groupBy('child_id');

        // What this agency expects, so the parent is offered the right vaccines to tick
        // rather than a free-text box nobody can report on.
        $agencyId = DB::table('children as c')
            ->join('families as f', 'f.id', '=', 'c.family_id')
            ->join('centres as ce', 'ce.id', '=', 'f.centre_id')
            ->whereIn('c.id', $ids)->value('ce.agency_id');

        $schedule = $agencyId
            ? DB::table('immunization_schedule')->where('agency_id', $agencyId)
                ->where(function ($q) { $q->where('active', 1)->orWhereNull('active'); })
                ->orderBy('display_order')->orderBy('due_at_age_months')
                ->get(['vaccine', 'dose_label', 'due_at_age_months', 'is_required'])
            : collect();

        return response()->json([
            'children' => $children->map(fn ($c) => [
                'id' => (int) $c->id,
                'name' => trim(($c->preferred_name ?: $c->first_name).' '.($c->last_name ?? '')),
                'date_of_birth' => $c->date_of_birth,
                'photo_url' => $c->photo_url,
                'allergies' => json_decode((string) $c->allergies, true) ?: [],
                'dietary_restrictions' => json_decode((string) $c->dietary_restrictions, true) ?: [],
                'medical_notes' => $c->medical_notes,
                'immunizations' => $shots->get($c->id, collect())->values(),
            ]),
            'schedule' => $schedule,
        ]);
    }

    /** POST /parent/onboarding/children/{child}/photo */
    public function photo(Request $request, int $child): JsonResponse
    {
        abort_unless(in_array($child, $this->ownChildIds((int) $request->user()->id), true), 403, 'Not your child.');

        $request->validate([
            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:6144'],
        ]);

        $file = $request->file('photo');
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $name = (string) Str::uuid().'.'.$ext;
        // Same store and path convention as the admin uploader, so one child photo is
        // never in two places depending on who uploaded it.
        $file->storeAs('child-photos', $name, 'public');
        $url = '/storage/child-photos/'.$name;

        DB::table('children')->where('id', $child)->update([
            'photo_url' => $url,
            'updated_at' => now(),
        ]);

        return response()->json(['photo_url' => $url]);
    }

    /** POST /parent/onboarding/children/{child} — health details and immunisations. */
    public function save(Request $request, int $child): JsonResponse
    {
        abort_unless(in_array($child, $this->ownChildIds((int) $request->user()->id), true), 403, 'Not your child.');

        $data = $request->validate([
            // Lists, not prose: both columns are JSON arrays behind a json_valid()
            // CHECK, and the educator allergy chips render one per entry.
            'allergies' => 'nullable|array|max:40',
            'allergies.*' => 'string|max:120',
            'dietary_restrictions' => 'nullable|array|max:40',
            'dietary_restrictions.*' => 'string|max:120',
            'medical_notes' => 'nullable|string|max:2000',
            'immunizations' => 'present|array|max:60',
            'immunizations.*.vaccine' => 'required|string|max:120',
            'immunizations.*.dose_label' => 'nullable|string|max:80',
            'immunizations.*.administered_on' => 'nullable|date',
            'immunizations.*.exempt' => 'nullable|boolean',
            'immunizations.*.exemption_reason' => 'nullable|string|max:300',
        ]);

        DB::transaction(function () use ($child, $data, $request) {
            DB::table('children')->where('id', $child)->update([
                'allergies' => self::jsonList($data['allergies'] ?? null),
                'dietary_restrictions' => self::jsonList($data['dietary_restrictions'] ?? null),
                'medical_notes' => $data['medical_notes'] ?? null,
                'updated_at' => now(),
            ]);

            foreach ($data['immunizations'] as $row) {
                $exempt = ! empty($row['exempt']);
                // Nothing to record: no date and not an exemption.
                if (empty($row['administered_on']) && ! $exempt) {
                    continue;
                }

                // Matched on vaccine + dose so re-running onboarding, or a parent
                // correcting a date, updates the row instead of adding a second one.
                $existing = DB::table('immunizations')
                    ->where('child_id', $child)
                    ->where('vaccine', $row['vaccine'])
                    ->where(function ($q) use ($row) {
                        $label = $row['dose_label'] ?? null;
                        $label === null ? $q->whereNull('dose_label') : $q->where('dose_label', $label);
                    })
                    ->first();

                $payload = [
                    'child_id' => $child,
                    'vaccine' => $row['vaccine'],
                    'dose_label' => $row['dose_label'] ?? null,
                    'administered_on' => $exempt ? null : ($row['administered_on'] ?? null),
                    'exempt' => $exempt,
                    'exemption_reason' => $exempt ? ($row['exemption_reason'] ?? null) : null,
                    'recorded_by_id' => $request->user()->id,
                    'updated_at' => now(),
                ];

                if ($existing) {
                    DB::table('immunizations')->where('id', $existing->id)->update($payload);
                } else {
                    DB::table('immunizations')->insert($payload + ['created_at' => now()]);
                }
            }
        });

        return response()->json(['ok' => true]);
    }
}
