<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Mail\WelcomeEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

final class FamilyController extends Controller
{
    use \App\Http\Controllers\Concerns\AuthorizesTenantAccess;

    use ResolvesCentreContext;

    public function parentDashboard(Request $request): JsonResponse
    {
        return response()->json([
            'user' => [
                'id' => $request->user()->id,
                'first_name' => $request->user()->first_name,
                'last_name' => $request->user()->last_name,
            ],
            'children' => $this->getMyChildren($request->user()),
        ]);
    }

    public function myChildren(Request $request): JsonResponse
    {
        return response()->json([
            'children' => $this->getMyChildren($request->user()),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $centreId = $this->resolveCentreId($request->user());

        if (! $centreId) {
            return response()->json(['data' => []]);
        }

        $families = DB::table('families')
            ->where('centre_id', $centreId)
            ->whereNull('deleted_at')
            ->select('id', 'family_name', 'primary_email', 'primary_phone',
                'address_line1', 'city', 'postal_code', 'billing_split')
            ->orderBy('family_name')
            ->get();

        $familyIds = $families->pluck('id')->all();

        $counts = DB::table('children')
            ->whereIn('family_id', $familyIds)
            ->whereNull('deleted_at')
            ->select('family_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('family_id')
            ->pluck('cnt', 'family_id');

        $primaryGuardians = DB::table('guardians')
            ->join('users', 'users.id', '=', 'guardians.user_id')
            ->whereIn('guardians.family_id', $familyIds)
            ->where('guardians.is_primary', true)
            ->select('guardians.family_id', 'users.email')
            ->pluck('email', 'family_id');

        return response()->json([
            'data' => $families->map(fn ($f) => [
                'id' => $f->id,
                'family_name' => $f->family_name,
                'primary_email' => $f->primary_email ?: ($primaryGuardians[$f->id] ?? null),
                'primary_phone' => $f->primary_phone,
                'address' => trim(($f->address_line1 ?? '').' '.($f->city ?? '').' '.($f->postal_code ?? '')),
                'children_count' => (int) ($counts[$f->id] ?? 0),
                'billing_split' => $f->billing_split,
            ])->all(),
        ]);
    }

    public function show(Request $request, int $familyId): JsonResponse
    {
        $family = DB::table('families')->where('id', $familyId)->whereNull('deleted_at')->first();
        if (! $family) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $this->authorizeFamily($request->user(), $family);

        $guardians = DB::table('guardians')
            ->join('users', 'users.id', '=', 'guardians.user_id')
            ->where('guardians.family_id', $familyId)
            ->select(
                'users.id', 'users.first_name', 'users.last_name', 'users.email', 'users.phone',
                'guardians.relationship', 'guardians.is_primary', 'guardians.can_pickup',
                'guardians.can_receive_billing', 'guardians.billing_share_pct',
            )
            ->get();

        $children = DB::table('children')
            ->where('family_id', $familyId)
            ->whereNull('deleted_at')
            ->orderBy('first_name')
            ->get();

        return response()->json([
            'family' => $family,
            'guardians' => $guardians,
            'children' => $children,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $centreId = $this->resolveCentreId($request->user());

        if (! $centreId) {
            return response()->json(['message' => 'No centre access'], 403);
        }

        $data = $request->validate([
            'family_name' => ['required', 'string', 'max:160'],
            'primary_email' => ['required', 'email', 'max:180'],   // required on create (2026-10-13)
            'primary_phone' => ['nullable', 'string', 'max:40'],
            /* Required on CREATE only. A partial address propagates everywhere
               downstream — iLearn mirrors whatever KiddieTrac holds, so a family
               entered with just a city arrives in iLearn with just a city, and no sync
               change can invent the rest. update() validates separately, so the
               families already carrying a partial address stay editable rather than
               becoming unsaveable. */
            'address_line1' => ['required', 'string', 'max:200'],
            'city' => ['required', 'string', 'max:80'],
            'province' => ['required', 'string', 'max:40'],
            'postal_code' => ['required', 'string', 'max:12'],
            'country' => ['nullable', 'in:CA,US'],
            'preferred_lang' => ['nullable', 'string', 'max:10'],
            'billing_split' => ['nullable', 'in:single,split,custom'],
        ], [
            'address_line1.required' => 'A street address is needed — it flows through to billing and to iLearn.',
            'city.required' => 'Please add the city.',
            'province.required' => 'Please add the province.',
            'postal_code.required' => 'Please add the postal code.',
        ]);

        $id = DB::table('families')->insertGetId([
            ...$data,
            'centre_id' => $centreId,
            'country' => $data['country'] ?? 'CA',
            'preferred_lang' => $data['preferred_lang'] ?? 'en-CA',
            'billing_split' => $data['billing_split'] ?? 'single',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['family_id' => $id, 'message' => 'Family added'], 201);
    }

    public function invite(Request $request, int $familyId): JsonResponse
    {
        $family = DB::table('families')->where('id', $familyId)->first();
        if (! $family) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $this->authorizeFamily($request->user(), $family);

        /* Only the director of the family's centre, or an admin of its agency (2026-11-05 leak sweep):
           authorizeFamily says yes to the family's own parent and to any staff role at the centre, so a parent who
           directs another centre, or a director elsewhere who teaches here, could add guardians to this family. */
        $famAgency = (int) DB::table('centres')->where('id', $family->centre_id)->value('agency_id');
        $callerRoles = DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)
            ->get(['role', 'agency_id', 'centre_id']);
        $runsCentre = $famAgency > 0 && ($callerRoles->contains(fn ($r) => $r->role === 'platform_admin')
            ? (int) $this->resolveAgencyId($request) === $famAgency
            : $callerRoles->contains(fn ($r) => ($r->role === 'agency_admin' && (int) $r->agency_id === $famAgency)
                || ($r->role === 'centre_director' && (int) $r->centre_id === (int) $family->centre_id)));
        if (! $runsCentre) {
            return response()->json(['message' => 'Only the director of this family\'s centre or an agency admin can add a guardian.'], 403);
        }

        $data = $request->validate([
            'email' => ['required', 'email', 'max:180'],
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'relationship' => ['required', 'in:mother,father,guardian,grandparent,foster,other'],
            'is_primary' => ['boolean'],
            'can_pickup' => ['boolean'],
            'can_receive_billing' => ['boolean'],
            'billing_share_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'send_email' => ['boolean'],
        ]);

        $existingUser = DB::table('users')->where('email', $data['email'])->first();
        $tempPassword = null;
        $isNewUser = false;

        if ($existingUser) {
            $userId = (int) $existingUser->id;
            /* Never yourself (2026-11-05 leak sweep): inviting your own email made you a guardian of the family,
               with its children, invoices and messages. */
            if ($userId === (int) $request->user()->id) {
                return response()->json(['message' => 'You cannot add yourself as a guardian of a family.'], 422);
            }
        } else {
            $isNewUser = true;
            $tempPassword = Str::random(12);
            $userId = (int) DB::table('users')->insertGetId([
                'email' => $data['email'],
                'password' => Hash::make($tempPassword),
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'locale' => 'en-CA',
                'timezone' => 'America/Toronto',
                'status' => 'invited',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('guardians')->updateOrInsert(
            ['family_id' => $familyId, 'user_id' => $userId],
            [
                'relationship' => $data['relationship'],
                'is_primary' => $data['is_primary'] ?? false,
                'can_pickup' => $data['can_pickup'] ?? true,
                'can_receive_billing' => $data['can_receive_billing'] ?? false,
                'billing_share_pct' => $data['billing_share_pct'] ?? 0,
                'created_at' => now(),
            ]
        );

        $centre = DB::table('centres')->where('id', $family->centre_id)->first();
        DB::table('role_assignments')->updateOrInsert(
            ['user_id' => $userId, 'role' => 'guardian', 'agency_id' => $centre->agency_id, 'centre_id' => null],
            ['active' => true, 'created_at' => now()]
        );

        // Send welcome email
        $emailSent = false;
        if ($isNewUser && ($data['send_email'] ?? true)) {
            try {
                $childNames = DB::table('children')
                    ->where('family_id', $familyId)
                    ->whereNull('deleted_at')
                    ->pluck('first_name')
                    ->implode(', ');

                Mail::to($data['email'])->send((new WelcomeEmail(
                    recipientName: $data['first_name'],
                    recipientEmail: $data['email'],
                    tempPassword: $tempPassword,
                    centreName: $centre->name,
                    role: 'parent',
                    childNames: $childNames ?: null,
                ))->withSymfonyMessage(function ($msg) use ($centre) {
                    \App\Support\MailScope::centre($msg, (int) $centre->id);
                }));
                $emailSent = true;
            } catch (Throwable $e) {
                Log::warning('Welcome email failed', ['error' => $e->getMessage(), 'recipient' => $data['email']]);
            }

            /* And the introduction to their provider, as a second email.

               The welcome email is credentials and a link — necessary, and cold. It
               tells a family how to sign in and nothing about who is going to be
               looking after their child. The provider welcome is the part that
               answers that, so a family joining should get both.

               Sent to every guardian on the family, with the care team on BCC, and
               in its own try: a failure here must not cost the parent the account
               details they actually need to get in. */
            try {
                $guardians = DB::table('guardians as g')
                    ->join('users as u', 'u.id', '=', 'g.user_id')
                    ->where('g.family_id', $familyId)
                    ->whereNotNull('u.email')
                    ->get(['u.email', 'u.first_name'])
                    ->map(fn ($g) => ['email' => $g->email, 'first_name' => $g->first_name])
                    ->all();

                $childFirstNames = DB::table('children')
                    ->where('family_id', $familyId)
                    ->whereNull('deleted_at')
                    ->pluck('first_name')->filter()->values()->all();

                \App\Support\ProviderWelcomeMailer::sendToFamily(
                    (int) $family->centre_id,
                    (int) $centre->agency_id,
                    $guardians,
                    $childFirstNames
                );
            } catch (Throwable $e) {
                Log::warning('Provider welcome after invite failed', [
                    'error' => $e->getMessage(), 'family' => $familyId,
                ]);
            }
        }

        return response()->json([
            'message' => $existingUser ? 'Existing user added to family' : 'New parent invited',
            'user_id' => $userId,
            'temp_password' => $tempPassword,
            'email_sent' => $emailSent,
        ], 201);
    }

    /**
     * One implementation, shared with every other controller — see
     * Concerns\AuthorizesTenantAccess. Verified equivalent to the hand-rolled
     * version it replaces across 13,320 real (user, family, active-agency)
     * combinations before the swap.
     *
     * The name and signature are unchanged so both call sites stay as they are.
     */
    private function authorizeFamily($user, object $family): void
    {
        $this->assertFamily((int) $user->id, (int) $family->id);
    }

    private function getMyChildren($user): array
    {
        $familyIds = DB::table('guardians')->where('user_id', $user->id)->pluck('family_id')->all();
        if (empty($familyIds)) {
            return [];
        }

        $children = DB::table('children')
            ->whereIn('family_id', $familyIds)
            ->whereNull('deleted_at')
            ->orderBy('first_name')
            ->get();

        $childIds = $children->pluck('id')->all();

        $enrollments = DB::table('enrollments')
            ->whereIn('child_id', $childIds)
            ->whereNull('end_date')
            ->leftJoin('rooms', 'rooms.id', '=', 'enrollments.room_id')
            ->select('enrollments.*', 'rooms.name as room_name', 'rooms.color_hex as room_color', 'rooms.age_group', 'rooms.centre_id as centre_id')
            ->get()
            ->keyBy('child_id');

        /* Agency day as instants. The centre comes from the family's own enrolments;
           a parent with children at one agency gets that agency's midnight. */
        [$dayFrom, $dayTo] = \App\Support\AgencyTime::dayRangeForCentre(
            optional($enrollments->first())->centre_id ? (int) $enrollments->first()->centre_id : null
        );
        $checkEvents = DB::table('check_events')
            ->whereIn('child_id', $childIds)
            ->where('occurred_at', '>=', $dayFrom)->where('occurred_at', '<', $dayTo)
            ->orderByDesc('occurred_at')
            ->get()
            ->groupBy('child_id');

        // Centre + agency names, and the room's educators — for the "your team"
        // card on the parent home/Today screen.
        $roomIds = $enrollments->pluck('room_id')->filter()->unique()->values()->all();
        $centreIds = $enrollments->pluck('centre_id')->filter()->unique()->values()->all();
        $centres = empty($centreIds) ? collect() : DB::table('centres')->whereIn('id', $centreIds)->get()->keyBy('id');
        $agencyIds = $centres->pluck('agency_id')->filter()->unique()->values()->all();
        $agencies = empty($agencyIds) ? collect() : DB::table('agencies')->whereIn('id', $agencyIds)->get()->keyBy('id');
        /* The home's own team only (App\Support\CentreTeam::forRooms, 2026-10-21): reading
           educator_rooms directly showed families the agency's office account and both
           home-visitor accounts as "your educators" in every iLearn home. */
        $educatorsByRoom = collect(\App\Support\CentreTeam::forRooms($roomIds))
            ->map(fn ($members) => collect(array_values($members))->map(fn ($m) => (object) $m));

        return $children->map(function ($c) use ($enrollments, $checkEvents, $centres, $agencies, $educatorsByRoom) {
            $enrollment = $enrollments[$c->id] ?? null;
            $check = $checkEvents->get($c->id, collect())->first();
            $isAtCentre = $check && $check->event_type === 'check_in';

            $centre = ($enrollment && $enrollment->centre_id) ? ($centres[$enrollment->centre_id] ?? null) : null;
            $agency = ($centre && $centre->agency_id) ? ($agencies[$centre->agency_id] ?? null) : null;
            $eds = ($enrollment && $enrollment->room_id) ? ($educatorsByRoom[$enrollment->room_id] ?? collect()) : collect();
            $educatorList = collect($eds)->map(function ($e) {
                return [
                    // Parents see the FULL name of their own child's provider/educator
                    // (not a "Sarah M." initial — that privacy-trim is only for the
                    // PUBLIC marketing map, never a family's own assigned team).
                    'name' => trim(($e->preferred_name ?: $e->first_name ?: '').' '.($e->last_name ?: '')),
                    'photo_url' => $e->photo_url ?? null,
                ];
            })->filter(fn ($e) => $e['name'] !== '')->values()->all();

            return [
                'id' => $c->id,
                'first_name' => $c->first_name,
                'last_name' => $c->last_name,
                'preferred_name' => $c->preferred_name,
                'display_name' => $c->preferred_name ?: $c->first_name,
                'date_of_birth' => $c->date_of_birth,
                'age' => $this->formatAge($c->date_of_birth),
                'photo_url' => $c->photo_url,
                'room_id' => $enrollment?->room_id,
                'room_name' => $enrollment?->room_name,
                'room_color' => $enrollment?->room_color,
                'centre_id' => $enrollment?->centre_id,
                'centre_name' => $centre?->name,
                'centre_address' => $centre ? trim(implode(', ', array_filter([
                    trim(($centre->address_line1 ?? '').' '.($centre->address_line2 ?? '')),
                    $centre->city ?? null,
                    trim(($centre->province ?? '').' '.($centre->postal_code ?? '')),
                ]))) : null,
                'centre_phone' => $centre->phone ?? null,
                'centre_supervisor' => $centre ? trim(($centre->supervisor_first_name ?? '').' '.($centre->supervisor_last_name ?? '')) : null,
                'agency_name' => $agency?->name,
                'educators' => $educatorList,
                'enrollment_start' => $enrollment?->start_date,
                'is_at_centre' => $isAtCentre,
                'arrived_at' => $isAtCentre ? \App\Support\AgencyTime::fmt($check->occurred_at, \App\Support\AgencyTime::tzForCentre($enrollment?->centre_id)) : null,
            ];
        })->all();
    }

    private function formatAge(?string $dob): array
    {
        if (! $dob) {
            return ['human' => '—', 'total_months' => 0];
        }

        $months = (int) Carbon::parse($dob)->diffInMonths(now());
        $years = intdiv($months, 12);
        $m = $months % 12;

        return [
            'years' => $years,
            'months' => $m,
            'total_months' => $months,
            'human' => $years > 0 ? "{$years}y {$m}m" : "{$months} months",
        ];
    }
}
