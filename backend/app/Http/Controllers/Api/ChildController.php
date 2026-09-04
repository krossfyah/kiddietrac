<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

final class ChildController extends Controller
{
    use \App\Http\Controllers\Concerns\AuthorizesTenantAccess;

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

        /* One row per child, with their open enrolments folded together first. A child
           can hold more than one — Mon-Thu with one provider, Friday with another — and
           joining them directly listed that child once per provider. */
        $enrolments = DB::table('enrollments as e')
            ->leftJoin('rooms as r', 'r.id', '=', 'e.room_id')
            ->whereNull('e.end_date')
            ->groupBy('e.child_id')
            ->select(
                'e.child_id',
                // A representative room, for the colour swatch and the row's link.
                DB::raw('MIN(e.room_id) as room_id'),
                // The fee is carried across a split, not divided by it, so they match —
                // MAX simply avoids summing the same figure twice.
                DB::raw('MAX(e.monthly_fee) as monthly_fee'),
                DB::raw('MIN(e.start_date) as enrollment_start'),
                DB::raw('COUNT(DISTINCT e.room_id) as rooms_count'),
                DB::raw("GROUP_CONCAT(DISTINCT r.name ORDER BY r.name SEPARATOR ', ') as room_names")
            );

        $q = DB::table('children')
            ->select(
                'children.*',
                'families.family_name',
                'en.room_id',
                'en.monthly_fee',
                'en.enrollment_start',
                // How many providers this child has, and their names — so the table can
                // say "2 providers" instead of showing the child twice.
                'en.rooms_count',
                'en.room_names',
                'rooms.name as room_name',
                'rooms.color_hex as room_color',
                'rooms.age_group as room_age_group',
            )
            ->join('families', 'families.id', '=', 'children.family_id')
            ->leftJoinSub($enrolments, 'en', fn ($j) => $j->on('en.child_id', '=', 'children.id'))
            ->leftJoin('rooms', 'rooms.id', '=', 'en.room_id')
            ->whereIn('families.centre_id', $centreIds)
            ->whereNull('children.deleted_at');

        if ($roomId = $request->input('room_id')) {
            /* Filter on MEMBERSHIP of that room, not on the representative one — a child
               who is only with this provider on Fridays still belongs in its list. */
            $q->whereExists(fn ($sub) => $sub->select(DB::raw(1))->from('enrollments as fe')
                ->whereColumn('fe.child_id', 'children.id')
                ->whereNull('fe.end_date')
                ->where('fe.room_id', $roomId));
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

        /* When each child was last actually here. Enrolment status says what the
           paperwork claims; this says whether they have been in recently, so a child
           who stopped attending three weeks ago no longer looks identical to one who
           was here this morning.

           check_in only — a check_out is the same visit ending, and taking MAX over
           both would report a departure as an attendance. */
        try {
            $seenIds = $children->pluck('id')->filter()->values()->all();
            $lastSeen = $seenIds
                ? DB::table('check_events')
                    ->whereIn('child_id', $seenIds)
                    ->where('event_type', 'check_in')
                    ->groupBy('child_id')
                    ->select('child_id', DB::raw('MAX(occurred_at) as seen'))
                    ->pluck('seen', 'child_id')
                : collect();
            $children->each(function ($c) use ($lastSeen) {
                $c->last_seen_at = $lastSeen[$c->id] ?? null;
            });
        } catch (\Throwable $e) {
            /* The roster must still render without attendance data. Every row gets
               the property so the front end never has to test for its absence. */
            $children->each(function ($c) {
                if (! isset($c->last_seen_at)) { $c->last_seen_at = null; }
            });
        }

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
        /* A removed child's record is retained for years after they leave, and an
           admin opening it from the Archived list must not get a 404. Opt-in per
           request so every other caller keeps excluding them. (2026-08-25) */
        $wantArchived = $request->boolean('archived');

        $child = DB::table('children')->where('id', $childId)
            ->when(! $wantArchived, fn ($q) => $q->whereNull('deleted_at'))
            ->first();

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
            // The DAYS matter now: with a week split between providers, "Cassandra ·
            // Aug 5 → present" is true of both rows and distinguishes neither.
            ->select('enrollments.id', 'enrollments.start_date', 'enrollments.end_date',
                     'enrollments.monthly_fee', 'enrollments.schedule', 'rooms.name as room_name')
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
            /* Expected drop-off / pick-up. Trimmed to HH:MM because that is what the
               field accepts and what anybody reads — MySQL hands back 08:15:00. */
            'is_archived' => (bool) ($child->deleted_at ?? null),
            'departed_at' => $child->deleted_at ?? ($child->withdrawn_at ?? null),
            'expected_dropoff_time' => $child->expected_dropoff_time
                ? substr((string) $child->expected_dropoff_time, 0, 5) : null,
            'expected_pickup_time' => $child->expected_pickup_time
                ? substr((string) $child->expected_pickup_time, 0, 5) : null,
            // #10 — the dedicated safety fields, surfaced on the child detail record.
            'allergies' => $child->allergies ?? null,
            'health_alerts' => $child->health_alerts ?? null,
            'dietary_restrictions' => $child->dietary_restrictions ?? null,
            'cultural_notes' => $child->cultural_notes ?? null,
            'doctor_name' => $child->doctor_name ?? null,
            'doctor_phone' => $child->doctor_phone ?? null,
            'health_card_last4' => $child->health_card_last4 ?? null,
            'pronouns' => $child->pronouns ?? null,
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
                /* Write BOTH halves of being in a room. This wrote the enrolment row
                   below but left children.primary_room_id null, and the two are read by
                   different halves of the portal: the educator's room list, ratios and
                   the day brief go by primary_room_id, while the centre roster, room
                   roster and QR check-in INNER JOIN enrollments. Setting only one made a
                   child visible in some lists and absent from others, with nothing
                   anywhere to say why. (2026-08-26) */
                'primary_room_id' => $data['room_id'],
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

        // SECURITY (2026-08-25): the child was authorised above, but the DESTINATION
        // room was not — so a director could move a child into any room in any centre
        // or agency by id, putting them on a foreign educator's roster. store() already
        // checks the room; this path did not. Found via enr#66: a centre-8 child sitting
        // in centre 14's room. The room must belong to the child's own family centre.
        if (array_key_exists('room_id', $data)) {
            $familyCentreId = DB::table('children')
                ->join('families', 'families.id', '=', 'children.family_id')
                ->where('children.id', (int) $enrollment->child_id)
                ->value('families.centre_id');

            $room = DB::table('rooms')->where('id', (int) $data['room_id'])->first();

            if (! $room || (int) $room->centre_id !== (int) $familyCentreId) {
                return response()->json(['message' => 'Room not found in this child\'s centre'], 422);
            }
        }

        DB::table('enrollments')->where('id', $enrollmentId)->update($data);

        return response()->json(['message' => 'Enrollment updated']);
    }

