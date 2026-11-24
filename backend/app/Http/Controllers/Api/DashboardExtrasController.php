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
        /* The membership-checked active agency, never "the first role row's agency" (2026-11-05 leak sweep): that
           was NULL for a director's centre row and could be another agency for a person with roles in two. */
        if (DB::table('role_assignments')->where('user_id', $uid)->where('active', true)->where('role', 'platform_admin')->exists()) return null;
        return \App\Support\AgencyRole::activeAgency($request);
    }

    private function centreIds(int $agencyId): array
    {
        return DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all();
    }

    /**
     * GET /admin/upcoming-birthdays — birthdays in the next 30 days: enrolled children, staff
     * and (for admins/directors) parents who set one.
     *
     * 2026-10-21:
     *  - STAFF ONLY, AND SCOPED. This route sat outside every role group, so any signed-in
     *    account — a parent included — could list every enrolled child's name, birthday and
     *    age across the agency. Now: admins see the agency, directors and educators their own
     *    centres (App\Support\CentreScope), parents nothing.
     *  - STAFF ARE LISTED, labelled with their role (Anthony: "upcoming birthdays add staff in
     *    that list"). Their date of birth comes from users.date_of_birth, or the profile's.
     *  - ADULTS SHOW DAY AND MONTH ONLY: never a birth year or an age for staff or parents.
     *  - THE AGENCY'S TODAY, NOT THE SERVER'S (Anthony: "upcoming birthdays is showing a
     *    birthday occurring today but its actually tomorrow"). now() is UTC here, and from
     *    8 PM in Toronto UTC is already tomorrow, so tomorrow's birthday read as "today".
     *    Dates are compared as calendar dates, so a 23- or 25-hour DST day can never round a
     *    birthday a day early or late.
     */
    public function upcomingBirthdays(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        if (!$agencyId) return response()->json(['birthdays' => []]);

        $uid = (int) $request->user()->id;
        $agencyCentres = $this->centreIds($agencyId);
        $myRoles = DB::table('role_assignments')->where('user_id', $uid)->where('active', true)
            ->where(fn ($q) => $q->where('role', 'platform_admin')->orWhere('agency_id', $agencyId)
                ->orWhereIn('centre_id', $agencyCentres ?: [0]))
            ->pluck('role')->unique()->all();
        $staffRoles = ['platform_admin', 'agency_admin', 'centre_director', 'educator', 'home_visitor'];
        if (! array_intersect($myRoles, $staffRoles)) {
            return response()->json(['birthdays' => []]);
        }
        $scope = \App\Support\CentreScope::ids($request, (int) $agencyId);   // null = the whole agency
        $centreIds = $scope === null ? $agencyCentres : array_values(array_intersect($agencyCentres, $scope));
        if (empty($centreIds)) return response()->json(['birthdays' => []]);
        $seesParents = (bool) array_intersect($myRoles, ['platform_admin', 'agency_admin', 'centre_director']);

        $tz = \App\Support\AgencyTime::tz((int) $agencyId);
        $today = Carbon::parse(Carbon::now($tz)->toDateString(), 'UTC');
        /* The next birthday on or after today, as a calendar date: [date, days away] or null
           if it is more than 30 days off. 29 February falls on 1 March in other years. */
        $nextOf = function ($dobRaw) use ($today): ?array {
            $ymd = substr((string) $dobRaw, 0, 10);
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) return null;
            try { $dob = Carbon::parse($ymd, 'UTC'); } catch (\Throwable $e) { return null; }
            $next = $dob->copy()->year((int) $today->year);
            if ($next->lt($today)) $next->addYear();
            $days = (int) round($today->diffInDays($next, false));
            return ($days < 0 || $days > 30) ? null : [$next, $days, $dob];
        };
        $entry = function (string $name, $photo, $sex, Carbon $next, int $days, string $kind, ?int $turning, ?string $role) {
            return [
                'name' => $name,
                'photo_url' => $photo,
                'sex' => $sex,
                'date' => $next->toDateString(),
                'day' => (int) $next->format('j'),
                'month' => (int) $next->format('n'),
                'display' => $next->format('M j'),
                'turning' => $turning,          // children only
                'in_days' => $days,
                'is_today' => $days === 0,
                'kind' => $kind,                // child | staff | parent ('adult' kept for older clients below)
                'role' => $role,
            ];
        };
        $list = [];

        // ── children ──
        $rows = DB::table('children')
            ->join('families', 'families.id', '=', 'children.family_id')
            ->whereIn('families.centre_id', $centreIds)
            ->whereNull('children.deleted_at')
            ->whereNotNull('children.date_of_birth')
            ->where('children.enrollment_status', 'enrolled')
            ->select('children.first_name', 'children.preferred_name', 'children.date_of_birth', 'children.photo_url', 'children.gender')
            ->get();
        foreach ($rows as $r) {
            if (! ($n = $nextOf($r->date_of_birth))) continue;
            [$next, $days, $dob] = $n;
            $list[] = $entry((string) ($r->preferred_name ?: $r->first_name), $r->photo_url ?? null, $r->gender ?? null,
                $next, $days, 'child', (int) $next->year - (int) $dob->year, null);
        }

        // ── staff (the people this viewer works with) ──
        $ROLE_LABEL = ['agency_admin' => 'Admin', 'centre_director' => 'Director', 'educator' => 'Educator', 'home_visitor' => 'Home visitor'];
        $RANK = ['agency_admin' => 1, 'centre_director' => 2, 'educator' => 3, 'home_visitor' => 4];
        $staffRows = DB::table('role_assignments as ra')
            ->where('ra.active', true)
            ->whereIn('ra.role', array_keys($ROLE_LABEL))
            ->where(function ($x) use ($agencyId, $centreIds, $scope) {
                $x->whereIn('ra.centre_id', $centreIds);
                if ($scope === null) {
                    // Agency-wide viewer: everyone in the agency, centre or not.
                    $x->orWhere('ra.agency_id', $agencyId);
                } else {
                    // A director or educator sees their own centres' team, plus the agency's
                    // admins (no centre). Not an educator assigned elsewhere without a centre.
                    $x->orWhere(fn ($w) => $w->where('ra.agency_id', $agencyId)->whereNull('ra.centre_id')->where('ra.role', 'agency_admin'));
                }
            })
            ->get(['ra.user_id', 'ra.role']);
        $staffRole = [];
        foreach ($staffRows as $sr) {
            $cur = $staffRole[(int) $sr->user_id] ?? null;
            if (! $cur || $RANK[$sr->role] < $RANK[$cur]) $staffRole[(int) $sr->user_id] = $sr->role;
        }
        $off = \App\Support\Audience::OFF_STATUSES;
        $people = function (array $ids) use ($off) {
            if (! $ids) return collect();
            return DB::table('users as u')
                ->leftJoin('user_profiles as p', 'p.user_id', '=', 'u.id')
                ->whereIn('u.id', $ids)->whereNull('u.deleted_at')->whereNotIn('u.status', $off)
                ->where(fn ($w) => $w->whereNotNull('u.date_of_birth')->orWhereNotNull('p.date_of_birth'))
                ->get(['u.id', 'u.first_name', 'u.last_name', 'u.photo_url', 'u.sex', 'u.date_of_birth', 'p.date_of_birth as profile_dob']);
        };
        $listed = [];
        foreach ($people(array_keys($staffRole)) as $u) {
            if (! ($n = $nextOf($u->date_of_birth ?: $u->profile_dob))) continue;
            [$next, $days] = $n;
            $listed[(int) $u->id] = true;
            $list[] = $entry(trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: 'Team member', $u->photo_url ?? null, $u->sex ?? null,
                $next, $days, 'staff', null, $ROLE_LABEL[$staffRole[(int) $u->id]]);
        }

        // ── parents who set a birthday (admins and directors only, as before) ──
        if ($seesParents) {
            $guardianIds = DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')
                ->whereIn('f.centre_id', $centreIds)->whereNotNull('g.user_id')->pluck('g.user_id')
                ->map(fn ($v) => (int) $v)->unique()->reject(fn ($id) => isset($listed[$id]))->values()->all();
            foreach ($people($guardianIds) as $u) {
                if (! ($n = $nextOf($u->date_of_birth ?: $u->profile_dob))) continue;
                [$next, $days] = $n;
                $list[] = $entry(trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: 'Parent', $u->photo_url ?? null, $u->sex ?? null,
                    $next, $days, 'parent', null, 'Parent');
            }
        }

        usort($list, fn ($a, $b) => [$a['in_days'], $a['kind'] === 'child' ? 0 : 1, $a['name']] <=> [$b['in_days'], $b['kind'] === 'child' ? 0 : 1, $b['name']]);
        return response()->json(['birthdays' => $list]);
    }

    /** GET /admin/floor-staff — staff active in the app in the last 10 min (id + name), for the clickable presence widget. */
    public function floorStaff(Request $request): JsonResponse
    {
        /* STAFF ONLY (2026-10-21). The route sits in the any-signed-in-user group, and this
           had no role check of its own: a parent could list which staff were clocked in right
           now, with names, photos and online status. */
        $isStaff = \App\Support\AgencyRole::constrain(DB::table('role_assignments'))->where('user_id', $request->user()->id)->where('active', true)
            ->whereIn('role', ['educator', 'home_visitor', 'centre_director', 'agency_admin', 'platform_admin'])->exists();
        if (! $isStaff) {
            return response()->json(['staff' => []], 403);
        }
        $agencyId = $this->agencyId($request);
        if (!$agencyId) return response()->json(['staff' => []]);
        $centreIds = $this->centreIds($agencyId);
        /* A centre-scoped viewer sees their own centres' floor, plus the agency office (2026-11-05 leak sweep): an
           educator at one centre listed who was clocked in at every centre of the agency. Agency-wide roles and a
           home visitor (who works across the agency by design) keep the whole agency. */
        if (! in_array('home_visitor', \App\Support\AgencyRole::names($request), true)) {
            $fsScope = \App\Support\CentreScope::ids($request, (int) $agencyId);
            if ($fsScope !== null) $centreIds = array_values(array_intersect(array_map('intval', $centreIds), $fsScope));
        }

        $staffIds = DB::table('role_assignments')
            ->where('active', true)
            ->whereIn('role', ['educator', 'centre_director', 'agency_admin', 'home_visitor'])
            ->where(function ($x) use ($agencyId, $centreIds) {
                $x->where('agency_id', $agencyId);
                if (!empty($centreIds)) $x->orWhereIn('centre_id', $centreIds);
            })
            ->pluck('user_id')->unique()->all();

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

        /* THE OFFICE IS ON THE FLOOR TOO (2026-11-03, Anthony: "team on floor should show the admin, director and
           superadmin staff as well"). They never clock in, so the clock-in test above left them out. They count while
           they are using KiddieTrac (a request in the last 15 minutes): agency admins, directors of these centres, and
           a superadmin only while this is the agency they are working in (users.last_agency_id) - never shown to
           another agency's team. */
        $office = DB::table('role_assignments')->where('active', true)
            ->where(function ($x) use ($agencyId, $centreIds) {
                $x->where(fn ($w) => $w->where('role', 'agency_admin')->where('agency_id', $agencyId))
                  ->orWhere(function ($w) use ($agencyId, $centreIds) {
                      $w->where('role', 'centre_director')->where(function ($v) use ($agencyId, $centreIds) {
                          $v->where('agency_id', $agencyId);
                          if (! empty($centreIds)) $v->orWhereIn('centre_id', $centreIds);
                      });
                  });
            })->pluck('user_id')->map(fn ($v) => (int) $v)->all();
        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'last_agency_id')) {
            $supers = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
                ->where('ra.role', 'platform_admin')->where('ra.active', true)->where('u.last_agency_id', $agencyId)
                ->pluck('u.id')->map(fn ($v) => (int) $v)->all();
            $office = array_merge($office, $supers);
        }
        $office = array_values(array_unique($office));
        if ($office) {
            $activeOffice = DB::table('personal_access_tokens')->whereIn('tokenable_id', $office)
                ->where('last_used_at', '>=', now()->subMinutes(15))->distinct()->pluck('tokenable_id')->map(fn ($v) => (int) $v)->all();
            $activeOffice = DB::table('users')->whereIn('id', $activeOffice ?: [0])->whereNull('deleted_at')->where('status', 'active')
                ->pluck('id')->map(fn ($v) => (int) $v)->all();
            $online = array_values(array_unique(array_merge(array_map('intval', $online), $activeOffice)));
        }
        if (empty($online)) return response()->json(['staff' => []]);

        // Primary role per user (for the role pill next to the name).
        $roleRank = ['platform_admin' => 5, 'agency_admin' => 4, 'centre_director' => 3, 'home_visitor' => 2, 'educator' => 1];
        $roleLabels = ['platform_admin' => 'Superadmin', 'agency_admin' => 'Admin', 'centre_director' => 'Director', 'home_visitor' => 'Home visitor', 'educator' => 'Educator'];
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
        $isStaff = \App\Support\AgencyRole::constrain(DB::table('role_assignments'))->where('user_id', $sender->id)->where('active', true)
            ->whereIn('role', ['educator', 'centre_director', 'agency_admin', 'platform_admin'])->exists();
        if (!$isStaff) abort(403);

        // SECURITY: only allow notifying a user who shares one of the sender's active
        // agencies/centres (platform_admin excepted) — no cross-tenant pings by user id.
        $isPlatform = \App\Support\AgencyRole::constrain(DB::table('role_assignments'))->where('user_id', $sender->id)->where('active', true)->where('role', 'platform_admin')->exists();
        if (!$isPlatform) {
            $myAgencies = DB::table('role_assignments')->where('user_id', $sender->id)->where('active', true)->pluck('agency_id')->filter()->all();
            $myCentres = DB::table('role_assignments')->whereIn('role', \App\Support\Visibility::STAFF_ROLES)->where('user_id', $sender->id)->where('active', true)->pluck('centre_id')->filter()->all();
            $shares = DB::table('role_assignments')->where('user_id', $data['user_id'])->where('active', true)
                ->where(function ($q) use ($myAgencies, $myCentres) {
                    if (!empty($myAgencies)) $q->orWhereIn('agency_id', $myAgencies);
                    if (!empty($myCentres)) $q->orWhereIn('centre_id', $myCentres);
                })->exists();
            $sharesFamily = DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')
                ->where('g.user_id', $data['user_id'])->whereIn('f.centre_id', $myCentres ?: [0])->exists();
            if (!$shares && !$sharesFamily) abort(403);
        }

        /* THE RECIPIENT MUST BE IN YOUR SCOPE IN THE AGENCY YOU ARE WORKING IN (2026-11-05 leak sweep). The check
           above counted the sender's roles and centres in ANY agency, and any role row of the recipient (a parent's
           guardian row included), so an educator could ping every parent and colleague of the agency, and a
           platform admin anyone at all. Now: staff of a centre you work at, or the agency's own office (agency-level
           staff); parents only of a live family at a centre you work at. Agency-wide roles (and a home visitor, who
           works across the agency) have every centre of the agency. */
        $agencyId = $this->agencyId($request);
        if (! $agencyId) abort(403);
        $agencyCentres = array_map('intval', $this->centreIds((int) $agencyId));
        $qnScope = in_array('home_visitor', \App\Support\AgencyRole::names($request), true)
            ? null : \App\Support\CentreScope::ids($request, (int) $agencyId);
        $myScopeCentres = $qnScope === null ? $agencyCentres : array_values(array_intersect($agencyCentres, $qnScope));
        $targetId = (int) $data['user_id'];
        $inScopeStaff = DB::table('role_assignments')->where('user_id', $targetId)->where('active', true)
            ->where(function ($q) use ($agencyId, $myScopeCentres) {
                // The agency office: admins, auditors, home visitors of THIS agency.
                $q->where(fn ($w) => $w->whereIn('role', ['agency_admin', 'auditor', 'home_visitor'])->where('agency_id', $agencyId))
                  // Staff at a centre the sender works at.
                  ->orWhere(fn ($w) => $w->whereIn('role', \App\Support\Visibility::STAFF_ROLES)->whereIn('centre_id', $myScopeCentres ?: [0]));
            })->exists()
            // A superadmin currently working in this agency (the floor widget lists them the same way).
            || (\Illuminate\Support\Facades\Schema::hasColumn('users', 'last_agency_id')
                && DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
                    ->where('ra.user_id', $targetId)->where('ra.role', 'platform_admin')->where('ra.active', true)
                    ->where('u.last_agency_id', $agencyId)->exists());
        $inScopeParent = DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')
            ->where('g.user_id', $targetId)->whereNull('f.deleted_at')->whereIn('f.centre_id', $myScopeCentres ?: [0])->exists();
        if ($targetId !== (int) $sender->id && ! $inScopeStaff && ! $inScopeParent) {
            return response()->json(['message' => 'You can only message people at your centres.'], 403);
        }

        $sn = DB::table('users')->where('id', $sender->id)->first();
        $senderName = trim(($sn->first_name ?? '') . ' ' . ($sn->last_name ?? '')) ?: 'Your team';

        // If the recipient is a colleague (staff), persist this quick message as a
        // real staff DM thread so it shows up — and can be replied to — under Team
        // messages, not just as a fire-and-forget bell notification.
        $recipientIsStaff = \App\Support\AgencyRole::constrain(DB::table('role_assignments'))->where('user_id', $data['user_id'])->where('active', true)
            ->whereIn('role', ['educator', 'centre_director', 'agency_admin', 'platform_admin', 'home_visitor'])->exists();
        $link = '#notifications';
        if ($recipientIsStaff && (int) $data['user_id'] !== (int) $sender->id) {
            $link = $this->quickMessageToThread((int) $sender->id, (int) $data['user_id'], (string) $data['message']);
        }

        \App\Support\Notify::write([
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
        /* Shared with the chat + broadcast paths. The copy that used to live here
           took the agency from the SENDER's first role assignment — for a platform
           admin that is an arbitrary agency, filing the thread under the wrong one.
           PrivateThreads takes the agency it is given. */
        $agencyId = DB::table('role_assignments')->where('user_id', $recipientId)->where('active', true)
            ->whereNotNull('agency_id')->value('agency_id');
        $threadId = \App\Support\PrivateThreads::findOrCreate($senderId, $recipientId, $agencyId ? (int) $agencyId : null);

        DB::table('staff_messages')->insert([
            'thread_id' => $threadId, 'sender_id' => $senderId, 'body' => trim($body),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('staff_threads')->where('id', $threadId)->update(['last_message_at' => $now, 'updated_at' => $now]);

        return '#team-messages';
    }
}
