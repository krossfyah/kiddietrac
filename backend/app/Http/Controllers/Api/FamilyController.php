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
            'primary_email' => ['nullable', 'email', 'max:180'],
            'primary_phone' => ['nullable', 'string', 'max:40'],
            'address_line1' => ['nullable', 'string', 'max:200'],
            'city' => ['nullable', 'string', 'max:80'],
            'province' => ['nullable', 'string', 'max:40'],
            'postal_code' => ['nullable', 'string', 'max:12'],
            'preferred_lang' => ['nullable', 'string', 'max:10'],
            'billing_split' => ['nullable', 'in:single,split,custom'],
        ]);

        $id = DB::table('families')->insertGetId([
            ...$data,
            'centre_id' => $centreId,
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

                Mail::to($data['email'])->send(new WelcomeEmail(
                    recipientName: $data['first_name'],
                    recipientEmail: $data['email'],
                    tempPassword: $tempPassword,
                    centreName: $centre->name,
                    role: 'parent',
                    childNames: $childNames ?: null,
                ));
                $emailSent = true;
            } catch (Throwable $e) {
                Log::warning('Welcome email failed', ['error' => $e->getMessage(), 'recipient' => $data['email']]);
            }
        }

        return response()->json([
            'message' => $existingUser ? 'Existing user added to family' : 'New parent invited',
            'user_id' => $userId,
            'temp_password' => $tempPassword,
            'email_sent' => $emailSent,
        ], 201);
    }

    private function authorizeFamily($user, object $family): void
    {
        if ($this->authorizeCentreAccess($user, (int) $family->centre_id)) {
            return;
        }

        if (DB::table('guardians')
            ->where('user_id', $user->id)
            ->where('family_id', $family->id)
            ->exists()) {
            return;
        }

        abort(403);
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
            ->select('enrollments.*', 'rooms.name as room_name', 'rooms.color_hex as room_color', 'rooms.age_group')
            ->get()
            ->keyBy('child_id');

        $today = now()->toDateString();
        $checkEvents = DB::table('check_events')
            ->whereIn('child_id', $childIds)
            ->whereDate('occurred_at', $today)
            ->orderByDesc('occurred_at')
            ->get()
            ->groupBy('child_id');

        return $children->map(function ($c) use ($enrollments, $checkEvents) {
            $enrollment = $enrollments[$c->id] ?? null;
            $check = $checkEvents->get($c->id, collect())->first();
            $isAtCentre = $check && $check->event_type === 'check_in';

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
                'is_at_centre' => $isAtCentre,
                'arrived_at' => $isAtCentre ? Carbon::parse($check->occurred_at)->format('g:i A') : null,
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