    /**
     * Move a child from one provider to another — off the old roster, onto the new one.
     *
     * Before this existed the operation was two separate manual edits: change
     * `families.centre_id`, then change `enrollments.room_id`. Doing only the second is
     * what produced enr#66 — a centre-8 child sitting in centre 14's room, on the wrong
     * provider's roster for nineteen days. One endpoint, one transaction, both writes or
     * neither.
     *
     * The enrolment is ENDED and a new one OPENED rather than having its room_id
     * rewritten. Rosters read `enrollments` where `end_date IS NULL`, so this both clears
     * the old roster and leaves the child's placement history intact — which is a
     * licensed record, not a detail.
     *
     * A child's centre is derived from its family, so a family cannot straddle two
     * centres. If siblings are staying put the transfer is refused rather than guessed
     * at; `move_siblings` moves the whole family deliberately.
     */
    public function transferChild(Request $request, int $childId): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $this->assertChild($userId, $childId);

        $data = $request->validate([
            'to_room_id' => ['required', 'integer'],
            'effective_date' => ['required', 'date'],
            'move_siblings' => ['nullable', 'boolean'],
            /* Which children are actually moving. Absent means the old behaviour:
               the named child, plus every sibling when move_siblings is set. Present
               means the caller has chosen deliberately, and the choice is honoured
               even when it leaves a sibling behind. */
            'child_ids' => ['nullable', 'array'],
            'child_ids.*' => ['integer'],
            'reason' => ['nullable', 'string', 'max:300'],
        ]);

        $child = DB::table('children')->where('id', $childId)->whereNull('deleted_at')->first();
        if (! $child) {
            return response()->json(['message' => 'Child not found'], 404);
        }
        $family = DB::table('families')->where('id', $child->family_id)->first();
        if (! $family) {
            return response()->json(['message' => 'This child has no family record to move.'], 422);
        }

        $toRoom = DB::table('rooms')->where('id', (int) $data['to_room_id'])->first();
        if (! $toRoom) {
            return response()->json(['message' => 'That room does not exist.'], 422);
        }
        $toCentre = DB::table('centres')->where('id', (int) $toRoom->centre_id)->first();
        $fromCentre = DB::table('centres')->where('id', (int) $family->centre_id)->first();
        if (! $toCentre || ! $fromCentre) {
            return response()->json(['message' => 'Could not resolve both centres.'], 422);
        }

        // The caller must be able to reach BOTH ends. assertChild covered the origin.
        $this->assertCentre($userId, (int) $toCentre->id);

        // A transfer is a move within one agency. Crossing agencies is not a transfer,
        // it is a withdrawal at one business and an enrolment at another.
        if ((int) $toCentre->agency_id !== (int) $fromCentre->agency_id) {
            return response()->json([
                'message' => 'A child cannot be transferred between agencies. Withdraw them here and enrol them there.',
            ], 422);
        }

