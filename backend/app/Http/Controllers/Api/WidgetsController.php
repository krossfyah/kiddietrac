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
            ? \App\Support\AgencyTime::fmt($latest->occurred_at, \App\Support\AgencyTime::tzForCentre(
                DB::table('families as f')->join('children as ch', 'ch.family_id', '=', 'f.id')->where('ch.id', $latest->child_id)->value('f.centre_id')
              ))
            : 'Check-in pending';

        // Outstanding balance across all this family's invoices. Includes BOTH
        // KiddieTrac-native `invoices` AND `external_invoices` (billing synced from
        // an integrated system like iLearn — its `balance_due` already reflects
        // payments, so a paid invoice contributes 0). Without the external table an
        // iLearn family's card wrongly read $0.00 while they owed money there.
        // THIS MONTH only (not the cumulative all-time balance): invoices issued in
        // the current calendar month, in the agency's Eastern zone.
        $monthStart = Carbon::now('America/Toronto')->startOfMonth()->utc()->format('Y-m-d H:i:s');
        $monthEnd   = Carbon::now('America/Toronto')->endOfMonth()->utc()->format('Y-m-d H:i:s');
        $familyIds = DB::table('guardians')->where('user_id', $user->id)->pluck('family_id')->all();
        // Filter by DUE date — invoices are billed as installments with staggered
        // due dates, so "this month" means what falls due this month (not when the
        // batch was issued).
        $balance = (float) DB::table('invoices')
            ->whereIn('family_id', $familyIds)
            ->whereIn('status', ['sent', 'partial', 'overdue'])
            ->whereBetween('due_at', [$monthStart, $monthEnd])
            ->sum('balance_due');
        if (\Illuminate\Support\Facades\Schema::hasTable('external_invoices') && ! empty($familyIds)) {
            $balance += (float) DB::table('external_invoices')
                ->whereIn('family_id', $familyIds)
                ->whereNotIn('status', ['void', 'cancelled', 'draft'])
                ->whereBetween('due_at', [$monthStart, $monthEnd])
                ->sum('balance_due');
        }

        // ACTUAL photos of the family's children shared in the last 7 days. Photos
        // live in `photos` with a `child_ids` JSON array (PhotoFeedController::upload).
        // (This used to count SHARED OBSERVATIONS, so a shared learning-story with no
        // image made the "Photos this week" card read "1 photo" with nothing to view.)
        $sinceWeek = Carbon::now()->subDays(7);
        $photoCount = 0;
        if (! empty($childIds)) {
            $photoCount = (int) DB::table('photos')
                ->where('taken_at', '>=', $sinceWeek)
                ->where(function ($q) use ($childIds) {
                    foreach ($childIds as $cid) {
                        $q->orWhereJsonContains('child_ids', (int) $cid);
                    }
                })
                ->count();
        }

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
                'label' => 'Balance this month',
                'value' => '$' . number_format($balance, 2),
                'hint' => $balance > 0 ? 'Due this month' : 'Nothing due this month',
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
            $this->openTasksCard($user->id),
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

        // Hours worked so far this week (completed time-clock punches only).
        $weekStart = Carbon::now()->startOfWeek();
        $myPunches = DB::table('time_punches')
            ->where('user_id', $user->id)
            ->whereNotNull('punched_out_at')
            ->where('punched_in_at', '>=', $weekStart)
            ->get(['punched_in_at', 'punched_out_at']);
        $weekMins = 0;
        foreach ($myPunches as $mp) {
            $weekMins += (int) Carbon::parse($mp->punched_in_at)->diffInMinutes(Carbon::parse($mp->punched_out_at));
        }
        $wH = intdiv($weekMins, 60);
        $wM = $weekMins % 60;
        $hoursDisplay = $weekMins > 0 ? ($wH . 'h' . ($wM ? ' ' . $wM . 'm' : '')) : '0h';
        $shiftCount = count($myPunches);

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
            [
                'id' => 'my-hours',
                'label' => 'My hours this week',
                'value' => $hoursDisplay,
                'hint' => $shiftCount > 0 ? ($shiftCount . ' shift' . ($shiftCount === 1 ? '' : 's') . ' logged') : 'No shifts logged yet',
                'accent' => '#4F46E5',
                'icon' => '⏱',
            ],
            $this->openTasksCard($user->id),
        ];
    }

    /** A stat card for the number of tasks assigned to this user that are still open. */
    private function openTasksCard($userId): array
    {
        $n = (int) DB::table('tasks')
            ->whereNull('deleted_at')
            ->where('assigned_to', $userId)
            ->whereIn('status', ['open', 'in_progress'])
            ->count();
        return [
            'id' => 'open-tasks',
            'label' => 'Open tasks',
            'value' => (string) $n,
            'hint' => $n > 0 ? 'Assigned to you — tap to view' : 'You\'re all caught up',
            'accent' => '#0E9BAF',
            'icon' => '📋',
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
