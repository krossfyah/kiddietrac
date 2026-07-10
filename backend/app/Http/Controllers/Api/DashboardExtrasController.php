<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Extra agency-dashboard widgets (2026-07-09): upcoming birthdays,
 * clickable "team on the floor" (online staff with ids), and a one-tap
 * quick-message that fires an in-app notification + FCM push to a staff member.
 */
final class DashboardExtrasController extends Controller
{
    private function agencyId(Request $request): ?int
    {
        $uid = $request->user()->id;
        $active = (int) $request->header('X-Active-Agency-Id');
        if ($active) {
            $ok = DB::table('role_assignments')->where('user_id', $uid)->where('active', true)
                ->where(function ($q) use ($active) { $q->where('agency_id', $active)->orWhere('role', 'platform_admin'); })->exists();
            if ($ok) return $active;
        }
        return DB::table('role_assignments')->where('user_id', $uid)->where('active', true)->value('agency_id');
    }

    private function centreIds(int $agencyId): array
    {
        return DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all();
    }

    /** GET /admin/upcoming-birthdays — enrolled children with a birthday in the next 30 days. */
    public function upcomingBirthdays(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        if (!$agencyId) return response()->json(['birthdays' => []]);
        $centreIds = $this->centreIds($agencyId);
        if (empty($centreIds)) return response()->json(['birthdays' => []]);

        $rows = DB::table('children')
            ->join('families', 'families.id', '=', 'children.family_id')
            ->whereIn('families.centre_id', $centreIds)
            ->whereNotNull('children.date_of_birth')
            ->where('children.enrollment_status', 'enrolled')
            ->select('children.first_name', 'children.preferred_name', 'children.date_of_birth')
            ->get();

        $today = now()->startOfDay();
        $list = [];
        foreach ($rows as $r) {
            try { $dob = Carbon::parse($r->date_of_birth)->startOfDay(); } catch (\Throwable $e) { continue; }
            $next = $dob->copy()->year((int) $today->year);
            if ($next->lt($today)) $next->addYear();
            $days = (int) $today->diffInDays($next, false);
            if ($days < 0 || $days > 30) continue;
            $list[] = [
                'name' => $r->preferred_name ?: $r->first_name,
                'date' => $next->toDateString(),
                'day' => (int) $next->format('j'),
                'month' => (int) $next->format('n'),
                'display' => $next->format('M j'),
                'turning' => (int) $next->year - (int) $dob->year,
                'in_days' => $days,
                'is_today' => $days === 0,
                'kind' => 'child',
            ];
        }

        // Also include everyone else in the agency (staff, parents, admins) who
        // has set a birthday in their profile.
        $userIds = DB::table('role_assignments')->where('active', true)
            ->where(function ($x) use ($agencyId, $centreIds) {
                $x->where('agency_id', $agencyId);
                if (!empty($centreIds)) $x->orWhereIn('centre_id', $centreIds);
            })->pluck('user_id')->all();
        $guardianIds = DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')
            ->whereIn('f.centre_id', $centreIds ?: [0])->pluck('g.user_id')->all();
        $userIds = array_values(array_unique(array_merge($userIds, $guardianIds)));
        $users = empty($userIds) ? collect()
            : DB::table('users')->whereIn('id', $userIds)->whereNotNull('date_of_birth')->get(['first_name', 'last_name', 'date_of_birth']);
        foreach ($users as $u) {
            try { $dob = Carbon::parse($u->date_of_birth)->startOfDay(); } catch (\Throwable $e) { continue; }
            $next = $dob->copy()->year((int) $today->year);
            if ($next->lt($today)) $next->addYear();
            $days = (int) $today->diffInDays($next, false);
            if ($days < 0 || $days > 30) continue;
            $list[] = [
                'name' => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: 'Team member',
                'date' => $next->toDateString(),
                'day' => (int) $next->format('j'),
                'month' => (int) $next->format('n'),
                'display' => $next->format('M j'),
                'turning' => (int) $next->year - (int) $dob->year,
                'in_days' => $days,
                'is_today' => $days === 0,
                'kind' => 'adult',
            ];
        }

        usort($list, fn ($a, $b) => $a['in_days'] <=> $b['in_days']);
        return response()->json(['birthdays' => $list]);
    }