        $openEnrolments = DB::table('enrollments')->where('child_id', $childId)
            ->whereNull('end_date')->get();
        $currentRoomIds = $openEnrolments->pluck('room_id')->filter()->map(fn ($v) => (int) $v)->all();
        if (in_array((int) $toRoom->id, $currentRoomIds, true) && count($currentRoomIds) === 1) {
            return response()->json(['message' => 'This child is already with that provider.'], 422);
        }

        // Capacity at the destination — the number that means "most children at one time".
        $occupied = DB::table('enrollments')
            ->join('children as ch', 'ch.id', '=', 'enrollments.child_id')
            ->where('enrollments.room_id', $toRoom->id)
            ->whereNull('enrollments.end_date')
            ->where('ch.enrollment_status', 'enrolled')
            ->whereNull('ch.deleted_at')
            ->count();

        // Siblings: everyone else in this family who is still enrolled and placed.
        $siblings = DB::table('children as ch')
            ->join('enrollments as e', 'e.child_id', '=', 'ch.id')
            ->where('ch.family_id', $family->id)
            ->where('ch.id', '!=', $childId)
            ->where('ch.enrollment_status', 'enrolled')
            ->whereNull('ch.deleted_at')
            ->whereNull('e.end_date')
            ->select('ch.id', 'ch.first_name', 'ch.last_name', 'ch.preferred_name')
            ->distinct()->get();

        /* An explicit selection, filtered to children who are really in this family
           and really enrolled — a child id from anywhere else is dropped, not trusted. */
        $selected = null;
        if (! empty($data['child_ids'])) {
            $eligible = $siblings->pluck('id')->map(fn ($v) => (int) $v)->push($childId)->unique();
            $selected = collect($data['child_ids'])->map(fn ($v) => (int) $v)
                ->filter(fn ($v) => $eligible->contains($v))->unique()->values();
            if ($selected->isEmpty()) {
                return response()->json(['message' => 'Select at least one child to move.'], 422);
            }
        }

        $moveSiblings = (bool) ($data['move_siblings'] ?? false);
        /* The "they move together or not at all" guard only applies when nobody has said
           which children are moving. A family CAN now be split across providers — the
           caller is warned in the UI and the family record follows the children who
           move (see the centre_id update below). (Anthony, 2026-08-27) */
        if ($selected === null && $siblings->count() > 0 && ! $moveSiblings) {
            $names = $siblings->map(fn ($s) => trim(($s->preferred_name ?: $s->first_name).' '.($s->last_name ?? '')))->all();
            return response()->json([
                'message' => 'This family has other enrolled children. A family belongs to one provider, '
                    .'so they move together or not at all.',
                'siblings' => $names,
                'hint' => 'Send move_siblings=true to move the whole family, or withdraw this child instead.',
            ], 422);
        }

        $movingIds = $selected !== null
            ? $selected->all()
            : array_merge([$childId], $moveSiblings ? $siblings->pluck('id')->map(fn ($v) => (int) $v)->all() : []);
        $movingIds = array_values(array_unique($movingIds));

        /* Who is being left where they are. Reported back so the caller can say so
           plainly rather than implying the whole family moved. */
        $stayingIds = $siblings->pluck('id')->map(fn ($v) => (int) $v)->push($childId)->unique()
            ->reject(fn ($v) => in_array($v, $movingIds, true))->values();
        $stayingNames = $stayingIds->count()
            ? DB::table('children')->whereIn('id', $stayingIds->all())->get()
                ->map(fn ($c) => trim(($c->preferred_name ?: $c->first_name).' '.($c->last_name ?? '')))->all()
            : [];

        if (($occupied + count($movingIds)) > (int) ($toRoom->capacity ?: 0) && (int) ($toRoom->capacity ?: 0) > 0) {
            return response()->json([
                'message' => $toCentre->name.' has '.$occupied.' of '.$toRoom->capacity
                    .' places filled — '.count($movingIds).' more would go over capacity.',
                'occupied' => $occupied, 'capacity' => (int) $toRoom->capacity, 'moving' => count($movingIds),
            ], 422);
        }

        $effective = Carbon::parse($data['effective_date'])->toDateString();

        /* Which room does each child land in?
           The named room is for the child the request is about. Siblings are a different
           question: at an age-graded centre a toddler and a preschooler are in different
           rooms, and dropping both into whichever room was named would put a four-year-old
           in with the infants. So for a sibling, look for a room of the SAME NAME at the
           destination and use it when it exists — centres in one agency are commonly set
           up from the same template, so "Rainbow Toddlers" maps to "Rainbow Toddlers".
           Where there is no match (and for every single-room home provider) the named
           room is used, which is the old behaviour. */
        $destRooms = DB::table('rooms')->where('centre_id', $toRoom->centre_id)->get();
        $roomFor = function (int $cid) use ($childId, $toRoom, $destRooms): int {
            // The named room belongs to the child the request was made about.
            if ($cid === $childId) {
                return (int) $toRoom->id;
            }
            $currentName = DB::table('enrollments as e')->join('rooms as r', 'r.id', '=', 'e.room_id')
                ->where('e.child_id', $cid)->whereNull('e.end_date')->value('r.name');
            if ($currentName) {
                foreach ($destRooms as $dr) {
                    if (mb_strtolower(trim((string) $dr->name)) === mb_strtolower(trim((string) $currentName))) {
                        return (int) $dr->id;
                    }
                }
            }

            return (int) $toRoom->id;
        };

