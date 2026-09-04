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

final class StaffController extends Controller
{
    use ResolvesCentreContext;

    public function index(Request $request): JsonResponse
    {
        $centreId = $this->resolveCentreId($request->user());

        if (! $centreId) {
            return response()->json(['staff' => []]);
        }

        $staff = DB::table('users')
            ->join('role_assignments', 'role_assignments.user_id', '=', 'users.id')
            ->where('role_assignments.centre_id', $centreId)
            ->where('role_assignments.active', true)
            ->whereIn('role_assignments.role', ['educator', 'centre_director', 'agency_admin'])
            ->select(
                'users.id', 'users.first_name', 'users.last_name', 'users.email',
                'users.status', 'users.photo_url', 'users.is_contractor',
                'role_assignments.role',
            )
            ->distinct()
            ->orderBy('users.first_name')
            ->get();

        $userIds = $staff->pluck('id')->all();

        $certifications = DB::table('staff_certifications')
            ->whereIn('user_id', $userIds)
            ->where('active', true)
            ->select('user_id', 'cert_type', 'certifier', 'issued_at', 'expires_at')
            ->get()
            ->groupBy('user_id');

        return response()->json([
            'staff' => $staff->map(fn ($s) => [
                'id' => $s->id,
                'first_name' => $s->first_name,
                'last_name' => $s->last_name,
                'email' => $s->email,
                'role' => $s->role,
                'status' => $s->status ?? 'active',
                'photo_url' => $s->photo_url,
                'is_contractor' => (bool) ($s->is_contractor ?? false),
                'certifications' => ($certifications->get($s->id, collect()))->map(fn ($c) => [
                    'cert_type' => $c->cert_type,
                    'certifier' => $c->certifier,
                    'issued_at' => $c->issued_at,
                    'expires_at' => $c->expires_at,
                    'expires_soon' => $c->expires_at && Carbon::parse($c->expires_at)->lt(now()->addDays(60)),
                ])->values(),
            ])->all(),
        ]);
    }