    /** GET /admin/floor-staff — staff active in the app in the last 10 min (id + name), for the clickable presence widget. */
    public function floorStaff(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        if (!$agencyId) return response()->json(['staff' => []]);
        $centreIds = $this->centreIds($agencyId);

        $staffIds = DB::table('role_assignments')
            ->where('active', true)
            ->whereIn('role', ['educator', 'centre_director', 'agency_admin'])
            ->where(function ($x) use ($agencyId, $centreIds) {
                $x->where('agency_id', $agencyId);
                if (!empty($centreIds)) $x->orWhereIn('centre_id', $centreIds);
            })
            ->pluck('user_id')->unique()->all();
        if (empty($staffIds)) return response()->json(['staff' => []]);

        $online = DB::table('personal_access_tokens')
            ->where('tokenable_type', \App\Models\User::class)
            ->whereIn('tokenable_id', $staffIds)
            ->where('last_used_at', '>=', now()->subMinutes(10))
            ->distinct()->pluck('tokenable_id')->all();
        if (empty($online)) return response()->json(['staff' => []]);

        $staff = DB::table('users')->whereIn('id', $online)->orderBy('first_name')->get(['id', 'first_name', 'last_name'])
            ->map(fn ($u) => ['user_id' => (int) $u->id, 'name' => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: 'Staff member'])
            ->values();
        return response()->json(['staff' => $staff]);
    }

    /** POST /admin/quick-notify { user_id, message } — one-tap message → notification + push. */
    public function quickNotify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'message' => ['required', 'string', 'max:1000'],
        ]);
        $sender = $request->user();
        $isStaff = DB::table('role_assignments')->where('user_id', $sender->id)->where('active', true)
            ->whereIn('role', ['educator', 'centre_director', 'agency_admin', 'platform_admin'])->exists();
        if (!$isStaff) abort(403);

        // SECURITY: only allow notifying a user who shares one of the sender's active
        // agencies/centres (platform_admin excepted) — no cross-tenant pings by user id.
        $isPlatform = DB::table('role_assignments')->where('user_id', $sender->id)->where('active', true)->where('role', 'platform_admin')->exists();
        if (!$isPlatform) {
            $myAgencies = DB::table('role_assignments')->where('user_id', $sender->id)->where('active', true)->pluck('agency_id')->filter()->all();
            $myCentres = DB::table('role_assignments')->where('user_id', $sender->id)->where('active', true)->pluck('centre_id')->filter()->all();
            $shares = DB::table('role_assignments')->where('user_id', $data['user_id'])->where('active', true)
                ->where(function ($q) use ($myAgencies, $myCentres) {
                    if (!empty($myAgencies)) $q->orWhereIn('agency_id', $myAgencies);
                    if (!empty($myCentres)) $q->orWhereIn('centre_id', $myCentres);
                })->exists();
            $sharesFamily = DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')
                ->where('g.user_id', $data['user_id'])->whereIn('f.centre_id', $myCentres ?: [0])->exists();
            if (!$shares && !$sharesFamily) abort(403);
        }

        $sn = DB::table('users')->where('id', $sender->id)->first();
        $senderName = trim(($sn->first_name ?? '') . ' ' . ($sn->last_name ?? '')) ?: 'Your team';

        DB::table('notifications')->insert([
            'user_id' => $data['user_id'], 'type' => 'message',
            'title' => 'Message from ' . $senderName,
            'body' => $data['message'],
            'data' => json_encode(['link' => '#notifications']),
            'created_at' => now(),
        ]);
        try { app(\App\Services\FcmService::class)->sendToUser((int) $data['user_id'], 'Message from ' . $senderName, $data['message'], '#notifications'); } catch (\Throwable $e) {}

        return response()->json(['success' => true]);
    }
}