        $placements = [];
        DB::transaction(function () use ($movingIds, $toRoom, $family, $effective, $roomFor, &$placements) {
            foreach ($movingIds as $cid) {
                $open = DB::table('enrollments')->where('child_id', $cid)->whereNull('end_date')->get();
                $destRoomId = $roomFor((int) $cid);
                $placements[(int) $cid] = $destRoomId;

                DB::table('enrollments')->where('child_id', $cid)->whereNull('end_date')
                    ->update(['end_date' => $effective]);

                // Carry the placement's terms forward; a move is not a re-negotiation.
                $prev = $open->first();
                DB::table('enrollments')->insert([
                    'child_id' => $cid,
                    'room_id' => $destRoomId,
                    'start_date' => $effective,
                    'end_date' => null,
                    'schedule' => $prev->schedule ?? null,
                    'monthly_fee' => $prev->monthly_fee ?? 0,
                    'cwelcc_eligible' => $prev->cwelcc_eligible ?? 1,
                    'created_at' => now(),
                ]);

                // Keep the decorative column honest too. It is not what rosters read,
                // but leaving it pointing at the old room is how enr#66 stayed hidden.
                DB::table('children')->where('id', $cid)
                    ->update(['primary_room_id' => $destRoomId, 'updated_at' => now()]);
            }

            /* The family record follows the children who MOVED, even on a partial move.
               families.centre_id is read in ~177 places (chat scoping, exports, report
               cards, billing), so whichever way it points, the other provider loses the
               family-level view. Pointing it at the destination means the provider now
               caring for a child can also message that child's parents — which matters
               more than the old provider keeping a thread for a child who has left.
               A child left behind stays correct on their own provider's roster, ratios,
               daily log and check-in: those read children.primary_room_id and
               enrollments.room_id, which are per-child. (Anthony's call, 2026-08-27) */
            DB::table('families')->where('id', $family->id)
                ->update(['centre_id' => $toRoom->centre_id, 'updated_at' => now()]);
        });

        // ── Tell the people it affects. Best-effort: the move is already committed. ──
        $names = DB::table('children')->whereIn('id', $movingIds)
            ->get()->map(fn ($c) => $c->preferred_name ?: $c->first_name)->implode(', ');
        $effFmt = Carbon::parse($effective)->format('M j, Y');

        try {
            $oldEducators = $currentRoomIds
                ? DB::table('educator_rooms')->whereIn('room_id', $currentRoomIds)->pluck('user_id')->all() : [];
            // Every destination room that actually received a child, not just the named
            // one — siblings can land in different rooms at the same centre.
            $newEducators = DB::table('educator_rooms')
                ->whereIn('room_id', array_values(array_unique($placements)))->pluck('user_id')->all();
            $guardians = DB::table('guardians')->where('family_id', $family->id)
                ->whereNotNull('user_id')->pluck('user_id')->all();

            $rows = [];
            foreach (array_unique($oldEducators) as $uid) {
                $rows[] = ['user_id' => $uid, 'type' => 'transfer', 'title' => '👋 Moving on',
                    'body' => $names.' transferred to '.$toCentre->name.', effective '.$effFmt
                        .'. They have come off your roster.',
                    'data' => json_encode(['link' => '#today']), 'created_at' => now()];
            }
            foreach (array_unique($newEducators) as $uid) {
                $rows[] = ['user_id' => $uid, 'type' => 'transfer', 'title' => '👋 Joining you',
                    'body' => $names.' transferred from '.$fromCentre->name.', effective '.$effFmt
                        .'. They are on your roster now.',
                    'data' => json_encode(['link' => '#today']), 'created_at' => now()];
            }
            foreach (array_unique($guardians) as $uid) {
                $rows[] = ['user_id' => $uid, 'type' => 'transfer', 'title' => 'Your child care provider is changing',
                    'body' => 'From '.$effFmt.', '.$names.' will be with '.$toCentre->name.'.',
                    'data' => json_encode(['link' => '#home']), 'created_at' => now()];
            }
            if ($rows) {
                DB::table('notifications')->insert($rows);
            }

            // A bell is what a parent sees next time they open the app. Who is caring for
            // their child from Monday is not something to leave sitting in an app.
            $addresses = DB::table('users')->whereIn('id', array_unique($guardians))
                ->whereNull('deleted_at')->pluck('email')->all();
            $this->mailTransfer(
                (int) $toCentre->agency_id, $addresses, $names,
                $fromCentre->name, $toCentre->name, $effFmt, $data['reason'] ?? null
            );
        } catch (\Throwable $e) {
            Log::warning('Transfer notifications failed', ['child' => $childId, 'error' => $e->getMessage()]);
        }

