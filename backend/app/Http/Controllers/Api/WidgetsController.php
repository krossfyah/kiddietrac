<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v22p33 — Per-role dashboard widget data.
 *
 * One endpoint, switches on the caller's primary role. Returns a small array
 * of KPI cards suited to that role. The frontend module role-widgets.js
 * renders the strip above each role's dashboard.
 *
 * Cards are intentionally simple — value + label + hint + accent — so the
 * frontend renderer is the same shape for every role.
 */
final class WidgetsController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        // Pick the most privileged role for widget selection.
        $roles = DB::table('role_assignments')
            ->where('user_id', $user->id)
            ->where('active', true)
            ->pluck('role')
            ->all();
        $isGuardian = DB::table('guardians')->where('user_id', $user->id)->exists();
        if (in_array('platform_admin', $roles, true))      $role = 'platform_admin';
        elseif (in_array('agency_admin', $roles, true))    $role = 'agency_admin';
        elseif (in_array('centre_director', $roles, true)) $role = 'centre_director';
        elseif (in_array('educator', $roles, true))        $role = 'educator';
        elseif ($isGuardian)                               $role = 'guardian';
        else                                               $role = 'unknown';

        $widgets = match ($role) {
            'guardian'        => $this->forGuardian($user),
            'educator'        => $this->forEducator($user),
            'centre_director' => $this->forDirector($user),
            'agency_admin', 'platform_admin' => [], // these roles already have full dashboards
            default => [],
        };

        return response()->json([
            'role' => $role,
            'widgets' => $widgets,
        ]);
    }

    // ── Guardian / parent --------------------------------------------------

    private function forGuardian($user): array
    {
        $childIds = DB::table('guardians as g')
            ->join('families as f', 'f.id', '=', 'g.family_id')
            ->join('children as c', 'c.family_id', '=', 'f.id')
            ->where('g.user_id', $user->id)
            ->whereNull('c.deleted_at')
            ->pluck('c.id')
            ->all();

        if (empty($childIds)) {
            return [[
                'id' => 'no-children',
                'label' => 'No children on file',
                'value' => '—',
                'hint' => 'Ask your centre to link your account',
                'accent' => '#6B7280',
                'icon' => '👋',
            ]];
        }

        // Latest check event today for any of the user's children
        $today = Carbon::now()->startOfDay();
        $latest = DB::table('check_events')
            ->whereIn('child_id', $childIds)
            ->where('occurred_at', '>=', $today)
            ->orderByDesc('occurred_at')
            ->first();
        $statusValue = $latest
            ? ($latest->event_type === 'check_in' ? 'Signed in' : 'Signed out')
            : 'Not yet today';
        $statusHint = $latest
            ? Carbon::parse($latest->occurred_at)->format('g:i A')
            : 'Check-in pending';

        // Outstanding balance across all this family's invoices
        $familyIds = DB::table('guardians')->where('user_id', $user->id)->pluck('family_id')->all();
        $balance = (float) DB::table('invoices')
            ->whereIn('family_id', $familyIds)
            ->whereIn('status', ['sent', 'partial', 'overdue'])
            ->sum('balance_due');

        // Observations shared with the family in the last 7 days. observations.media_ids
        // is a longtext JSON of media uuids, so any shared observation counts as a
        // photo+note moment from the parent's perspective.
        $sinceWeek = Carbon::now()->subDays(7);
        $photoCount = DB::table('observations')
            ->whereIn('child_id', $childIds)
            ->where('created_at', '>=', $sinceWeek)
            ->where('shared_with_family', 1)
            ->count();

        $childCount = count($childIds);

        return [
            [
                'id' => 'today-status',
                'label' => "Today's status",
                'value' => $statusValue,
                'hint' => $statusHint,
                'accent' => $latest && $latest->event_type === 'check_in' ? '#16A34A' : '#6B7280',
                'icon' => '📍',
            ],
            [
                'id' => 'balance',
                'label' => 'Outstanding balance',
                'value' => '$' . number_format($balance, 2),
                'hint' => $balance > 0 ? 'Open invoices on your family' : 'You are all paid up',
                'accent' => $balance > 0 ? '#DC2626' : '#16A34A',
                'icon' => '💳',
            ],
            [
                'id' => 'photos-week',
                'label' => 'Photos this week',
                'value' => (string) $photoCount,
                'hint' => $photoCount > 0 ? 'New memories to view' : 'No photos shared yet',
                'accent' => '#7C3AED',
                'icon' => '📸',
            ],
            [
                'id' => 'children-count',
                'label' => $childCount === 1 ? 'Your child' : 'Your children',
                'value' => (string) $childCount,
                'hint' => 'Enrolled',
                'accent' => '#FF8A65',
                'icon' => '👶',
            ],
        ];
    }

    // ── Educator -----------------------------------------------------------

    private function forEducator($user): array
    {
        // Educators are scoped via role_assignments.centre_id. Use the first active one.
        $assignment = DB::table('role_assignments')
            ->where('user_id', $user->id)
            ->where('role', 'educator')
            ->where('active', true)
            ->whereNotNull('centre_id')
            ->first();

        if (!$assignment) {
            return [[
                'id' => 'unassigned',
                'label' => 'Not yet assigned to a centre',
                'value' => '—',
                'hint' => 'Ask your director to set your centre',
                'accent' => '#6B7280',
                'icon' => '🏫',
            ]];
        }
        $centreId = (int) $assignment->centre_id;

        $today = Carbon::now()->startOfDay();
        $tomorrow = (clone $today)->addDay();

        // Children currently signed in at the centre (last event today is check_in)
        $signedInCount = DB::table('check_events as ce1')
            ->join('children as c', 'c.id', '=', 'ce1.child_id')
            ->join('families as f', 'f.id', '=', 'c.family_id')
            ->where('f.centre_id', $centreId)
            ->where('ce1.occurred_at', '>=', $today)
            ->where('ce1.event_type', 'check_in')
            ->whereNotExists(function ($sq) use ($today) {
                $sq->select(DB::raw(1))->from('check_events as ce2')
                    ->whereColumn('ce2.child_id', 'ce1.child_id')
                    ->where('ce2.occurred_at', '>', DB::raw('ce1.occurred_at'))
                    ->where('ce2.event_type', 'check_out')
                    ->where('ce2.occurred_at', '<', $today->copy()->addDay());
            })
            ->distinct()
            ->count('ce1.child_id');

        // Total enrolled at this centre — capacity context
        $enrolledCount = DB::table('children as c')
            ->join('families as f', 'f.id', '=', 'c.family_id')
            ->where('f.centre_id', $centreId)
            ->where('c.enrollment_status', 'enrolled')
            ->whereNull('c.deleted_at')
            ->count();

        // medication_administrations carries completed admins (administered_at).
        // Count today's dose log to surface what's already been given. If you
        // need 'scheduled but not yet given', query medications.schedule_json
        // — out of scope for this widget strip.
        $medsToday = DB::table('medication_administrations as ma')
            ->join('children as c', 'c.id', '=', 'ma.child_id')
            ->join('families as f', 'f.id', '=', 'c.family_id')
            ->where('f.centre_id', $centreId)
            ->whereBetween('ma.administered_at', [$today, $tomorrow])
            ->count();

        // Observations the educator personally logged this week
        $sinceWeek = Carbon::now()->subDays(7);
        $obsThisWeek = DB::table('observations')
            ->where('recorded_by_id', $user->id)
            ->where('created_at', '>=', $sinceWeek)
            ->count();

        return [
            [
                'id' => 'signed-in',
                'label' => 'Signed in now',
                'value' => $signedInCount . ' / ' . $enrolledCount,
                'hint' => $enrolledCount > 0 ? round(($signedInCount / max($enrolledCount, 1)) * 100) . '% present' : 'No enrolled children',
                'accent' => '#16A34A',
                'icon' => '✅',
            ],
            [
                'id' => 'meds-today',
                'label' => 'Meds given today',
                'value' => (string) $medsToday,
                'hint' => $medsToday > 0 ? 'Doses logged so far' : 'No doses given yet',
                'accent' => '#F59E0B',
                'icon' => '💊',
            ],
            [
                'id' => 'observations',
                'label' => 'Observations this week',
                'value' => (string) $obsThisWeek,
                'hint' => 'Notes + photos you logged',
                'accent' => '#7C3AED',
                'icon' => '📝',
            ],
            [
                'id' => 'enrolled',
                'label' => 'Enrolled at centre',
                'value' => (string) $enrolledCount,
                'hint' => 'Children on roll',
                'accent' => '#1F6080',
                'icon' => '👶',
            ],
        ];
    }

    // ── Director -----------------------------------------------------------

    private function forDirector($user): array
    {
        $assignment = DB::table('role_assignments')
            ->where('user_id', $user->id)
            ->where('role', 'centre_director')
            ->where('active', true)
            ->whereNotNull('centre_id')
            ->first();
        if (!$assignment) {
            return [[
                'id' => 'no-centre',
                'label' => 'No centre assigned',
                'value' => '—',
                'hint' => 'Ask your agency admin to assign one',
                'accent' => '#6B7280',
                'icon' => '🏫',
            ]];
        }
        $centreId = (int) $assignment->centre_id;
        $centre = DB::table('centres')->where('id', $centreId)->first();
        $capacity = (int) ($centre->license_capacity ?? 0);

        $enrolled = DB::table('children as c')
            ->join('families as f', 'f.id', '=', 'c.family_id')
            ->where('f.centre_id', $centreId)
            ->where('c.enrollment_status', 'enrolled')
            ->whereNull('c.deleted_at')
            ->count();

        $pct = $capacity > 0 ? round(($enrolled / $capacity) * 100) : 0;
        $pctAccent = $pct >= 95 ? '#DC2626' : ($pct >= 80 ? '#F59E0B' : '#16A34A');

        // Open invoice balance for families at this centre
        $openBalance = (float) DB::table('invoices')
            ->where('centre_id', $centreId)
            ->whereIn('status', ['sent', 'partial', 'overdue'])
            ->sum('balance_due');
        $openCount = DB::table('invoices')
            ->where('centre_id', $centreId)
            ->whereIn('status', ['sent', 'partial', 'overdue'])
            ->count();

        // Children not checked in by 9:30 AM (a soft compliance signal)
        $today = Carbon::now()->startOfDay();
        $cutoff = $today->copy()->setTime(9, 30);
        $missingCheckIn = 0;
        if (Carbon::now()->greaterThan($cutoff)) {
            $missingCheckIn = DB::table('children as c')
                ->join('families as f', 'f.id', '=', 'c.family_id')
                ->where('f.centre_id', $centreId)
                ->where('c.enrollment_status', 'enrolled')
                ->whereNull('c.deleted_at')
                ->whereNotExists(function ($q) use ($today) {
                    $q->select(DB::raw(1))->from('check_events as ce')
                        ->whereColumn('ce.child_id', 'c.id')
                        ->where('ce.event_type', 'check_in')
                        ->where('ce.occurred_at', '>=', $today);
                })
                ->count();
        }

        return [
            [
                'id' => 'capacity',
                'label' => 'Capacity today',
                'value' => $pct . '%',
                'hint' => $enrolled . ' of ' . $capacity . ' filled',
                'accent' => $pctAccent,
                'icon' => '🏫',
            ],
            [
                'id' => 'open-invoices',
                'label' => 'Open invoices',
                'value' => '$' . number_format($openBalance, 0),
                'hint' => $openCount . ' open · sent/partial/overdue',
                'accent' => $openBalance > 0 ? '#DC2626' : '#16A34A',
                'icon' => '🧾',
            ],
            [
                'id' => 'missing-checkin',
                'label' => 'Not checked in (9:30am)',
                'value' => (string) $missingCheckIn,
                'hint' => Carbon::now()->greaterThan($cutoff) ? 'Children expected today' : 'Before cutoff',
                'accent' => $missingCheckIn > 0 ? '#F59E0B' : '#16A34A',
                'icon' => '⏰',
            ],
            [
                'id' => 'enrolled-count',
                'label' => 'Enrolled',
                'value' => (string) $enrolled,
                'hint' => 'Children on roll',
                'accent' => '#1F6080',
                'icon' => '👶',
            ],
        ];
    }
}