    public function invite(Request $request): JsonResponse
    {
        $centreId = $this->resolveCentreId($request->user());

        if (! $centreId) {
            return response()->json(['message' => 'No centre access'], 403);
        }

        $data = $request->validate([
            'email' => ['required', 'email', 'max:180'],
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'role' => ['required', 'in:educator,centre_director'],
            'send_email' => ['boolean'],
        ]);

        $existing = DB::table('users')->where('email', $data['email'])->first();
        $tempPassword = null;
        $isNewUser = false;

        if ($existing) {
            $userId = (int) $existing->id;
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

        $centre = DB::table('centres')->where('id', $centreId)->first();
        DB::table('role_assignments')->updateOrInsert(
            ['user_id' => $userId, 'role' => $data['role'], 'centre_id' => $centreId, 'agency_id' => $centre->agency_id],
            ['active' => true, 'created_at' => now()]
        );

        // Send welcome email
        $emailSent = false;
        if ($isNewUser && ($data['send_email'] ?? true)) {
            try {
                Mail::to($data['email'])->send(new WelcomeEmail(
                    recipientName: $data['first_name'],
                    recipientEmail: $data['email'],
                    tempPassword: $tempPassword,
                    centreName: $centre->name,
                    role: $data['role'] === 'centre_director' ? 'director' : 'educator',
                ));
                $emailSent = true;
            } catch (Throwable $e) {
                Log::warning('Welcome email failed', ['error' => $e->getMessage(), 'recipient' => $data['email']]);
            }
        }

        return response()->json([
            'message' => $existing ? 'Existing user added to centre' : 'New user invited',
            'user_id' => $userId,
            'temp_password' => $tempPassword,
            'email_sent' => $emailSent,
        ], 201);
    }

    /**
     * PATCH /staff/{user}/contractor — mark somebody a contractor, or stop.
     *
     * Only meaningful as an announcement audience today: there is no role that means
     * "contractor" and no employment field to infer it from, so it is stated explicitly
     * per person rather than guessed.
     *
     * Scoped like everything else here — you can only change staff at a centre you
     * administer, so an admin at one agency can never touch another agency's people.
     */
    public function setContractor(Request $request, int $userId): JsonResponse
    {
        $data = $request->validate(['is_contractor' => ['required', 'boolean']]);

        $centreId = $this->resolveCentreId($request->user());
        abort_unless($centreId, 403, 'No centre for this account.');

        $inScope = DB::table('role_assignments')
            ->where('user_id', $userId)
            ->where('centre_id', $centreId)
            ->where('active', true)
            ->exists();
        abort_unless($inScope, 403, 'That person is not staff at your centre.');

        DB::table('users')->where('id', $userId)
            ->update(['is_contractor' => $data['is_contractor'] ? 1 : 0]);

        \App\Support\Audit::write([
            'user_id' => $request->user()->id,
            'action' => 'staff.contractor_flag',
            'entity_type' => 'user',
            'entity_id' => $userId,
            'payload' => json_encode(['is_contractor' => (bool) $data['is_contractor']]),
            'created_at' => now(),
        ]);

        return response()->json(['ok' => true, 'is_contractor' => (bool) $data['is_contractor']]);
    }

    public function certifications(Request $request, int $userId): JsonResponse
    {
        return response()->json([
            'certifications' => DB::table('staff_certifications')
                ->where('user_id', $userId)
                ->orderByDesc('issued_at')
                ->get(),
        ]);
    }

    public function addCertification(Request $request, int $userId): JsonResponse
    {
        $data = $request->validate([
            'cert_type' => ['required', 'in:RECE,First_Aid,CPR,Vulnerable_Sector_Check,Health_Card,Other'],
            'certifier' => ['nullable', 'string', 'max:120'],
            'issued_at' => ['required', 'date'],
            'expires_at' => ['nullable', 'date', 'after:issued_at'],
            'document_url' => ['nullable', 'url'],
        ]);

        $id = DB::table('staff_certifications')->insertGetId([
            ...$data,
            'user_id' => $userId,
            'active' => true,
            'created_at' => now(),
        ]);

        return response()->json(['certification_id' => $id], 201);
    }

    public function updateCertification(Request $request, int $id): JsonResponse
    {
        $cert = DB::table('staff_certifications')->where('id', $id)->first();
        if (! $cert) {
            return response()->json(['message' => 'Not found'], 404);
        }
        // NOTE: staff_certifications has no updated_at column — do not set it.
        $data = $request->validate([
            'cert_type'    => ['sometimes', 'in:RECE,First_Aid,CPR,Vulnerable_Sector_Check,Health_Card,Other'],
            'certifier'    => ['nullable', 'string', 'max:120'],
            'issued_at'    => ['sometimes', 'date'],
            'expires_at'   => ['nullable', 'date'],
            'document_url' => ['nullable', 'url'],
        ]);
        if (! empty($data)) {
            DB::table('staff_certifications')->where('id', $id)->update($data);
        }
        return response()->json(['ok' => true]);
    }

    public function deleteCertification(Request $request, int $id): JsonResponse
    {
        $cert = DB::table('staff_certifications')->where('id', $id)->first();
        if (! $cert) {
            return response()->json(['message' => 'Not found'], 404);
        }
        DB::table('staff_certifications')->where('id', $id)->delete();
        return response()->json(['ok' => true]);
    }

    public function schedule(Request $request): JsonResponse
    {
        $centreId = $this->resolveCentreId($request->user());

        if (! $centreId) {
            return response()->json(['shifts' => []]);
        }

        $from = $request->input('from', now()->startOfWeek()->toDateString());
        $to = $request->input('to', now()->endOfWeek()->toDateString());

        // shifts table has room_id, not centre_id — scope via rooms in this centre
        $roomIds = DB::table('rooms')->where('centre_id', $centreId)->pluck('id')->all();
        $shifts = empty($roomIds) ? collect() : DB::table('shifts')
            ->join('users', 'users.id', '=', 'shifts.user_id')
            ->whereIn('shifts.room_id', $roomIds)
            ->whereBetween('shifts.starts_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->select('shifts.*', 'users.first_name', 'users.last_name')
            ->orderBy('shifts.starts_at')
            ->get();

        return response()->json(['shifts' => $shifts]);
    }

    public function createShift(Request $request): JsonResponse
    {
        $centreId = $this->resolveCentreId($request->user());

        if (! $centreId) {
            return response()->json(['message' => 'No centre access'], 403);
        }

        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'room_id' => ['nullable', 'integer'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'notes' => ['nullable', 'string'],
        ]);

        $id = DB::table('shifts')->insertGetId([
            ...$data,
            'centre_id' => $centreId,
            'status' => 'scheduled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['shift_id' => $id], 201);
    }

    public function clockIn(Request $request): JsonResponse
    {
        $user = $request->user();

        // v22p97: resolve the centre WITHIN the active agency (header-aware) so a
        // multi-agency user / super-admin clocks in at the agency they've switched
        // into, rather than failing with "no centre assignment".
        $centreId = $this->resolveCentreId($user);
        if (! $centreId) {
            return response()->json(['message' => 'No centre assignment'], 422);
        }

        // The centre is shut — there is no shift to start. Checked before the
        // already-clocked-in test so somebody who forgot to clock out yesterday gets told
        // about the closure rather than a confusing "already clocked in".
        if ($closure = \App\Support\Closures::forDate($centreId)) {
            return response()->json([
                'message' => 'The centre is closed today (' . \App\Support\Closures::reason($closure)
                    . '), so there is no shift to clock into. Enjoy the day.',
                'closed' => true,
                'closure' => [
                    'dates' => \App\Support\Closures::dateLabel($closure),
                    'reason' => \App\Support\Closures::reason($closure),
                ],
            ], 422);
        }

        $open = DB::table('time_entries')
            ->where('user_id', $user->id)
            ->whereNull('clocked_out_at')
            ->first();

        if ($open) {
            return response()->json([
                'message' => 'Already clocked in at '.\App\Support\AgencyTime::fmt($open->clocked_in_at, \App\Support\AgencyTime::tzForCentre($open->centre_id ?? null)),
                'time_entry_id' => $open->id,
            ], 422);
        }

        $id = DB::table('time_entries')->insertGetId([
            'user_id' => $user->id,
            'centre_id' => $centreId,
            'clocked_in_at' => now(),
            'created_at' => now(),
        ]);

        return response()->json([
            'time_entry_id' => $id,
            'clocked_in_at' => now()->toIso8601String(),
        ], 201);
    }

    public function clockOut(Request $request): JsonResponse
    {
        $user = $request->user();

        $open = DB::table('time_entries')
            ->where('user_id', $user->id)
            ->whereNull('clocked_out_at')
            ->orderByDesc('clocked_in_at')
            ->first();

        if (! $open) {
            return response()->json(['message' => 'Not currently clocked in'], 422);
        }

        DB::table('time_entries')->where('id', $open->id)->update([
            'clocked_out_at' => now(),
            'duration_minutes' => (int) (now()->diffInSeconds(Carbon::parse($open->clocked_in_at)) / 60),
            'updated_at' => now(),
        ]);

        return response()->json([
            'time_entry_id' => $open->id,
            'clocked_out_at' => now()->toIso8601String(),
        ]);
    }
}
