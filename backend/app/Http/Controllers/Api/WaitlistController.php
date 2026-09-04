<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * v13: Waitlist management.
 *
 * Schema (verified):
 *   children.enrollment_status enum('waitlist','enrolled','withdrawn','graduated')
 *   children.applied_at, waitlist_position, expected_start_date, preferred_room_age_group, waitlist_notes (v13)
 *   children.enrolled_at, withdrawn_at, family_id
 *   families.centre_id (waitlist is scoped to a centre via family.centre_id)
 *   enrollments(child_id, room_id, start_date, monthly_fee, ...)
 */
final class WaitlistController extends Controller
{
    /**
     * GET /api/v1/director/waitlist?centre_id=X
     */
    public function index(Request $request): JsonResponse
    {
        $centreId = (int) $request->input('centre_id');
        if (! $this->hasCentreAccess($request->user()->id, $centreId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $rows = DB::table('children')
            ->join('families', 'families.id', '=', 'children.family_id')
            ->where('families.centre_id', $centreId)
            ->where('children.enrollment_status', 'waitlist')
            ->whereNull('children.deleted_at')
            ->whereNull('families.deleted_at')
            ->orderBy('children.waitlist_position')
            ->orderBy('children.applied_at')
            ->select(
                'children.id',
                'children.first_name',
                'children.last_name',
                'children.date_of_birth',
                'children.applied_at',
                'children.waitlist_position',
                'children.expected_start_date',
                'children.preferred_room_age_group',
                'children.waitlist_notes',
                'families.id as family_id',
                'families.family_name',
                'families.primary_phone',
                'families.primary_email'
            )
            ->get();

        // Calculate position if null
        $position = 1;
        $waitlist = $rows->map(function ($r) use (&$position) {
            $age = $r->date_of_birth
                ? Carbon::parse($r->date_of_birth)->diffInMonths(Carbon::now())
                : null;
            return [
                'id' => $r->id,
                'child_name' => trim($r->first_name . ' ' . $r->last_name),
                'date_of_birth' => $r->date_of_birth,
                'age_months' => $age,
                'family_id' => $r->family_id,
                'family_name' => $r->family_name,
                'family_phone' => $r->primary_phone,
                'family_email' => $r->primary_email,
                'applied_at' => $r->applied_at,
                'days_waiting' => $r->applied_at ? Carbon::parse($r->applied_at)->diffInDays(Carbon::now()) : null,
                'position' => $r->waitlist_position ?? $position++,
                'expected_start_date' => $r->expected_start_date,
                'preferred_room_age_group' => $r->preferred_room_age_group,
                'notes' => $r->waitlist_notes,
            ];
        });

        return response()->json([
            'centre_id' => $centreId,
            'total_waitlisted' => $waitlist->count(),
            'waitlist' => $waitlist->values(),
        ]);
    }

    /**
     * POST /api/v1/director/waitlist/{childId}/promote
     * Promote a waitlisted child to enrolled, assign to room, set start date.
     */
    public function promote(Request $request, int $childId): JsonResponse
    {
        $data = $request->validate([
            'room_id' => ['required', 'integer'],
            'start_date' => ['required', 'date'],
            'monthly_fee' => ['required', 'numeric', 'min:0'],
            'cwelcc_eligible' => ['nullable', 'boolean'],
        ]);

        $child = DB::table('children')->where('id', $childId)->whereNull('deleted_at')->first();
        if (! $child) return response()->json(['message' => 'Child not found'], 404);
        if ($child->enrollment_status !== 'waitlist') return response()->json(['message' => 'Child is not waitlisted'], 422);

        $family = DB::table('families')->where('id', $child->family_id)->first();
        $room = DB::table('rooms')->where('id', $data['room_id'])->first();
        if (! $room) return response()->json(['message' => 'Room not found'], 404);
        if ($room->centre_id != $family->centre_id) {
            return response()->json(['message' => 'Room does not belong to this child\'s centre'], 422);
        }

        if (! $this->hasCentreAccess($request->user()->id, $family->centre_id)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        DB::beginTransaction();
        try {
            DB::table('children')->where('id', $childId)->update([
                'enrollment_status' => 'enrolled',
                'enrolled_at' => $data['start_date'],
                'waitlist_position' => null,
                'updated_at' => now(),
            ]);

            $enrollmentId = DB::table('enrollments')->insertGetId([
                'child_id' => $childId,
                'room_id' => $data['room_id'],
                'start_date' => $data['start_date'],
                'monthly_fee' => $data['monthly_fee'],
                'cwelcc_eligible' => $data['cwelcc_eligible'] ?? true,
                'created_at' => now(),
            ]);

            $this->reindexWaitlist($family->centre_id);

            \App\Support\Audit::write([
                'user_id' => $request->user()->id,
                'action' => 'waitlist.promoted',
                'entity_type' => 'child',
                'entity_id' => $childId,
                'payload' => json_encode([
                    'enrollment_id' => $enrollmentId,
                    'room_id' => $data['room_id'],
                    'start_date' => $data['start_date'],
                    'monthly_fee' => $data['monthly_fee'],
                ]),
                'created_at' => now(),
            ]);

            // Notify parents
            $guardianUserIds = DB::table('guardians')
                ->where('family_id', $child->family_id)
                ->pluck('user_id')
                ->all();
            foreach ($guardianUserIds as $uid) {
                DB::table('notifications')->insert([
                    'user_id' => $uid,
                    'type' => 'waitlist.promoted',
                    'title' => '🎉 Your spot is confirmed!',
                    'body' => trim($child->first_name . ' ' . $child->last_name) . ' is enrolled starting ' . $data['start_date'] . '.',
                    'data' => json_encode(['child_id' => $childId, 'start_date' => $data['start_date']]),
                    'created_at' => now(),
                ]);
            }

            DB::commit();
            return response()->json(['success' => true, 'enrollment_id' => $enrollmentId]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Waitlist promote failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Could not promote: ' . $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/v1/director/waitlist/{childId}/decline
     */
    public function decline(Request $request, int $childId): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $child = DB::table('children')->where('id', $childId)->whereNull('deleted_at')->first();
        if (! $child) return response()->json(['message' => 'Child not found'], 404);
        if ($child->enrollment_status !== 'waitlist') return response()->json(['message' => 'Child is not waitlisted'], 422);

        $family = DB::table('families')->where('id', $child->family_id)->first();
        if (! $this->hasCentreAccess($request->user()->id, $family->centre_id)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        DB::table('children')->where('id', $childId)->update([
            'enrollment_status' => 'withdrawn',
            'withdrawn_at' => now()->toDateString(),
            'waitlist_position' => null,
            'updated_at' => now(),
        ]);

        $this->reindexWaitlist($family->centre_id);

        \App\Support\Audit::write([
            'user_id' => $request->user()->id,
            'action' => 'waitlist.declined',
            'entity_type' => 'child',
            'entity_id' => $childId,
            'payload' => json_encode(['reason' => $data['reason'] ?? null]),
            'created_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }

    /** Email the family a "still on our waitlist" reminder, with an optional note. */
    public function remind(Request $request, int $childId): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        $child = DB::table('children')->where('id', $childId)->whereNull('deleted_at')->first();
        if (! $child) return response()->json(['message' => 'Child not found'], 404);
        if ($child->enrollment_status !== 'waitlist') return response()->json(['message' => 'Child is not waitlisted'], 422);

        $family = DB::table('families')->where('id', $child->family_id)->first();
        if (! $family || ! $this->hasCentreAccess($request->user()->id, $family->centre_id)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $email = $family->primary_email ?? null;
        if (! $email) return response()->json(['message' => 'This family has no email on file.'], 422);

        $agencyId = (int) DB::table('centres')->where('id', $family->centre_id)->value('agency_id');
        $centreName = DB::table('centres')->where('id', $family->centre_id)->value('name');
        $childName = trim(($child->first_name ?? '') . ' ' . ($child->last_name ?? '')) ?: 'your child';
        $note = trim((string) ($data['note'] ?? ''));

        $body = '<p style="margin:0 0 12px;font-size:14.5px;color:#243244;">Hello ' . htmlspecialchars((string) $family->family_name) . ',</p>'
            . '<p style="margin:0 0 12px;font-size:14px;color:#334155;">This is a friendly reminder that <strong>' . htmlspecialchars($childName) . '</strong> is still on the waitlist' . ($centreName ? ' at ' . htmlspecialchars((string) $centreName) : '') . '. We will be in touch as soon as a space becomes available.</p>'
            . ($note !== '' ? '<div style="margin:12px 0;padding:12px 14px;background:#F1F5F9;border-radius:8px;font-size:13.5px;color:#334155;"><strong>A note from us:</strong><br>' . nl2br(htmlspecialchars($note)) . '</div>' : '')
            . '<p style="margin:14px 0 0;font-size:12.5px;color:#64748b;">If your plans have changed, just reply to let us know.</p>';

        $html = \App\Services\EmailTemplate::wrap($agencyId, $body, [
            'eyebrow'   => 'WAITLIST',
            'title'     => 'You are still on our waitlist',
            'subtitle'  => $centreName ?: null,
            'preheader' => $childName . ' is still on the waitlist.',
        ]);

        try {
            $mailer = \App\Services\AgencyMailer::forAgency($agencyId);
            $fromA = $mailer->fromAddress();
            $fromN = $mailer->fromName();
            $mailer->mailer()->html($html, function ($m) use ($email, $family, $fromA, $fromN) {
                $m->to($email, $family->family_name ?? null)->from($fromA, $fromN)->subject('You are still on our waitlist');
            });
        } catch (\Throwable $e) {
            Log::warning('Waitlist reminder failed', ['child' => $childId, 'e' => $e->getMessage()]);
            return response()->json(['message' => 'Could not send the email — please try again.'], 500);
        }

        \App\Support\Audit::write([
            'user_id' => $request->user()->id,
            'action' => 'waitlist.reminded',
            'entity_type' => 'child',
            'entity_id' => $childId,
            'payload' => json_encode(['email' => $email, 'note' => $note ?: null]),
            'created_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * POST /api/v1/director/waitlist/{childId}/move
     * Move waitlist position up or down.
     */
    public function move(Request $request, int $childId): JsonResponse
    {
        $data = $request->validate([
            'new_position' => ['required', 'integer', 'min:1'],
        ]);

        $child = DB::table('children')->where('id', $childId)->whereNull('deleted_at')->first();
        if (! $child) return response()->json(['message' => 'Not found'], 404);
        $family = DB::table('families')->where('id', $child->family_id)->first();
        if (! $this->hasCentreAccess($request->user()->id, $family->centre_id)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        DB::table('children')->where('id', $childId)->update([
            'waitlist_position' => $data['new_position'],
            'updated_at' => now(),
        ]);
        $this->reindexWaitlist($family->centre_id);

        return response()->json(['success' => true]);
    }

    /**
     * POST /api/v1/director/waitlist (add a child + family to the waitlist directly).
     * For director-side intake of paper applications.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'centre_id' => ['required', 'integer'],
            'family_name' => ['required', 'string', 'max:120'],
            'primary_email' => ['nullable', 'email', 'max:180'],
            'primary_phone' => ['nullable', 'string', 'max:40'],
            'child_first_name' => ['required', 'string', 'max:80'],
            'child_last_name' => ['required', 'string', 'max:80'],
            'date_of_birth' => ['required', 'date'],
            'expected_start_date' => ['nullable', 'date'],
            'preferred_room_age_group' => ['nullable', 'in:infant,toddler,preschool,kindergarten,school_age'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if (! $this->hasCentreAccess($request->user()->id, $data['centre_id'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        DB::beginTransaction();
        try {
            $familyId = DB::table('families')->insertGetId([
                'centre_id' => $data['centre_id'],
                'family_name' => $data['family_name'],
                'primary_email' => $data['primary_email'] ?? null,
                'primary_phone' => $data['primary_phone'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Next position
            $nextPosition = (DB::table('children')
                ->join('families', 'families.id', '=', 'children.family_id')
                ->where('families.centre_id', $data['centre_id'])
                ->where('children.enrollment_status', 'waitlist')
                ->max('children.waitlist_position') ?? 0) + 1;

            $childId = DB::table('children')->insertGetId([
                'family_id' => $familyId,
                'first_name' => $data['child_first_name'],
                'last_name' => $data['child_last_name'],
                'date_of_birth' => $data['date_of_birth'],
                'enrollment_status' => 'waitlist',
                'applied_at' => now()->toDateString(),
                'waitlist_position' => $nextPosition,
                'expected_start_date' => $data['expected_start_date'] ?? null,
                'preferred_room_age_group' => $data['preferred_room_age_group'] ?? null,
                'waitlist_notes' => $data['notes'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            \App\Support\Audit::write([
                'user_id' => $request->user()->id,
                'action' => 'waitlist.added',
                'entity_type' => 'child',
                'entity_id' => $childId,
                'payload' => json_encode([
                    'family_id' => $familyId,
                    'position' => $nextPosition,
                ]),
                'created_at' => now(),
            ]);

            DB::commit();
            return response()->json([
                'success' => true,
                'child_id' => $childId,
                'family_id' => $familyId,
                'position' => $nextPosition,
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Could not add: ' . $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/v1/parent/waitlist-status
     * Parent's view of any waitlisted children they have.
     */
    public function parentStatus(Request $request): JsonResponse
    {
        $user = $request->user();
        $familyIds = DB::table('guardians')->where('user_id', $user->id)->pluck('family_id')->all();
        if (empty($familyIds)) return response()->json(['waitlisted' => []]);

        $waitlisted = DB::table('children')
            ->join('families', 'families.id', '=', 'children.family_id')
            ->join('centres', 'centres.id', '=', 'families.centre_id')
            ->whereIn('children.family_id', $familyIds)
            ->where('children.enrollment_status', 'waitlist')
            ->whereNull('children.deleted_at')
            ->select(
                'children.id', 'children.first_name', 'children.last_name',
                'children.applied_at', 'children.waitlist_position',
                'children.expected_start_date',
                'centres.name as centre_name'
            )
            ->get()
            ->map(function ($r) {
                return [
                    'child_id' => $r->id,
                    'child_name' => trim($r->first_name . ' ' . $r->last_name),
                    'centre_name' => $r->centre_name,
                    'applied_at' => $r->applied_at,
                    'days_waiting' => $r->applied_at ? Carbon::parse($r->applied_at)->diffInDays(Carbon::now()) : null,
                    'position' => $r->waitlist_position,
                    'expected_start_date' => $r->expected_start_date,
                ];
            });

        return response()->json(['waitlisted' => $waitlisted]);
    }

    /* ─── HELPERS ────────────────────────────────────────────────── */

    private function hasCentreAccess(int $userId, int $centreId): bool
    {
        $has = DB::table('role_assignments')
            ->where('user_id', $userId)
            ->whereIn('role', ['agency_admin', 'centre_director'])
            ->where('active', true)
            ->where(function ($q) use ($centreId) {
                $q->where('centre_id', $centreId)
                  ->orWhereIn('agency_id', function ($qq) use ($centreId) {
                      $qq->select('agency_id')->from('centres')->where('id', $centreId);
                  });
            })
            ->exists();
        if ($has) return true;
        // v22p98: platform_admin scoped to the agency they've switched into.
        $isPlatform = DB::table('role_assignments')->where('user_id', $userId)->where('role', 'platform_admin')->where('active', true)->exists();
        if (! $isPlatform) return false;
        $centreAgency = (int) DB::table('centres')->where('id', $centreId)->value('agency_id');
        return $centreAgency > 0 && $centreAgency === (int) request()->header('X-Active-Agency-Id');
    }

    private function reindexWaitlist(int $centreId): void
    {
        $children = DB::table('children')
            ->join('families', 'families.id', '=', 'children.family_id')
            ->where('families.centre_id', $centreId)
            ->where('children.enrollment_status', 'waitlist')
            ->whereNull('children.deleted_at')
            ->orderByRaw('CASE WHEN children.waitlist_position IS NULL THEN 1 ELSE 0 END, children.waitlist_position, children.applied_at, children.id')
            ->pluck('children.id')
            ->all();
        foreach ($children as $i => $id) {
            DB::table('children')->where('id', $id)->update(['waitlist_position' => $i + 1]);
        }
    }
}