        \App\Support\Audit::write([
            'user_id' => $userId,
            'agency_id' => (int) $toCentre->agency_id,
            'action' => 'child.transferred',
            'entity_type' => 'child',
            'entity_id' => $childId,
            'payload' => json_encode([
                'summary' => $names.' transferred from '.$fromCentre->name.' to '.$toCentre->name
                    .' effective '.$effFmt,
                'children' => $movingIds,
                'from_centre' => (int) $fromCentre->id,
                'to_centre' => (int) $toCentre->id,
                'to_room' => (int) $toRoom->id,
                'effective_date' => $effective,
                'moved_siblings' => $moveSiblings,
                'reason' => $data['reason'] ?? null,
            ]),
            'ip_address' => substr((string) $request->ip(), 0, 45),
            'created_at' => now(),
        ]);

        return response()->json([
            'message' => $names.' transferred to '.$toCentre->name.', effective '.$effFmt.'.',
            'children_moved' => count($movingIds),
            'from_centre' => $fromCentre->name,
            'to_centre' => $toCentre->name,
            'effective_date' => $effective,
            'places_used' => $occupied + count($movingIds),
            'capacity' => (int) ($toRoom->capacity ?: 0),
            // Where each child actually landed — siblings may be in different rooms.
            'placements' => DB::table('children as ch')->whereIn('ch.id', array_keys($placements))
                ->get()->map(fn ($c) => [
                    'child_id' => (int) $c->id,
                    'name' => trim(($c->preferred_name ?: $c->first_name).' '.($c->last_name ?? '')),
                    'room_id' => $placements[(int) $c->id] ?? null,
                    'room_name' => DB::table('rooms')->where('id', $placements[(int) $c->id] ?? 0)->value('name'),
                ])->values(),
        ]);
    }

    /**
     * GET  /admin/families/{family}/transfer-targets
     * POST /admin/families/{family}/transfer   {to_room_id, effective_date, reason}
     *
     * The family-level face of transferChild(). A transfer moves a whole family — a family
     * belongs to one provider and siblings cannot be split — so "move this family" is the
     * operation an admin actually performs, and the families list is where they perform it.
     * The admin UI has no child id to hand, only a family, which is exactly right.
     *
     * Both delegate to the child endpoints rather than repeating them, so the capacity
     * check, cross-agency refusal, sibling room-name matching, notifications and parent
     * email all happen here unchanged.
     */
    public function familyTransferTargets(Request $request, int $familyId): JsonResponse
    {
        $childId = $this->firstEnrolledChild($familyId);
        if (! $childId) {
            return response()->json(['data' => [], 'party_size' => 0, 'siblings' => 0,
                'message' => 'This family has no enrolled children to move.'], 200);
        }

        return $this->transferTargets($request, $childId);
    }

    public function familyTransfer(Request $request, int $familyId): JsonResponse
    {
        $childId = $this->firstEnrolledChild($familyId);
        if (! $childId) {
            return response()->json(['message' => 'This family has no enrolled children to move.'], 422);
        }

        // move_siblings is forced: at family level there is no other sensible reading.
        $request->merge(['move_siblings' => true]);

        return $this->transferChild($request, $childId);
    }

    /** The lowest-id enrolled child, used only as the handle transferChild() expects. */
    private function firstEnrolledChild(int $familyId): ?int
    {
        $id = DB::table('children')->where('family_id', $familyId)
            ->where('enrollment_status', 'enrolled')->whereNull('deleted_at')
            ->orderBy('id')->value('id');

        return $id ? (int) $id : null;
    }

    /**
     * The parent's letter about a change of provider.
     *
     * Public and parameterised rather than inlined, so a sample can be rendered for
     * review without performing a transfer to see what the email says.
     *
     * Tone: a parent reading this is not interested in our data model. They want to know
     * who has their child, from when, and whether anything they rely on is changing. It
     * answers those three in that order and then stops.
     */
    public function mailTransfer(
        int $agencyId, array $addresses, string $childNames,
        string $fromCentre, string $toCentre, string $effectiveLabel, ?string $reason = null
    ): int {
        $addresses = array_values(array_unique(array_filter($addresses,
            fn ($a) => filter_var((string) $a, FILTER_VALIDATE_EMAIL))));
        if (! $addresses) {
            return 0;
        }
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $body = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">'
            .'<tr><td style="font-size:15px;line-height:1.6;color:#334155;padding:0 0 14px;">'
            .'We are writing to let you know that <strong>'.$e($childNames).'</strong> will be moving '
            .'to a new provider. Everything about their place with us continues — this is a change of '
            .'who is caring for them, not a change to their enrolment.</td></tr>'
            .'<tr><td style="padding:6px 0;"><div style="background:#E4EEF2;border-radius:10px;padding:14px 16px;">'
            .'<strong style="color:#1F6080;text-transform:uppercase;font-size:12px;letter-spacing:.06em;">'
            .'From this date</strong><br><span style="font-size:16px;color:#0F172A;">'.$e($effectiveLabel).'</span>'
            .'</div></td></tr>'
            .'<tr><td style="padding:6px 0;"><div style="background:#F1F5F9;border-radius:10px;padding:14px 16px;'
            .'font-size:15px;color:#0F172A;">'
            .'<span style="color:#64748B;">Moving from</span><br><strong>'.$e($fromCentre).'</strong>'
            .'<div style="height:8px;"></div>'
            .'<span style="color:#64748B;">Moving to</span><br><strong>'.$e($toCentre).'</strong>'
            .'</div></td></tr>'
            .($reason ? '<tr><td style="padding:14px 0 0;font-size:15px;line-height:1.6;color:#334155;">'
                .$e($reason).'</td></tr>' : '')
            .'<tr><td style="padding:16px 0 0;font-size:15px;line-height:1.6;color:#334155;">'
            .'Your daily updates, photos, messages and invoices all carry on in the same place — you do not '
            .'need to do anything, and you do not need a new login. Their records move with them.</td></tr>'
            .'<tr><td style="padding:14px 0 0;font-size:15px;line-height:1.6;color:#334155;">'
            .'We know a change of provider is a big thing to hand a family, and we would rather you heard it '
            .'from us early than noticed it on the app. If you have any questions at all, just reply to this '
            .'email.</td></tr></table>';

        $subject = 'A change of provider for '.$childNames.' — from '.$effectiveLabel;

        // The director and agency admin get a blind copy — once for the batch, not once
        // per parent. See MailOversight for why.
        $bcc = \App\Support\MailOversight::bccFor($agencyId, null, $addresses);

        $sent = 0;
        foreach (array_values($addresses) as $i => $addr) {
            try {
                $html = \App\Services\EmailTemplate::wrap($agencyId, $body, [
                    'eyebrow' => 'CHANGE OF PROVIDER',
                    'title' => 'A change of provider for '.$childNames,
                    'subtitle' => $toCentre,
                ]);
                $copyTo = \App\Support\MailOversight::firstOnly($bcc, $i);
                \Illuminate\Support\Facades\Mail::html($html, function ($m) use ($addr, $subject, $copyTo) {
                    $m->to($addr)->subject($subject);
                    if ($copyTo) {
                        $m->bcc($copyTo);
                    }
                    // Who is caring for your child is operational, not marketing.
                    $m->getHeaders()->addTextHeader('X-KT-Bypass-Suppression', '1');
                });
                $sent++;
            } catch (\Throwable $ex) {
                Log::warning('Transfer email failed', ['to' => $addr, 'error' => $ex->getMessage()]);
            }
        }

        return $sent;
    }

    /**
     * Where can this child be moved to? Every other provider in the agency, with the
     * room that receives them and how much space is left — so the UI can grey out a
     * provider who is full instead of letting the transfer fail on submit.
     */
    public function transferTargets(Request $request, int $childId): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $this->assertChild($userId, $childId);

        $child = DB::table('children')->where('id', $childId)->first();
        $family = $child ? DB::table('families')->where('id', $child->family_id)->first() : null;
        if (! $family) {
            return response()->json(['data' => []]);
        }
        $agencyId = (int) DB::table('centres')->where('id', $family->centre_id)->value('agency_id');

        $siblings = DB::table('children as ch')->join('enrollments as e', 'e.child_id', '=', 'ch.id')
            ->where('ch.family_id', $family->id)->where('ch.id', '!=', $childId)
            ->where('ch.enrollment_status', 'enrolled')->whereNull('ch.deleted_at')
            ->whereNull('e.end_date')->distinct()->count('ch.id');
        $party = 1 + $siblings;

        $out = [];
        foreach (DB::table('rooms as r')->join('centres as c', 'c.id', '=', 'r.centre_id')
            ->where('c.agency_id', $agencyId)->whereNull('c.deleted_at')
            ->select('r.id as room_id', 'r.name as room_name', 'r.capacity',
                'c.id as centre_id', 'c.name as centre_name')
            ->orderBy('c.name')->get() as $r) {

            if (! $this->mayAccessCentre($userId, (int) $r->centre_id)) {
                continue;
            }
            $occupied = DB::table('enrollments')
                ->join('children as ch', 'ch.id', '=', 'enrollments.child_id')
                ->where('enrollments.room_id', $r->room_id)->whereNull('enrollments.end_date')
                ->where('ch.enrollment_status', 'enrolled')->whereNull('ch.deleted_at')->count();

            $cap = (int) ($r->capacity ?: 0);
            $out[] = [
                'centre_id' => (int) $r->centre_id,
                'centre_name' => $r->centre_name,
                'room_id' => (int) $r->room_id,
                'room_name' => $r->room_name,
                'capacity' => $cap,
                'occupied' => $occupied,
                'places_left' => max(0, $cap - $occupied),
                'is_current' => (int) $r->centre_id === (int) $family->centre_id,
                'can_take_party' => $cap === 0 ? true : ($occupied + $party) <= $cap,
            ];
        }

        return response()->json(['data' => $out, 'party_size' => $party, 'siblings' => $siblings]);
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
            /* MUST match children.enrollment_status, which is
               enum('waitlist','enrolled','withdrawn','graduated'). This read
               "...,inactive" - a value the column has never had, so setting it passed
               validation and then died at the write - and omitted 'graduated', which the
               column DOES have, so it could never be set at all. Third instance of this
               shape found on 2026-08-25. (2026-08-25) */
            "enrollment_status" => ["sometimes", "in:waitlist,enrolled,withdrawn,graduated"],

            /* The times this child is EXPECTED, as opposed to when they actually arrived.
               Nothing modelled this before: enrollments.schedule holds only the DAYS
               (["mon","tue"...]) and lateness was judged against the centre's own
               open_time/close_time, which says nothing about a child who is due at 08:00
               and collected at 15:30. Held on the child rather than the enrolment so the
               times survive a move between providers. */
            "expected_dropoff_time" => ["sometimes", "nullable", "date_format:H:i"],
            "expected_pickup_time" => ["sometimes", "nullable", "date_format:H:i"],
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
        // A specific DAY when asked for one, otherwise a trailing window. The record
        // needs both: "what happened today" and "what happened on the 3rd".
        $on = (string) $request->query('date', '');
        if ($on !== '') {
            try {
                $since = \Illuminate\Support\Carbon::parse($on)->startOfDay();
                $until = \Illuminate\Support\Carbon::parse($on)->endOfDay();
            } catch (\Throwable $e) { $on = ''; }
        }
        if ($on === '') {
            $days = max(1, min(90, (int) $request->query('days', 14)));
            $since = now()->subDays($days)->startOfDay();
            $until = now()->endOfDay();
        }

        // Recorder names, so the record says WHO logged each moment rather than
        // leaving an educator's work anonymous on the child's own file.
        $withWho = function ($q, string $table) {
            return $q->leftJoin('users as ru', 'ru.id', '=', $table . '.recorded_by_id')
                ->selectRaw($table . ".*, TRIM(CONCAT(COALESCE(ru.first_name,''),' ',COALESCE(ru.last_name,''))) as recorded_by_name");
        };

        $events = $withWho(DB::table('daily_events')->where('daily_events.child_id', $childId)
            ->whereBetween('daily_events.occurred_at', [$since, $until]), 'daily_events')
            ->orderByDesc('daily_events.occurred_at')->limit(200)->get();
        $checks = $withWho(DB::table('check_events')->where('check_events.child_id', $childId)
            ->whereBetween('check_events.occurred_at', [$since, $until]), 'check_events')
            ->orderByDesc('check_events.occurred_at')->limit(200)->get();

        // The OTHER care table. The care screen writes daily_care_logs while the
        // roster quick-log writes daily_events; reading only the second meant a
        // provider's logged moments never appeared on the child's own record — the
        // same split that under-counted the educator summary.
        $careLogs = \Illuminate\Support\Facades\Schema::hasTable('daily_care_logs')
            ? $withWho(DB::table('daily_care_logs')->where('daily_care_logs.child_id', $childId)
                ->whereBetween('daily_care_logs.occurred_at', [$since, $until]), 'daily_care_logs')
                ->orderByDesc('daily_care_logs.occurred_at')->limit(200)->get()
            : collect();
        return response()->json(['events' => $events, 'checks' => $checks,
            'care_logs' => $careLogs]);
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
        $docs = DB::table('documents as d')
            ->leftJoin('users as u', 'u.id', '=', 'd.uploaded_by_id')
            ->where('d.scope_type', 'child')->where('d.scope_id', $childId)
            ->orderByDesc('d.created_at')
            ->get(['d.id', 'd.title', 'd.category', 'd.file_url', 'd.file_type', 'd.file_size',
                   'd.created_at', 'd.expires_at',
                   DB::raw("TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) as uploaded_by")]);
        return response()->json(['documents' => $docs]);
    }

    /**
     * Attach a file to a child's record.
     *
     * Deliberately a copy of AdminController::uploadUserDocument rather than a shared
     * helper: the two differ in who may call them and where the bytes land, and the only
     * genuinely shared part is four lines of Storage call. The route group is already
     * role:centre_director,agency_admin so a parent cannot reach this at all; assertChild
     * (via authorizeChild) is what stops a director reaching into another agency.
     */
    public function uploadDocument(Request $request, int $childId): JsonResponse
    {
        $child = DB::table('children')->where('id', $childId)->whereNull('deleted_at')->first();
        if (! $child) return response()->json(['message' => 'Not found'], 404);
        $this->authorizeChild($request->user(), $child);

        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx', 'max:10240'],
            'title' => ['nullable', 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:60'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $file = $request->file('file');
        $ext  = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $name = (string) \Illuminate\Support\Str::uuid() . '.' . $ext;
        // Public disk so the file lands under the /storage symlink (Laravel 11).
        $file->storeAs('child-documents/' . $childId, $name, 'public');
        $publicPath = '/storage/child-documents/' . $childId . '/' . $name;

        $title = trim((string) ($data['title'] ?? '')) ?: ($file->getClientOriginalName() ?: 'Document');
        $id = DB::table('documents')->insertGetId([
            'scope_type'     => 'child',
            'scope_id'       => $childId,
            'category'       => $data['category'] ?? 'file',
            'title'          => mb_substr($title, 0, 200),
            'file_url'       => $publicPath,
            'file_type'      => $file->getClientMimeType() ?: 'application/octet-stream',
            'file_size'      => $file->getSize(),
            'expires_at'     => $data['expires_at'] ?? null,
            'uploaded_by_id' => $request->user()->id,
            'created_at'     => now(),
        ]);

        $this->auditDoc($request->user()->id, 'child.document_uploaded', $childId, ['document_id' => $id, 'title' => $title]);

        return response()->json(['id' => $id, 'file_url' => $publicPath, 'message' => 'File attached']);
    }

    /**
     * Stream the file through the API rather than relying on the raw /storage URL:
     * the mobile WebView cannot always open a storage link, and this keeps the tenant
     * check on the path, so a guessed document id from another agency 404s.
     */
    public function downloadDocument(Request $request, int $childId, int $docId)
    {
        $child = DB::table('children')->where('id', $childId)->whereNull('deleted_at')->first();
        if (! $child) abort(404);
        $this->authorizeChild($request->user(), $child);

        $doc = DB::table('documents')->where('id', $docId)
            ->where('scope_type', 'child')->where('scope_id', $childId)->first();
        if (! $doc) abort(404);
        $rel = ltrim(str_replace('/storage/', '', (string) $doc->file_url), '/');
        $disk = \Illuminate\Support\Facades\Storage::disk('public');
        if ($rel === '' || ! $disk->exists($rel)) abort(404);
        return response()->file($disk->path($rel));
    }

    /** Remove an attached file. Signed agreements are a legal record and can't be deleted. */
    public function deleteDocument(Request $request, int $childId, int $docId): JsonResponse
    {
        $child = DB::table('children')->where('id', $childId)->whereNull('deleted_at')->first();
        if (! $child) return response()->json(['message' => 'Not found'], 404);
        $this->authorizeChild($request->user(), $child);

        $doc = DB::table('documents')->where('id', $docId)
            ->where('scope_type', 'child')->where('scope_id', $childId)->first();
        if (! $doc) return response()->json(['message' => 'Not found'], 404);
        if (($doc->category ?? '') === 'agreement') {
            return response()->json(['message' => 'Signed agreements are a legal record and cannot be deleted.'], 422);
        }

        try {
            $rel = ltrim(str_replace('/storage/', '', (string) $doc->file_url), '/');
            if ($rel !== '') \Illuminate\Support\Facades\Storage::disk('public')->delete($rel);
        } catch (\Throwable $e) {
            // File may already be gone; the DB row is what matters.
        }
        DB::table('documents')->where('id', $docId)->delete();

        $this->auditDoc($request->user()->id, 'child.document_deleted', $childId, [
            'document_id' => $docId, 'title' => $doc->title ?? null,
        ]);

        return response()->json(['message' => 'File removed']);
    }

    private function auditDoc(int $userId, string $action, int $childId, array $payload = []): void
    {
        try {
            \App\Support\Audit::write([
                'user_id' => $userId,
                'agency_id' => \App\Support\AuditScope::resolve($userId),
                'action' => $action,
                'entity_type' => 'child',
                'entity_id' => $childId,
                'payload' => json_encode($payload),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Auditing must never break the write it is recording.
        }
    }

    /**
     * One implementation, shared across controllers — Concerns\AuthorizesTenantAccess.
     * Verified equivalent to the hand-rolled version across 13,350 real
     * (user, child, active-agency) combinations before the swap.
     */
    private function authorizeChild($user, object $child): void
    {
        $this->assertChild((int) $user->id, (int) $child->id);
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
            /* Carried through formatChildListItem or the screen never sees it. */
            'last_seen_at' => $c->last_seen_at ?? null,
            'room_id' => $c->room_id ?? null,
            'room_name' => $c->room_name ?? null,
            /* A child can be with more than one provider across the week. The list shows
               ONE row per child, so the row has to carry how many — otherwise the only
               way to represent it was a second row, which reads as a duplicate child. */
            'rooms_count' => isset($c->rooms_count) ? (int) $c->rooms_count : null,
            'room_names' => $c->room_names ?? null,
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
