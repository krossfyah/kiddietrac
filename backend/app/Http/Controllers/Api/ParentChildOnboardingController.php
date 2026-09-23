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
    /**
     * Fold what a parent submitted into what is already on file.
     *
     * Entries are matched by NAME, case-insensitively and trimmed, because that is the
     * only thing the two sides reliably share — a parent types "peanuts", the record says
     * "Peanuts". Where they match, the stored row wins on every field the parent did not
     * supply, so detail entered by staff is never lost to a shorter answer.
     *
     * Anything the parent leaves out is DROPPED, which is the point of the screen: it is
     * how somebody removes an allergy their child has outgrown.
     *
     * @param  string|null  $storedJson  the column as it stands
     * @param  array  $incoming  strings and/or structured rows
     * @param  string  $key  'allergen' or 'restriction'
     */
    private static function mergeList(?string $storedJson, array $incoming, string $key): ?string
    {
        $stored = [];
        if ($storedJson) {
            $decoded = json_decode($storedJson, true);
            if (is_array($decoded)) {
                foreach ($decoded as $row) {
                    $name = is_array($row) ? ($row[$key] ?? '') : (string) $row;
                    $name = trim((string) $name);
                    if ($name !== '') {
                        $stored[mb_strtolower($name)] = is_array($row) ? $row : [$key => $name];
                    }
                }
            }
        }

        $out = [];
        foreach ($incoming as $row) {
            $given = is_array($row) ? $row : [$key => (string) $row];
            $name = trim((string) ($given[$key] ?? ''));
            if ($name === '') {
                continue;
            }
            $prior = $stored[mb_strtolower($name)] ?? [];

            /* The parent's values win where they gave one; the stored row fills the rest.
               array_filter drops nulls and empty strings so a blank box cannot erase a
               field somebody else filled in. */
            $given = array_filter($given, fn ($v) => $v !== null && $v !== '');
            $merged = array_merge($prior, $given);
            /* Keep the stored spelling when the only difference is case. A parent typing
               "peanuts" should not turn a staff-entered "Peanuts" lower-case in the alert
               chips an educator reads. */
            $priorName = trim((string) ($prior[$key] ?? ''));
            $merged[$key] = ($priorName !== '' && mb_strtolower($priorName) === mb_strtolower($name))
                ? $priorName
                : $name;
            $out[] = $merged;
        }

        return $out ? json_encode(array_values($out)) : null;
    }

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

        /* A PHONE PHOTO IS BIGGER THAN 6MB (found 2026-09-17).

           Patricia Burgess hit this twice during onboarding - 422 at 15:24, 422 at 15:25,
           then a different photo succeeded at 15:26 - from Android Chrome. Nothing was
           broken; the cap was simply below what a modern phone camera produces, and the
           message did not say so, leaving a parent to guess and retry.

           12MB covers a full-resolution phone photo. HEIC is deliberately still refused:
           accepting it would store a file most browsers cannot display, so a photo that
           "uploaded fine" would render as a broken box on every roster and emergency
           card. Better to say so and let them convert it.

           The messages name the problem AND the fix, because a parent in the middle of
           onboarding cannot act on "The photo field is invalid." */
        $request->validate([
            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:12288'],
        ], [
            'photo.required' => 'Choose a photo first.',
            'photo.file' => 'That did not come through as a file. Try choosing the photo again.',
            'photo.mimes' => 'That photo format is not supported. Please use a JPG, PNG or WebP '
                . '- if it is a HEIC from an iPhone, open it and choose Share, then Save as JPEG, '
                . 'or set Camera to "Most Compatible" in Settings.',
            'photo.max' => 'That photo is too large. The limit is 12MB - most phones can email '
                . 'or share it at a smaller size, or you can crop it and try again.',
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
            /* Lists, not prose: both columns are JSON arrays behind a json_valid()
               CHECK, and the educator allergy chips render one per entry.

               An entry may be a bare string ("Peanuts") or the structured row the rest of
               the portal now uses ({allergen, severity, reaction, epipen_required,
               epipen_location, action_plan}). Both are accepted because a parent filling
               in onboarding on a phone should not be forced through a form that asks for
               an action plan — but when they DO give the detail, it must survive. */
            'allergies' => 'nullable|array|max:40',
            'allergies.*' => 'nullable',
            'dietary_restrictions' => 'nullable|array|max:40',
            'dietary_restrictions.*' => 'nullable',
            'medical_notes' => 'nullable|string|max:2000',
            'immunizations' => 'present|array|max:60',
            'immunizations.*.vaccine' => 'required|string|max:120',
            'immunizations.*.dose_label' => 'nullable|string|max:80',
            'immunizations.*.administered_on' => 'nullable|date',
            'immunizations.*.exempt' => 'nullable|boolean',
            'immunizations.*.exemption_reason' => 'nullable|string|max:300',
        ]);

        DB::transaction(function () use ($child, $data, $request) {
            /* MERGE, DO NOT CLOBBER.

               A parent re-running onboarding used to overwrite these columns outright with
               a list of plain strings. If a director had already recorded "Peanuts —
               anaphylactic — EpiPen in Room 2 cupboard", a parent typing "peanuts" in the
               onboarding box replaced all of it with ["peanuts"] — silently deleting the
               severity, the action plan and the location of the pen, from the one screen
               an educator reads in an emergency.

               So an incoming entry that names something already on file keeps whatever
               detail is on file, and only adds what the parent actually supplied.
               (Anthony, 2026-09-10) */
            $existingChild = DB::table('children')->where('id', $child)
                ->first(['allergies', 'dietary_restrictions']);

            $update = ['updated_at' => now()];
            if (array_key_exists('allergies', $data)) {
                $update['allergies'] = self::mergeList(
                    $existingChild->allergies ?? null, $data['allergies'] ?? [], 'allergen');
            }
            if (array_key_exists('dietary_restrictions', $data)) {
                $update['dietary_restrictions'] = self::mergeList(
                    $existingChild->dietary_restrictions ?? null, $data['dietary_restrictions'] ?? [], 'restriction');
            }
            if (array_key_exists('medical_notes', $data)) {
                $update['medical_notes'] = $data['medical_notes'] ?? null;
            }
            DB::table('children')->where('id', $child)->update($update);

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
