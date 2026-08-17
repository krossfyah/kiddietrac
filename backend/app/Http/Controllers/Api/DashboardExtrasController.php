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
            ->select('children.first_name', 'children.preferred_name', 'children.date_of_birth', 'children.photo_url', 'children.gender')
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
                'photo_url' => $r->photo_url ?? null,
                'sex' => $r->gender ?? null,
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
            : DB::table('users')->whereIn('id', $userIds)->whereNotNull('date_of_birth')->get(['first_name', 'last_name', 'date_of_birth', 'photo_url', 'sex']);
        foreach ($users as $u) {
            try { $dob = Carbon::parse($u->date_of_birth)->startOfDay(); } catch (\Throwable $e) { continue; }
            $next = $dob->copy()->year((int) $today->year);
            if ($next->lt($today)) $next->addYear();
            $days = (int) $today->diffInDays($next, false);
            if ($days < 0 || $days > 30) continue;
            $list[] = [
                'name' => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: 'Team member',
                'photo_url' => $u->photo_url ?? null,
                'sex' => $u->sex ?? null,
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
            ->whereIn('role', ['educator', 'centre_director', 'agency_admin', 'home_visitor'])
            ->where(function ($x) use ($agencyId, $centreIds) {
                $x->where('agency_id', $agencyId);
                if (!empty($centreIds)) $x->orWhereIn('centre_id', $centreIds);
            })
            ->pluck('user_id')->unique()->all();
        if (empty($staffIds)) return response()->json(['staff' => []]);

        // "On the floor" = CLOCKED IN (an open shift), not merely app-active. Two clock
        // tables: time_punches (current provider/educator clock) + time_entries (legacy).
        // App activity is used ONLY for the green/amber presence dot below — a clocked-in
        // educator who isn't touching the app still belongs here (idle, not absent).
        $punch = DB::table('time_punches')->whereIn('centre_id', $centreIds ?: [0])
            ->whereNull('punched_out_at')->where('punched_in_at', '>=', now()->subHours(20))->pluck('user_id');
        $entry = DB::table('time_entries')->whereIn('centre_id', $centreIds ?: [0])
            ->whereNull('clocked_out_at')->pluck('user_id');
        $clockedIn = $punch->merge($entry)->unique()->filter()->values()->all();
        $online = array_values(array_intersect($clockedIn, $staffIds));   // keep the var name used below
        if (empty($online)) return response()->json(['staff' => []]);

        // Primary role per user (for the role pill next to the name).
        $roleRank = ['agency_admin' => 4, 'centre_director' => 3, 'home_visitor' => 2, 'educator' => 1];
        $roleLabels = ['agency_admin' => 'Admin', 'centre_director' => 'Director', 'home_visitor' => 'Home visitor', 'educator' => 'Educator'];
        $rolesByUser = [];
        foreach (DB::table('role_assignments')->whereIn('user_id', $online)->where('active', true)
            ->whereIn('role', array_keys($roleRank))->get(['user_id', 'role']) as $ra) {
            $cur = $rolesByUser[$ra->user_id] ?? null;
            if (!$cur || ($roleRank[$ra->role] ?? 0) > ($roleRank[$cur] ?? 0)) $rolesByUser[$ra->user_id] = $ra->role;
        }

        $presence = \App\Support\Presence::forUsers($online);
        $staff = DB::table('users')->whereIn('id', $online)->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'photo_url', 'sex'])
            ->map(function ($u) use ($rolesByUser, $roleLabels, $presence) {
                $role = $rolesByUser[$u->id] ?? null;
                return [
                    'user_id' => (int) $u->id,
                    'name' => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: 'Staff member',
                    'photo_url' => $u->photo_url ?? null,
                    'sex' => $u->sex ?? null,
                    'role' => $role,
                    'role_label' => $role ? ($roleLabels[$role] ?? ucfirst(str_replace('_', ' ', $role))) : null,
                    'presence' => $presence[(int) $u->id] ?? 'idle',
                ];
            })
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

        // If the recipient is a colleague (staff), persist this quick message as a
        // real staff DM thread so it shows up — and can be replied to — under Team
        // messages, not just as a fire-and-forget bell notification.
        $recipientIsStaff = DB::table('role_assignments')->where('user_id', $data['user_id'])->where('active', true)
            ->whereIn('role', ['educator', 'centre_director', 'agency_admin', 'platform_admin', 'home_visitor'])->exists();
        $link = '#notifications';
        if ($recipientIsStaff && (int) $data['user_id'] !== (int) $sender->id) {
            $link = $this->quickMessageToThread((int) $sender->id, (int) $data['user_id'], (string) $data['message']);
        }

        DB::table('notifications')->insert([
            'user_id' => $data['user_id'], 'type' => 'message',
            'title' => 'Message from ' . $senderName,
            'body' => $data['message'],
            'data' => json_encode(['link' => $link]),
            'created_at' => now(),
        ]);
        try { app(\App\Services\FcmService::class)->sendToUser((int) $data['user_id'], 'Message from ' . $senderName, $data['message'], $link); } catch (\Throwable $e) {}

        return response()->json(['success' => true]);
    }

    /**
     * Find-or-create the 1:1 staff DM thread between two users and post $body from
     * $senderId. Mirrors TeamChatController's thread model (staff_threads /
     * staff_thread_participants / staff_messages) so quick messages and Team
     * messages share one conversation. Returns the deep-link hash for the thread.
     */
    private function quickMessageToThread(int $senderId, int $recipientId, string $body): string
    {
        $now = now();
        $threadId = DB::table('staff_thread_participants as a')
            ->join('staff_thread_participants as b', 'a.thread_id', '=', 'b.thread_id')
            ->where('a.user_id', $senderId)->where('b.user_id', $recipientId)
            ->whereRaw('(SELECT COUNT(*) FROM staff_thread_participants p WHERE p.thread_id = a.thread_id) = 2')
            ->value('a.thread_id');

        if (! $threadId) {
            $agencyId = DB::table('role_assignments')->where('user_id', $senderId)->where('active', true)
                ->whereNotNull('agency_id')->value('agency_id');
            $threadId = DB::table('staff_threads')->insertGetId([
                'agency_id' => $agencyId, 'created_by' => $senderId, 'last_message_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ([$senderId, $recipientId] as $p) {
                DB::table('staff_thread_participants')->insert([
                    'thread_id' => $threadId, 'user_id' => $p, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        DB::table('staff_messages')->insert([
            'thread_id' => $threadId, 'sender_id' => $senderId, 'body' => trim($body),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('staff_threads')->where('id', $threadId)->update(['last_message_at' => $now, 'updated_at' => $now]);

        return '#team-messages';
    }
}
