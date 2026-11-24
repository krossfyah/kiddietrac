<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * GET /provider/day-brief — an educator's "what to expect today" start-of-day
 * summary. Aggregates attendance, lesson plan, meals, health, messages, tasks,
 * forms, announcements, incidents, birthdays, and new enrolments for the rooms
 * the caller works in. Every section is independently guarded so a missing
 * source degrades to "—" instead of failing the whole brief.
 */
final class DayBriefController extends Controller
{
    use ResolvesCentreContext;

    public function brief(Request $request): JsonResponse
    {
        $user = $request->user();
        $tz = AgencyTime::tz($this->callerAgencyId($request));
        $todayStr = now($tz)->toDateString();
        /* $todayStr is already the agency's date, which is why this one looked
           correct — but whereDate() buckets occurred_at by its UTC date, so the
           window was still shifted. An instant needs instant bounds. */
        [$dayFrom, $dayTo] = AgencyTime::dayRange($this->callerAgencyId($request));

        // Centres the caller works in (educator: assigned centres; director/admin:
        // agency centres). Rooms follow from those centres.
        [$centreIds, $roomIds] = $this->scope($request, $user);

        $items = [];
        $add = function (string $key, string $icon, string $label, $value, string $detail = '', string $tone = 'info', string $hash = '') use (&$items) {
            $items[] = compact('key', 'icon', 'label', 'value', 'detail', 'tone', 'hash');
        };

        // ── Today's lesson plan ─────────────────────────────────────────
        // The educator's own room first, then the centre-wide plan a room inherits when
        // it has none of its own — the same order the planner and both summary emails
        // use, via the same helper, so the day brief cannot disagree with them.
        try {
            $lpRooms = $roomIds instanceof \Illuminate\Support\Collection ? $roomIds->all() : (array) $roomIds;
            $lpCentres = $centreIds instanceof \Illuminate\Support\Collection ? $centreIds->all() : (array) $centreIds;
            $lpCentre = $lpCentres ? (int) reset($lpCentres) : null;

            $plan = ['theme' => null, 'items' => []];
            foreach (array_slice(array_values($lpRooms), 0, 8) as $rid) {
                $p = \App\Support\LessonPlans::forDate((int) $rid, $lpCentre, $todayStr);
                if ($p['items']) { $plan = $p; break; }
            }
            if (! $plan['items'] && $lpCentre) {
                $plan = \App\Support\LessonPlans::forDate(null, $lpCentre, $todayStr);
            }
            // Still nothing: a plan may exist for a room this person is not assigned to —
            // often because nobody is assigned to it at all. Showing it, named, beats
            // showing nothing and looking like the feature was never built.
            if (! $plan['items']) {
                $plan = \App\Support\LessonPlans::forDateInCentres($lpCentres, $todayStr);
            }

            if ($plan['items']) {
                $n = count($plan['items']);
                $first = $plan['items'][0];
                $add(
                    'lesson-plan', '📘', 'Today’s plan',
                    ($plan['theme'] ?: ($n . ' activit' . ($n === 1 ? 'y' : 'ies')))
                        . (! empty($plan['room_name']) ? ' · ' . $plan['room_name'] : ''),
                    trim(($first['time_label'] ? $first['time_label'] . ' · ' : '') . $first['title'])
                        . ($n > 1 ? ' +' . ($n - 1) . ' more' : ''),
                    'info', '#lesson-plans'
                );
            }
        } catch (\Throwable $e) {
            // A day brief is more useful without this tile than not at all.
        }

        // ── Attendance: expected / in / out / late pickups ──────────────
        /* THIS TILE IS THE WHOLE CENTRE, AND NOW SAYS SO (2026-11-03).

           Saurav's Today screen read "0 in · 3 not in yet · 7 left · 10 enrolled today" right
           above a headcount of "0 of 2 here now" for his room, and nothing said that one was
           Sunny Meadows and the other Discovery Preschool. The scope is unchanged (the
           centres on the caller's own role_assignments); the label now names it.

           Three computation fixes, each of which can only REMOVE children or events:
             - closed centres and families that have left are not "enrolled" (App\Support\Live);
             - an event stamped later than now cannot say where a child is now (the roster's rule);
             - "not in yet" counts only children BOOKED today (App\Support\ScheduledToday, the
               rule the provider cards and Daily Overview use). A child not booked today is
               neither late nor absent; they are counted on the detail line instead.
           After the centre's closing time nobody is "not in yet" any more: they did not come in.
           "left" becomes "picked up", the word the roster under it uses for the same thing. */
        try {
            $liveCentres = \App\Support\Live::onlyOpenCentres(array_map('intval', (array) $centreIds));
            $kidRows = \App\Support\Live::families(DB::table('children as c')
                ->join('families as f', 'f.id', '=', 'c.family_id'), 'f')
                ->whereIn('f.centre_id', $liveCentres ?: [0])
                ->where('c.enrollment_status', 'enrolled')
                ->whereNull('f.suspended_at')
                ->whereNull('c.deleted_at')
                ->get(['c.id', 'f.centre_id']);
            $childIds = $kidRows->pluck('id')->map(fn ($v) => (int) $v)->all();
            $expected = count($childIds);
            $lastByChild = [];
            if ($childIds) {
                foreach (DB::table('check_events')->whereIn('child_id', $childIds)
                    ->where('occurred_at', '>=', $dayFrom)->where('occurred_at', '<', $dayTo)
                    ->where('occurred_at', '<=', now())
                    ->orderBy('occurred_at')->orderBy('id')
                    ->get(['child_id', 'event_type', 'occurred_at']) as $e) {
                    $lastByChild[(int) $e->child_id] = $e;
                }
            }
            $present = 0; $out = 0;
            foreach ($lastByChild as $e) { $e->event_type === 'check_in' ? $present++ : $out++; }

            // Of those who have not been in at all today: booked today, or not booked?
            $byCentre = [];
            foreach ($kidRows as $r) { $byCentre[(int) $r->centre_id][] = (int) $r->id; }
            $sched = [];
            try {
                $open = \App\Support\ScheduledToday::centresOpen(array_keys($byCentre), $todayStr);
                foreach (\App\Support\ScheduledToday::children($byCentre, $todayStr, $open) as $m) {
                    foreach ($m as $cid => $yes) { $sched[(int) $cid] = (bool) $yes; }
                }
            } catch (\Throwable $e3) { $sched = []; }   // no schedule answer -> everyone counts as booked, as before
            $notInYet = 0; $notBooked = 0;
            foreach ($childIds as $cid) {
                if (isset($lastByChild[$cid])) continue;
                (array_key_exists($cid, $sched) && ! $sched[$cid]) ? $notBooked++ : $notInYet++;
            }

            // Past closing time (the latest close of the caller's centres), "not in yet" is over.
            $closed = false;
            try {
                $closes = DB::table('centres')->whereIn('id', $liveCentres ?: [0])->pluck('close_time')->filter()->all();
                if ($closes) {
                    $nowLocal = now($tz);
                    $closed = $nowLocal->gt(Carbon::parse($todayStr . ' ' . max($closes), $tz));
                }
            } catch (\Throwable $e4) { $closed = false; }

            // Fixed wording (no centre name) so kt-i18n's number templates still translate it.
            $one = count($liveCentres) <= 1;
            $detail = $one ? "{$expected} enrolled at centre" : "{$expected} enrolled at your centres";
            if ($notBooked > 0) $detail .= " · {$notBooked} not booked today";
            $add('attendance', '👶', $one ? 'At Centre' : 'At your centres',
                "{$present} at Centre · {$out} not at Centre · {$notInYet} " . ($closed ? 'did not come' : 'not arrived yet'),
                $detail, $present > 0 ? 'good' : 'info', '#today');

            // Late pickups: still checked in and past the centre close time.
            $closeCandidates = 0;
            $closeTimes = DB::table('centres')->whereIn('id', $centreIds ?: [0])->pluck('close_time', 'id');
            $nowT = now($tz);
            foreach ($lastByChild as $cid => $e) {
                if ($e->event_type !== 'check_in') continue;
                // We don't have per-child centre here cheaply; use the earliest close.
                $close = null;
                foreach ($closeTimes as $ct) { if ($ct && (!$close || $ct < $close)) $close = $ct; }
                if ($close) {
                    try { $c = Carbon::parse($todayStr . ' ' . $close, $tz); if ($nowT->gt($c)) $closeCandidates++; } catch (\Throwable $e2) {}
                }
            }
            if ($closeCandidates > 0) {
                $add('late_pickup', '🕒', 'Late pickups', "{$closeCandidates} past close time", 'Still checked in', 'warn', '#today');
            }
        } catch (\Throwable $e) {}

        // ── Lesson plan for this week ───────────────────────────────────
        try {
            $weekStart = now($tz)->startOfWeek(Carbon::MONDAY)->toDateString();
            $plan = DB::table('lesson_plans')->where('week_starting', $weekStart)
                ->where(function ($q) use ($roomIds, $centreIds) {
                    $q->whereIn('room_id', $roomIds ?: [0])->orWhereIn('centre_id', $centreIds ?: [0]);
                })->orderByDesc('updated_at')->first();
            if ($plan) {
                $add('lesson_plan', '📚', 'Lesson plan', 'Ready for this week', $plan->theme ? ('Theme: ' . $plan->theme) : '', 'good', '#lesson-plans');
            } else {
                $add('lesson_plan', '📚', 'Lesson plan', 'Not set for this week', 'Add one so parents can see it', 'warn', '#lesson-plans');
            }
        } catch (\Throwable $e) {}

        // ── Weekly meal plan (menu) for this week ───────────────────────
        // Same "needs action" nudge as the lesson plan: if this week's menu isn't
        // set for the centre, prompt the educator to add it. Week match is a date
        // RANGE (covers today) so it works whether the menu week starts Sun or Mon.
        try {
            $menu = DB::table('menu_weeks')
                ->whereIn('centre_id', $centreIds ?: [0])
                ->whereDate('week_start', '<=', $todayStr)
                ->whereRaw('DATE_ADD(week_start, INTERVAL 6 DAY) >= ?', [$todayStr])
                ->orderByDesc('updated_at')->first();
            if ($menu) {
                $published = (($menu->status ?? '') === 'published') || ! empty($menu->published_at);
                $add('meal_plan', '🥗', 'Meal plan', $published ? 'Set for this week' : 'Draft saved',
                    $published ? '' : 'Publish it so parents can see it', $published ? 'good' : 'warn', '#menu');
            } else {
                $add('meal_plan', '🥗', 'Meal plan', 'Not set for this week',
                    "Add this week's menu so parents can see it", 'warn', '#menu');
            }
        } catch (\Throwable $e) {}

        // ── Meals / menu today ──────────────────────────────────────────
        try {
            $mealCount = DB::table('daily_events')
                ->whereIn('room_id', $roomIds ?: [0])
                ->where('occurred_at', '>=', $dayFrom)->where('occurred_at', '<', $dayTo)
                ->whereIn('event_type', ['meal', 'snack'])->count();
            $add('meals', '🍽️', 'Meals logged', $mealCount . ' today', '', 'info', '#care-log');
        } catch (\Throwable $e) {}

        // ── Health: active severe allergies/alerts + NEW this week ──────
        try {
            $childIds = $childIds ?? [];
            $activeSevere = DB::table('child_health_flags')
                ->whereIn('child_id', $childIds ?: [0])->where('active', true)
                ->whereIn('severity', ['severe', 'life_threatening'])->count();
            $newFlags = DB::table('child_health_flags')
                ->whereIn('child_id', $childIds ?: [0])->where('active', true)
                ->where('created_at', '>=', now($tz)->subDays(7))->count();
            if ($activeSevere > 0 || $newFlags > 0) {
                $add('health', '🚑', 'Allergies / alerts',
                    $activeSevere . ' active severe',
                    $newFlags > 0 ? ($newFlags . ' new this week') : '',
                    $activeSevere > 0 ? 'warn' : 'info', '#today');
            }
        } catch (\Throwable $e) {}

        // ── New medications (started in the last 7 days) ────────────────
        try {
            $newMeds = DB::table('medications')
                ->whereIn('centre_id', $centreIds ?: [0])
                ->where('starts_on', '>=', now($tz)->subDays(7)->toDateString())->count();
            if ($newMeds > 0) $add('medications', '💊', 'New medications', $newMeds . ' this week', '', 'warn', '#medications');
        } catch (\Throwable $e) {}

        // ── Unread messages in the centre's conversations ───────────────
        try {
            $unread = DB::table('messages as m')
                ->join('conversations as cv', 'cv.id', '=', 'm.conversation_id')
                ->whereIn('cv.centre_id', $centreIds ?: [0])
                ->whereNull('m.read_at')
                ->where('m.sender_id', '!=', $user->id)
                ->whereNull('cv.deleted_at')
                ->count();
            if ($unread > 0) $add('messages', '💬', 'Unread messages', (string) $unread, '', 'info', '#messages');
        } catch (\Throwable $e) {}

        // ── My open tasks (due today or overdue) ────────────────────────
        try {
            $openTasks = DB::table('tasks')->where('assigned_to', $user->id)
                ->whereNotIn('status', ['done', 'completed', 'cancelled'])->count();
            $dueToday = DB::table('tasks')->where('assigned_to', $user->id)
                ->whereNotIn('status', ['done', 'completed', 'cancelled'])
                ->whereDate('due_date', '<=', $todayStr)->count();
            if ($openTasks > 0) $add('tasks', '✅', 'My tasks', $openTasks . ' open', $dueToday > 0 ? ($dueToday . ' due/overdue') : '', $dueToday > 0 ? 'warn' : 'info', '#my-tasks');
        } catch (\Throwable $e) {}

        // ── Forms to fill (staff-audience, active) ──────────────────────
        try {
            $forms = DB::table('custom_forms')->whereIn('centre_id', $centreIds ?: [0])
                ->where('status', 'active')
                ->where(function ($q) { $q->where('audience', 'like', '%educator%')->orWhere('audience', 'like', '%staff%')->orWhere('audience', 'like', '%all%'); })
                ->count();
            if ($forms > 0) $add('forms', '📝', 'Forms', $forms . ' to complete', '', 'info', '#inspection-forms');
        } catch (\Throwable $e) {}

        // ── Recent announcements (last 3 days) ──────────────────────────
        try {
            /* Scoped like AnnouncementController's staff feed (2026-10-31): agency-wide news of the ACTIVE agency
               only (this counted every agency's agency-wide announcements), the caller's centres and rooms, and
               personal announcements only when they are the caller's own. */
            $annAgency = (int) ($this->callerAgencyId($request) ?: 0);
            $annCentres = $centreIds instanceof \Illuminate\Support\Collection ? $centreIds->all() : (array) $centreIds;
            $annRooms = $roomIds instanceof \Illuminate\Support\Collection ? $roomIds->all() : (array) $roomIds;
            $ann = DB::table('announcements')
                ->whereNotNull('sent_at')->where('sent_at', '>=', now($tz)->subDays(3))
                ->where(function ($q) use ($annAgency, $annCentres, $annRooms, $user) {
                    $q->where(function ($qq) use ($annAgency) {
                        $qq->where('scope_type', 'agency')->where('scope_id', $annAgency);
                    })->orWhere(function ($qq) use ($annCentres) {
                        $qq->where('scope_type', 'centre')->whereIn('scope_id', $annCentres ?: [0]);
                    })->orWhere(function ($qq) use ($annRooms) {
                        $qq->where('scope_type', 'room')->whereIn('scope_id', $annRooms ?: [0]);
                    })->orWhere(function ($qq) use ($user, $annAgency) {
                        $qq->where('scope_type', 'user')->where('scope_id', (int) $user->id)
                            ->where(function ($w) use ($annAgency) { $w->whereNull('agency_id')->orWhere('agency_id', $annAgency); });
                    });
                })->count();
            if ($ann > 0) $add('announcements', '📣', 'News', $ann . ' new', 'In the last 3 days', 'info', '#announcements');
        } catch (\Throwable $e) {}

        // ── Today's incidents in the centre ─────────────────────────────
        try {
            $inc = DB::table('incidents')->whereIn('room_id', $roomIds ?: [0])
                ->where('occurred_at', '>=', $dayFrom)->where('occurred_at', '<', $dayTo)->count();
            if ($inc > 0) $add('incidents', '⚠️', 'Incidents today', (string) $inc, '', 'warn', '#incidents');
        } catch (\Throwable $e) {}

        // ── Birthdays today ─────────────────────────────────────────────
        try {
            $md = now($tz)->format('m-d');
            $bdays = DB::table('children as c')->join('families as f', 'f.id', '=', 'c.family_id')
                ->whereIn('f.centre_id', $centreIds ?: [0])->where('c.enrollment_status', 'enrolled')
                ->whereNotNull('c.date_of_birth')
                ->whereRaw("DATE_FORMAT(c.date_of_birth, '%m-%d') = ?", [$md])
                ->pluck('c.first_name')->all();
            if ($bdays) $add('birthdays', '🎂', 'Birthday today', count($bdays) === 1 ? ($bdays[0] . '!') : (count($bdays) . ' children'), implode(', ', array_slice($bdays, 0, 3)), 'good', '#dashboard');
        } catch (\Throwable $e) {}

        // ── Potential new enrolments (applied / waitlist) ───────────────
        try {
            $pending = DB::table('children as c')->join('families as f', 'f.id', '=', 'c.family_id')
                ->whereIn('f.centre_id', $centreIds ?: [0])
                ->whereIn('c.enrollment_status', ['applied', 'waitlist', 'waitlisted', 'pending'])
                ->whereNull('c.deleted_at')->count();
            if ($pending > 0) $add('enrolments', '🌱', 'New enrolments', $pending . ' in the pipeline', 'Applied / waitlisted', 'info', '#waitlist');
        } catch (\Throwable $e) {}

        $warn = count(array_filter($items, fn ($i) => $i['tone'] === 'warn'));
        $headline = $warn > 0
            ? "{$warn} thing" . ($warn === 1 ? ' needs' : 's need') . ' your attention today'
            : 'You’re all set — have a great day!';

        return response()->json([
            'date' => $todayStr,
            'headline' => $headline,
            'attention_count' => $warn,
            'items' => $items,
        ]);
    }

    /**
     * GET /provider/day-activity?centre_id=&date= — a director/admin quick glance
     * at ONE provider's day (today or any past date): summary counts + a
     * chronological feed of what happened (check-ins/outs + care moments).
     */
    public function dayActivity(Request $request): JsonResponse
    {
        $user = $request->user();
        $agencyId = $this->callerAgencyId($request);
        $tz = AgencyTime::tz($agencyId);
        $centreId = (int) $request->query('centre_id');
        $date = $request->query('date') ?: now($tz)->toDateString();
        // The agency's local day, expressed as a UTC window — occurred_at is stored
        // in UTC, so an evening (Eastern) event rolls into the next UTC calendar day;
        // whereDate on the raw column would drop it from the correct local day.
        $dayStart = Carbon::parse($date . ' 00:00:00', $tz)->timezone('UTC');
        $dayEnd = Carbon::parse($date . ' 23:59:59', $tz)->timezone('UTC');

        // Access: the centre must be in the caller's agency (platform/agency admins
        // + directors see any centre in-agency; educators are already centre-scoped).
        $centre = DB::table('centres')->where('id', $centreId)->first();
        if (! $centre) {
            return response()->json(['message' => 'Provider not found'], 404);
        }
        // A platform_admin (super-admin) may view ANY centre — never wall them off,
        // even if their active-agency header is absent or set to another agency.
        $isPlatform = DB::table('role_assignments')->where('user_id', $user->id)
            ->where('role', 'platform_admin')->where('active', true)->exists();
        if (! $isPlatform && $agencyId && (int) $centre->agency_id !== (int) $agencyId) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $children = DB::table('children as c')->join('families as f', 'f.id', '=', 'c.family_id')
            ->where('f.centre_id', $centreId)->whereNull('c.deleted_at')
            ->whereNull('f.suspended_at')
            /* ONLY CHILDREN STILL HERE (2026-11-03). Anthony: "old children under her care is showing up again in the
               list under clock in/out". This filtered deleted children and suspended families only, so every withdrawn
               child whose family had left stayed on the roster. App\Support\Live is the one definition; the one exception
               is a day they were actually signed in or out, which is that day's attendance record (an older date). */
            ->where(fn ($w) => $w->where(fn ($live) => \App\Support\Live::families(\App\Support\Live::children($live, 'c'), 'f'))
                ->orWhereExists(fn ($x) => $x->from('check_events as ce')->whereColumn('ce.child_id', 'c.id')
                    ->whereBetween('ce.occurred_at', [$dayStart, $dayEnd])))
            /* primary_room_id so the roster can offer a manual sign in/out —
               POST /provider/check-in needs a room, and the Daily Overview is the only
               attendance screen an agency admin can actually reach. (2026-08-26) */
            ->get(['c.id', 'c.first_name', 'c.last_name', 'c.preferred_name', 'c.photo_url',
                   'c.gender', 'c.primary_room_id']);
        $nameById = [];
        foreach ($children as $c) {
            $nameById[$c->id] = $c->preferred_name ?: trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? ''));
        }
        $childIds = array_keys($nameById);

        $roomIds = DB::table('rooms')->where('centre_id', $centreId)->pluck('id')->all();

        // Timeline: check events + care/daily events for the date.
        $events = [];
        $checkTypes = ['check_in' => ['✅', 'Signed in'], 'check_out' => ['👋', 'Signed out']];
        if ($childIds) {
            foreach (DB::table('check_events')->whereIn('child_id', $childIds)
                ->whereBetween('occurred_at', [$dayStart, $dayEnd])->orderBy('id')
                ->get(['id', 'child_id', 'event_type', 'occurred_at', 'created_at', 'notes']) as $e) {
                $m = $checkTypes[$e->event_type] ?? ['•', ucfirst((string) $e->event_type)];
                $events[] = [
                    'at' => $e->occurred_at, 'time' => AgencyTime::fmt($e->occurred_at, $tz),
                    'logged_at' => $e->created_at ?? $e->occurred_at, 'seq' => (int) $e->id,
                    'icon' => $m[0], 'title' => $m[1], 'child' => $nameById[$e->child_id] ?? 'Child',
                    'detail' => $e->notes ?? '',
                ];
            }
            $ICON = ['meal' => '🍽️', 'snack' => '🍎', 'nap_start' => '😴', 'nap_end' => '🌅', 'diaper' => '🧷', 'bathroom' => '🚽', 'activity' => '✨', 'mood' => '🙂', 'note' => '📝', 'bottle' => '🍼', 'outdoor' => '🌳'];
            foreach (DB::table('daily_events')->whereIn('child_id', $childIds)
                ->whereBetween('occurred_at', [$dayStart, $dayEnd])->orderBy('id')
                ->get(['id', 'child_id', 'event_type', 'occurred_at', 'created_at', 'payload', 'notes']) as $e) {
                $p = is_string($e->payload) ? (json_decode($e->payload, true) ?: []) : ((array) ($e->payload ?? []));
                // A shared photo or video is filed as event_type 'note' (daily_events
                // has an enum, so the real shape rides in the payload) — which meant
                // capturing a moment showed up in Activities & daily logs as a bare
                // "Note". Name it for what it is, and keep the caption as the detail.
                $isMedia = ($p['kind'] ?? '') === 'media';
                $isVideoEvent = $isMedia && (($p['media_type'] ?? '') === 'video');
                $title = $isMedia
                    ? ($isVideoEvent ? 'Video captured' : 'Photo captured')
                    : match ($e->event_type) {
                        'meal', 'snack' => ucfirst($p['meal'] ?? $e->event_type),
                        'nap_start' => 'Started nap', 'nap_end' => 'Woke from nap',
                        'diaper' => 'Diaper (' . ($p['type'] ?? 'changed') . ')',
                        'activity' => \App\Support\PlaySpark::activityTitle($p),
                        'mood' => 'Mood: ' . ($p['score'] ?? '—'),
                        default => str_replace('_', ' ', ucfirst((string) $e->event_type)),
                    };
                $events[] = [
                    'at' => $e->occurred_at, 'time' => AgencyTime::fmt($e->occurred_at, $tz),
                    'logged_at' => $e->created_at ?? $e->occurred_at, 'seq' => (int) $e->id,
                    'icon' => $isMedia ? ($isVideoEvent ? '🎥' : '📸') : ($ICON[$e->event_type] ?? '•'),
                    'title' => $title,
                    'child' => $nameById[$e->child_id] ?? 'Child',
                    // The caption is the description the educator typed when capturing.
                    'detail' => $isMedia ? (string) ($p['note'] ?? $e->notes ?? '')
                        : (\App\Support\PlaySpark::isRun($p) ? \App\Support\PlaySpark::domainsText($p) : ($e->notes ?? '')),
                    // The front end draws the Play Spark mark instead of the icon for these.
                    'brand' => \App\Support\PlaySpark::isRun($p) ? 'play-spark' : null,
                    'media_type' => $isMedia ? ($isVideoEvent ? 'video' : 'photo') : null,
                    'photo_id' => $isMedia ? ($p['photo_id'] ?? null) : null,
                ];
            }
            // ALSO the "Log a moment" entries — these write to daily_care_logs, a
            // SEPARATE table from the roster quick-log's daily_events. Reading only
            // daily_events meant a provider's logged moments never showed here.
            $CICON = ['diaper' => '🧷', 'bathroom' => '🚽', 'nap' => '😴', 'meal' => '🍽️', 'snack' => '🍎', 'bottle' => '🍼', 'sunscreen' => '☀️', 'mood' => '🙂', 'outdoor' => '🌳', 'medicine' => '💊', 'clothing' => '👕'];
            foreach (DB::table('daily_care_logs')->whereIn('child_id', $childIds)
                ->whereBetween('occurred_at', [$dayStart, $dayEnd])->orderBy('id')
                ->get(['id', 'child_id', 'log_type', 'occurred_at', 'created_at', 'details', 'notes']) as $e) {
                $events[] = [
                    'at' => $e->occurred_at, 'time' => AgencyTime::fmt($e->occurred_at, $tz),
                    'logged_at' => $e->created_at ?? $e->occurred_at, 'seq' => (int) $e->id,
                    'icon' => $CICON[$e->log_type] ?? '📝',
                    'title' => ucfirst((string) $e->log_type) . ($e->details ? ' — ' . $e->details : ''),
                    'child' => $nameById[$e->child_id] ?? 'Child', 'detail' => $e->notes ?? '',
                ];
            }
        }
        // Absences, so the feed shows why a child has nothing logged against them
        // rather than leaving a silent gap that reads as an educator who forgot.
        //
        // Read in here rather than written as a daily_events row on the way in:
        // event_type is an ENUM with no 'absence' member, and those rows are what an
        // educator's activity counts are built from — an absence would inflate the
        // score of whoever recorded it, the same miscounting already fixed in the
        // daily summaries.
        if (!empty($childIds)) {
            foreach (DB::table('child_absences as ab')
                ->leftJoin('users as u', 'u.id', '=', 'ab.reported_by_id')
                ->whereIn('ab.child_id', $childIds)
                ->whereDate('ab.absent_on', $date)
                ->get([
                    'ab.id', 'ab.child_id', 'ab.reason', 'ab.note', 'ab.created_at',
                    DB::raw("COALESCE(NULLIF(TRIM(CONCAT(u.first_name,' ',u.last_name)),''),'') as reporter"),
                ]) as $ab) {
                $who = trim((string) $ab->reporter);
                $events[] = [
                    'at' => $ab->created_at,
                    'time' => AgencyTime::fmt($ab->created_at, $tz),
                    'logged_at' => $ab->created_at,
                    'seq' => (int) $ab->id,
                    'icon' => '🚫',
                    'title' => 'Marked absent' . ($ab->reason ? ' — ' . ucfirst((string) $ab->reason) : ''),
                    'child' => $nameById[$ab->child_id] ?? 'Child',
                    // No reporter means nothing was asserted by a person — an
                    // administrative record, not a report. Say that, rather than
                    // leaving a blank that invites the question of who marked it.
                    'detail' => trim(((string) ($ab->note ?? ''))
                        . ($who !== '' ? ' · reported by ' . $who : ' · recorded automatically, not reported by a person')),
                ];
            }
        }

        // ORDER OF RECORDING, not the time typed into the entry. Sorting by
        // occurred_at meant one mistyped time jumped its entry across the day and
        // pulled the sequence around it out of shape - in the one view you open to
        // work out what actually happened. created_at cannot be mistyped; the row id
        // breaks ties within the same second, and across the three tables the two
        // together keep entries in the order they were genuinely logged. The times
        // still display exactly as entered, so a wrong one now stands out against
        // its neighbours instead of quietly reshuffling them.
        usort($events, function ($a, $b) {
            $al = (string) ($a['logged_at'] ?? $a['at']);
            $bl = (string) ($b['logged_at'] ?? $b['at']);
            return strcmp($al, $bl) ?: (($a['seq'] ?? 0) <=> ($b['seq'] ?? 0));
        });

        // Summary counts for the date.
        $present = 0; $out = 0; $seen = [];
        foreach ($events as $e) {
            if ($e['title'] === 'Signed in') { $seen[$e['child']] = 'in'; }
            elseif ($e['title'] === 'Signed out') { $seen[$e['child']] = 'out'; }
        }
        foreach ($seen as $st) { $st === 'in' ? $present++ : $out++; }
        $incidents = $roomIds ? DB::table('incidents')->whereIn('room_id', $roomIds)->whereBetween('occurred_at', [$dayStart, $dayEnd])->count() : 0;
        $meals = 0; $naps = 0; $activities = 0;
        foreach ($events as $e) {
            if (in_array($e['icon'], ['🍽️', '🍎'], true)) $meals++;
            if (in_array($e['icon'], ['😴', '🌅'], true)) $naps++;
            if ($e['icon'] === '✨') $activities++;
        }

        // ── Per-child roster: in/out times, status, check-in source ─────
        $checksByChild = [];
        if ($childIds) {
            foreach (DB::table('check_events')->whereIn('child_id', $childIds)
                ->whereBetween('occurred_at', [$dayStart, $dayEnd])->orderBy('occurred_at')
                ->get(['child_id', 'event_type', 'occurred_at', 'kiosk_source', 'is_automatic', 'correction_source', 'original_auto_at']) as $ce) {
                $checksByChild[$ce->child_id][] = $ce;
            }
        }
        /* Not every child carries primary_room_id (5 of 20 in Test Agency), and without
           a room the roster cannot offer a sign in/out. Fall back to the room of their
           OPEN enrolment — the same room the educator roster uses. One grouped query,
           not one per child. (2026-08-26) */
        /* THE DAY BEING VIEWED, not today.

           constrain() defaults to today's day key, which was right while this screen only
           ever showed today. It takes a date, the Daily Overview has a date picker (and
           now day arrows), and a family can split its week across providers -- so looking
           back at last Wednesday resolved every child to whichever provider has them
           TODAY. Exactly the case CareSchedule exists for, answered wrongly.
           (Anthony, 2026-09-10) */
        $dayKey = \App\Support\CareSchedule::dayKey($tz, $date);

        $roomByChild = [];
        $scheduledIds = [];
        if ($childIds) {
            foreach (DB::table('enrollments')->whereIn('child_id', $childIds)
                ->whereNull('end_date')->orderBy('start_date')
                ->tap(fn ($q) => \App\Support\CareSchedule::constrain($q, 'enrollments', $dayKey))
                ->get(['child_id', 'room_id']) as $en) {
                $scheduledIds[(int) $en->child_id] = true;
                if ($en->room_id) { $roomByChild[$en->child_id] = (int) $en->room_id; }
            }
        }

        /* WHO IS DUE IN, as opposed to who is on the books.

           The roster is every child at the centre and stays that way: a child can be
           signed in on a day they were not booked (a swap, a parent working late), and a
           screen that hid them would hide a real attendance record. So this MARKS rather
           than filters — `scheduled` false means "not expected today", which is what makes
           an unexpected arrival worth noticing instead of invisible.

           A child with no open enrolment at all is left as expected (true) rather than
           flagged: they are unplaced, which is a different problem with its own warning,
           and calling them "not due in" would be a guess. */
        $hasEnrolment = $childIds
            ? DB::table('enrollments')->whereIn('child_id', $childIds)->whereNull('end_date')
                ->pluck('child_id')->map(fn ($v) => (int) $v)->flip()->all()
            : [];

        /* ONE RULE WITH THE OVERVIEW CARD (2026-11-01): App\Support\ScheduledToday also
           knows the centre's operating days and closures and which provider a split-week
           child is with that day. The unplaced child (no enrolment) stays expected, as above. */
        $__open = \App\Support\ScheduledToday::centresOpen([$centreId], $date);
        $__kidSched = \App\Support\ScheduledToday::children([$centreId => $childIds], $date, $__open)[$centreId] ?? [];

        $roster = [];
        $qr = 0; $manual = 0;
        foreach ($children as $c) {
            $evs = $checksByChild[$c->id] ?? [];

            /* THE VISIT THEY ARE IN, not the first one of the day.
               This kept the FIRST check_in and the LAST check_out, which is fine until a
               child leaves and comes back: Yahya signed in 08:21, out 10:41, back in 11:14
               and the row read "in 08:21 · out 10:41 · status in" — an out-time on a child
               who is present, and no sign the second arrival had registered at all. It was
               reported as the table not updating on the second sign-in, and from the row it
               is indistinguishable from exactly that.

               Events arrive ordered by occurred_at, so one pass is enough: an arrival opens
               a visit and clears whatever the last one closed with. The full day is still in
               the timeline below; this column answers "where are they now". */
            $inEv = null; $outEv = null;
            foreach ($evs as $e) {
                if ($e->event_type === 'check_in') {
                    $inEv = $e;
                    $outEv = null;     // a new arrival supersedes the previous departure
                    ((int) ($e->kiosk_source ?? 0) > 0) ? $qr++ : $manual++;
                }
                if ($e->event_type === 'check_out') { $outEv = $e; }
            }
            $last = $evs ? end($evs) : null;
            $status = ! $evs ? 'away' : ($last->event_type === 'check_in' ? 'in' : 'out');
            $roster[] = [
                'id' => $c->id, 'name' => $nameById[$c->id], 'photo_url' => $c->photo_url, 'gender' => $c->gender,
                /* The enrolment for THIS day wins over primary_room_id: primary_room_id is
                   one room and a split week has several, so preferring it put a child in
                   the wrong provider's list on the days they are elsewhere. */
                'room_id' => ($roomByChild[$c->id] ?? null) ?: $c->primary_room_id,
                'status' => $status,
                'scheduled' => $__kidSched[(int) $c->id] ?? (! isset($hasEnrolment[(int) $c->id]) || isset($scheduledIds[(int) $c->id])),
                'in' => $inEv ? AgencyTime::fmt($inEv->occurred_at, $tz) : null,
                'out' => $outEv ? AgencyTime::fmt($outEv->occurred_at, $tz) : null,
                'source' => $inEv ? ((int) ($inEv->kiosk_source ?? 0) > 0 ? 'QR' : 'Manual') : null,
                /* How the departure was recorded (2026-10-22): automatic, or the real time
                   entered by a parent from the automatic sign-out email, or by staff. */
                'out_how' => ! $outEv ? null : (($outEv->correction_source ?? null) === 'parent_email' ? 'parent_email'
                    : (($outEv->correction_source ?? null) ? 'corrected' : ((int) ($outEv->is_automatic ?? 0) ? 'automatic' : null))),
                'out_auto' => ($outEv && ($outEv->original_auto_at ?? null)) ? AgencyTime::fmt($outEv->original_auto_at, $tz) : null,
                /* Every visit, when there was more than one (2026-11-05): the in/out above
                   answers "where are they now", this keeps the earlier visits on the row. */
                'visits' => count($__v = \App\Support\ChildVisits::pair($evs)),
                'visits_text' => count($__v) > 1 ? \App\Support\ChildVisits::text($__v, $tz, 'now') : null,
                // Each visit for the table (2026-11-05: "if there are multiple clock in and out it needs to show here too for each child").
                'visit_list' => count($__v) > 1 ? array_map(fn ($v) => [
                    'in' => $v['in'] ? AgencyTime::fmt($v['in']->occurred_at, $tz) : null,
                    'out' => $v['out'] ? AgencyTime::fmt($v['out']->occurred_at, $tz) : null,
                    'out_how' => ! $v['out'] ? null : ((($v['out']->correction_source ?? null) === 'parent_email') ? 'parent_email'
                        : (($v['out']->correction_source ?? null) ? 'corrected' : ((int) ($v['out']->is_automatic ?? 0) ? 'automatic' : null))),
                ], $__v) : null,
            ];
        }
        // Running late (2026-11-06): staff logged "here by …" today (SafeArrivalController::late). Today, not-arrived children only.
        if ($date === now($tz)->toDateString() && \Illuminate\Support\Facades\Schema::hasTable('safe_arrival_late')) {
            $__late = \App\Support\SafeArrival::lateQuery($childIds, $date, 'arrival')->get(['child_id', 'arrive_by', 'note'])->keyBy('child_id');
            $__out = \App\Support\SafeArrival::lateQuery($childIds, $date, 'departure')->get(['child_id', 'arrive_by', 'note'])->keyBy('child_id');
            $__fmt = fn ($l) => ['by' => \Illuminate\Support\Carbon::parse(substr((string) $l->arrive_by, 0, 5), $tz)->format('g:i A'),
                'by_hm' => substr((string) $l->arrive_by, 0, 5), 'note' => $l->note];
            foreach ($roster as &$__r) {
                $__l = $__r['status'] === 'away' ? $__late->get($__r['id']) : null;
                $__r['running_late'] = $__l ? $__fmt($__l) : null;
                // Late pickup: still to be picked up (in now, or not here yet).
                $__p = $__r['status'] !== 'out' ? $__out->get($__r['id']) : null;
                $__r['late_pickup'] = $__p ? $__fmt($__p) : null;
            }
            unset($__r);
        }
        $ord = ['in' => 0, 'out' => 1, 'away' => 2];
        usort($roster, fn ($a, $b) => ($ord[$a['status']] <=> $ord[$b['status']]) ?: strcmp((string) $a['name'], (string) $b['name']));

        // ── Staff (provider/educator) clock in/out on the date ──────────
        $staff = [];
        foreach (DB::table('time_punches as tp')->join('users as u', 'u.id', '=', 'tp.user_id')
            ->where('tp.centre_id', $centreId)->whereBetween('tp.punched_in_at', [$dayStart, $dayEnd])
            ->orderBy('tp.punched_in_at')
            ->get(['u.id as uid', 'u.first_name', 'u.last_name', 'u.photo_url', 'u.sex', 'tp.id as pid', 'tp.punched_in_at', 'tp.punched_out_at', 'tp.out_reason', 'tp.source', 'tp.correction_source', 'tp.original_auto_at']) as $tp) {
            $staff[] = [
                'user_id' => (int) $tp->uid, 'punch_id' => (int) $tp->pid,
                'name' => trim(($tp->first_name ?? '') . ' ' . ($tp->last_name ?? '')) ?: 'Staff',
                'photo_url' => $tp->photo_url, 'sex' => $tp->sex,
                'in' => AgencyTime::fmt($tp->punched_in_at, $tz),
                'out' => $tp->punched_out_at ? AgencyTime::fmt($tp->punched_out_at, $tz) : null,
                'source' => $tp->source,
                // 'lunch' / 'break': this row closed for a break (StaffBreaks, 2026-11-05), not the end of the day.
                'out_how' => in_array($tp->out_reason ?? null, ['lunch', 'break'], true) ? $tp->out_reason
                    : (($tp->correction_source ?? null) === 'educator_email' ? 'educator_email'
                    : (($tp->correction_source ?? null) ? 'corrected' : ($tp->source === 'auto' && $tp->punched_out_at ? 'automatic' : null))),
                'out_auto' => ($tp->original_auto_at ?? null) ? AgencyTime::fmt($tp->original_auto_at, $tz) : null,
            ];
        }
        // Who is on a break right now (their last row closed for it and they are not back yet).
        $__brk = \App\Support\StaffBreaks::currentFor(array_column($staff, 'user_id'));
        // Presence: green = actively using the app now, amber = on shift but idle.
        // Clocked-out staff get no dot (presence null).
        $__pres = \App\Support\Presence::forUsers(array_column($staff, 'user_id'));
        foreach ($staff as &$__s) { $__s['presence'] = $__s['out'] ? null : ($__pres[$__s['user_id']] ?? 'idle'); $__s['on_break'] = (isset($__s['punch_id'], $__brk[$__s['user_id']]) && (int) $__brk[$__s['user_id']]->punch_id === $__s['punch_id']) ? $__brk[$__s['user_id']]->kind : null; }
        unset($__s);

        /* EVERYONE ASSIGNED TO THIS HOME, not only who clocked in (2026-10-21). Anthony:
           "if two educators are assigned to the same centre, does this show up ... Bruni's
           centre has her as a provider and one other". It did not — this list came from
           time_punches alone, so Rachel Blane (assigned, never clocked in) was invisible.
           Members with no punch that day follow as `not_in` rows; `scheduled` says whether
           they were rostered, so "not working today" never reads as "absent". The provider
           is always expected, as on the overview card. Agency office accounts are left out
           (see App\Support\CentreTeam) unless they actually clocked in here. */
        $__clocked = array_flip(array_column($staff, 'user_id'));
        foreach ((\App\Support\CentreTeam::forCentres([$centreId], $date)[$centreId] ?? []) as $__uid => $__m) {
            if (isset($__clocked[$__uid])) { continue; }
            $staff[] = [
                'user_id' => (int) $__uid, 'name' => $__m['name'],
                'photo_url' => $__m['photo_url'], 'sex' => $__m['sex'],
                'in' => null, 'out' => null, 'source' => null, 'presence' => null,
                'not_in' => true, 'is_provider' => $__m['is_provider'],
                // The provider by the shared rule, no longer "always expected" (2026-11-01).
                'scheduled' => $__m['is_provider']
                    ? \App\Support\ScheduledToday::provider($centreId, (int) $__uid, $date, ! empty($__open[$centreId]), $__m['scheduled'])
                    : $__m['scheduled'],
            ];
        }

        /* ── Last headcount, per educator, on the date (2026-10-20) ──
           Anthony: "on the daily overview for each educator add a new section that shows the
           last head count with time stamp and number of kids". One line per person who took
           a headcount here that day (their latest: when, how many of how many, anyone
           missing), plus anyone on shift who has not taken one - the gap a director is
           looking for. */
        $headcounts = [];
        try {
            $byUser = [];
            foreach (DB::table('headcounts as h')->leftJoin('users as u', 'u.id', '=', 'h.taken_by')
                ->leftJoin('rooms as r', 'r.id', '=', 'h.room_id')
                ->where('h.centre_id', $centreId)->whereBetween('h.taken_at', [$dayStart, $dayEnd])
                ->orderBy('h.taken_at')
                ->get(['h.taken_by', 'h.taken_at', 'h.context', 'h.expected_count', 'h.counted_count', 'h.all_accounted',
                    'h.missing_child_ids', 'h.resolved_at', 'r.name as room_name', 'u.first_name', 'u.last_name', 'u.photo_url', 'u.sex']) as $h) {
                $k = (int) $h->taken_by;
                $byUser[$k] = ['n' => ($byUser[$k]['n'] ?? 0) + 1, 'last' => $h];
            }
            $contexts = \App\Http\Controllers\Api\HeadcountController::CONTEXTS;
            foreach ($byUser as $uid => $b) {
                $h = $b['last'];
                $missingIds = array_map('intval', json_decode((string) $h->missing_child_ids, true) ?: []);
                $missingNames = $missingIds ? DB::table('children')->whereIn('id', array_slice($missingIds, 0, 8))
                    ->get(['first_name', 'preferred_name', 'last_name'])
                    ->map(fn ($c) => trim(($c->preferred_name ?: $c->first_name) . ' ' . mb_substr((string) $c->last_name, 0, 1)))->all() : [];
                $headcounts[] = [
                    'user_id' => $uid,
                    'name' => trim(($h->first_name ?? '') . ' ' . ($h->last_name ?? '')) ?: 'Staff',
                    'photo_url' => $h->photo_url, 'sex' => $h->sex,
                    'time' => AgencyTime::fmt($h->taken_at, $tz),
                    'at' => Carbon::parse($h->taken_at, 'UTC')->toIso8601String(),
                    'counted' => (int) $h->counted_count,
                    'expected' => (int) $h->expected_count,
                    'all_accounted' => (bool) $h->all_accounted,
                    'missing' => $missingNames,
                    'missing_count' => count($missingIds),
                    'resolved' => ! empty($h->resolved_at),
                    'context' => $contexts[$h->context] ?? ucfirst(str_replace('_', ' ', (string) $h->context)),
                    'room' => $h->room_name,
                    'count_today' => $b['n'],
                ];
            }
            usort($headcounts, fn ($a, $b) => strcmp($b['at'], $a['at']));
            // On shift now (no clock-out) but no headcount on this day.
            $seenStaff = [];
            foreach ($staff as $st) {
                if ($st['out'] || ! empty($st['not_in']) || isset($byUser[$st['user_id']]) || isset($seenStaff[$st['user_id']])) { continue; }
                $seenStaff[$st['user_id']] = true;
                $headcounts[] = ['user_id' => $st['user_id'], 'name' => $st['name'], 'photo_url' => $st['photo_url'], 'sex' => $st['sex'], 'none' => true];
            }
        } catch (\Throwable $e) {
            $headcounts = [];
        }

        /* MISSED HEADCOUNTS = STRIKES (2026-11-03, HeadcountStrikesCommand). Per educator: how many, and when/where. */
        $headcountStrikes = [];
        try {
            $byU = [];
            foreach (\App\Console\Commands\HeadcountStrikesCommand::between((int) $centreId, null, (string) $dayStart, (string) $dayEnd) as $s) {
                $byU[$s['user_id']][] = ['time' => AgencyTime::fmt($s['due_at'], $tz), 'room' => $s['room']];
            }
            if ($byU) {
                foreach (DB::table('users')->whereIn('id', array_keys($byU))->get(['id', 'first_name', 'last_name', 'photo_url', 'sex']) as $u) {
                    $headcountStrikes[] = ['user_id' => (int) $u->id, 'name' => trim($u->first_name . ' ' . $u->last_name) ?: 'Staff',
                        'photo_url' => $u->photo_url, 'sex' => $u->sex, 'count' => count($byU[$u->id]), 'items' => $byU[$u->id]];
                }
                usort($headcountStrikes, fn ($a, $b) => $b['count'] <=> $a['count']);
            }
        } catch (\Throwable $e) {
            $headcountStrikes = [];
        }

        // ── Weekly meal plan (menu) for the week containing the date ────
        $monday = Carbon::parse($date, $tz)->startOfWeek(Carbon::MONDAY)->toDateString();
        $menu = []; $menuNotes = null;
        $menuWeek = DB::table('menu_weeks')->where('centre_id', $centreId)
            ->whereDate('week_start', $monday)->orderByDesc('id')->first();
        if ($menuWeek) {
            /* Food photos (2026-11-02): image_url was not selected, so the Daily Overview could never show one. Same
               photo the Weekly menu shows, including the not-yet-saved match for older weeks (FoodPhotos::forDisplay). */
            $menuRows = DB::table('menu_items')->where('menu_week_id', $menuWeek->id)
                ->orderBy('day_of_week')->orderByRaw("FIELD(meal_type,'breakfast','morning_snack','lunch','afternoon_snack','dinner')")
                ->get(['day_of_week', 'meal_type', 'name', 'description', 'allergens', 'image_url', 'image_source']);
            \App\Support\FoodPhotos::forDisplay($menuRows, (int) $centre->agency_id);
            // The notes staff write on the menu travel with it (2026-11-04, Anthony: "this weeks meal plan doesnt show
            // any of the notes that an educator would enter in the daily overview and parent daily email").
            foreach ($menuRows as $mi) {
                $menu[(string) $mi->day_of_week][] = ['meal' => $mi->meal_type, 'name' => $mi->name, 'image_url' => $mi->image_url ?: null,
                    'notes' => $mi->description ?: null, 'allergens' => $mi->allergens ?: null];
            }
            $menuNotes = trim((string) ($menuWeek->notes ?? '')) ?: null;
        }

        // ── Per-child care detail: nap windows, meals (with times), diapers ──
        $deByChild = [];
        if ($childIds) {
            foreach (DB::table('daily_events')->whereIn('child_id', $childIds)
                ->whereBetween('occurred_at', [$dayStart, $dayEnd])->orderBy('occurred_at')
                ->get(['child_id', 'event_type', 'occurred_at', 'payload']) as $e) {
                $deByChild[$e->child_id][] = $e;
            }
        }
        foreach ($roster as &$rr) {
            $evs = $deByChild[$rr['id']] ?? [];
            $rNaps = []; $rMeals = []; $rDiapers = 0; $openNap = null;
            foreach ($evs as $e) {
                if ($e->event_type === 'nap_start') { $openNap = AgencyTime::fmt($e->occurred_at, $tz); }
                elseif ($e->event_type === 'nap_end') { $rNaps[] = ['start' => $openNap, 'end' => AgencyTime::fmt($e->occurred_at, $tz)]; $openNap = null; }
                elseif (in_array($e->event_type, ['meal', 'snack'], true)) {
                    $p = is_string($e->payload) ? (json_decode($e->payload, true) ?: []) : ((array) ($e->payload ?? []));
                    $rMeals[] = ['name' => $p['meal'] ?? ucfirst($e->event_type), 'time' => AgencyTime::fmt($e->occurred_at, $tz)];
                } elseif ($e->event_type === 'diaper') { $rDiapers++; }
            }
            if ($openNap) { $rNaps[] = ['start' => $openNap, 'end' => null]; }
            $rr['care'] = ['naps' => $rNaps, 'meals' => $rMeals, 'diapers' => $rDiapers];
        }
        unset($rr);

        // ── Photos & videos captured today ──────────────────────────────
        $mediaAbs = fn ($u) => $u ? (preg_match('#^https?://#', (string) $u) ? $u : ('https://api.kiddietrac.com' . $u)) : null;
        $photos = [];
        foreach (DB::table('photos')->where('centre_id', $centreId)->whereNull('hidden_at')   // no photo consent: shown to nobody
            ->whereBetween('taken_at', [$dayStart, $dayEnd])->orderByDesc('taken_at')->limit(60)
            ->get(['url', 'thumbnail_url', 'media_type', 'caption', 'taken_at']) as $ph) {
            $photos[] = [
                'url' => $mediaAbs($ph->url),
                'thumb' => $mediaAbs($ph->thumbnail_url ?: $ph->url),
                'type' => $ph->media_type ?: 'photo',
                'caption' => $ph->caption,
                'time' => AgencyTime::fmt($ph->taken_at, $tz),
            ];
        }

        // ── Chat log (educator ↔ parent) today ──────────────────────────
        $guardianIds = DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')
            ->where('f.centre_id', $centreId)->whereNotNull('g.user_id')->pluck('g.user_id')->flip();
        $chat = [];
        foreach (DB::table('messages as m')->join('conversations as cv', 'cv.id', '=', 'm.conversation_id')
            ->leftJoin('users as u', 'u.id', '=', 'm.sender_id')
            ->where('cv.centre_id', $centreId)->whereBetween('m.created_at', [$dayStart, $dayEnd])
            ->whereNull('m.deleted_at')->whereNull('cv.deleted_at')
            ->orderBy('m.created_at')->limit(80)
            ->get(['m.body', 'm.sender_id', 'm.created_at', 'u.first_name', 'u.last_name']) as $msg) {
            $chat[] = [
                'from' => trim(($msg->first_name ?? '') . ' ' . ($msg->last_name ?? '')) ?: 'Someone',
                'is_parent' => isset($guardianIds[$msg->sender_id]),
                'body' => mb_strlen((string) $msg->body) > 260 ? mb_substr((string) $msg->body, 0, 260) . '…' : (string) $msg->body,
                'time' => AgencyTime::fmt($msg->created_at, $tz),
            ];
        }

        // ── Observations recorded this day (learning-story / HDLH notes) ──
        $observations = [];
        if ($childIds) {
            foreach (DB::table('observations as o')->leftJoin('users as u', 'u.id', '=', 'o.recorded_by_id')
                ->whereIn('o.child_id', $childIds)
                ->whereBetween('o.observed_at', [$dayStart, $dayEnd])
                ->orderBy('o.observed_at')->limit(120)
                ->get(['o.child_id', 'o.framework', 'o.domain', 'o.title', 'o.body', 'o.family_summary', 'o.observed_at', 'o.shared_with_family', 'u.first_name', 'u.last_name']) as $o) {
                $text = trim((string) ($o->body ?? '')) !== '' ? (string) $o->body : (string) ($o->family_summary ?? '');
                $observations[] = [
                    'child'     => $nameById[$o->child_id] ?? 'Child',
                    'title'     => $o->title,
                    'body'      => mb_strlen($text) > 600 ? mb_substr($text, 0, 600) . '…' : $text,
                    'domain'    => $o->domain,
                    'framework' => $o->framework,
                    'educator'  => trim(($o->first_name ?? '') . ' ' . ($o->last_name ?? '')) ?: null,
                    'time'      => AgencyTime::fmt($o->observed_at, $tz),
                    'shared'    => (bool) $o->shared_with_family,
                ];
            }
        }

        // ── Performance analytics ───────────────────────────────────────
        $enrolled = count($childIds);
        $attended = count($seen);
        $presentIds = [];
        foreach ($checksByChild as $cid => $evs) {
            foreach ($evs as $e) { if ($e->event_type === 'check_in') { $presentIds[$cid] = true; break; } }
        }
        $attendedById = count($presentIds) ?: $attended;
        $careCovered = 0;
        foreach (array_keys($presentIds) as $cid) { if (! empty($deByChild[$cid])) { $careCovered++; } }
        $totalDiapers = 0;
        foreach ($roster as $rr2) { $totalDiapers += $rr2['care']['diapers'] ?? 0; }
        $analytics = [
            'attendance_rate'   => $enrolled ? (int) round($attended / $enrolled * 100) : 0,
            'care_coverage_pct' => $attendedById ? (int) round($careCovered / $attendedById * 100) : 0,
            'qr_pct'            => ($qr + $manual) ? (int) round($qr / ($qr + $manual) * 100) : 0,
            'avg_moments'       => $attended ? round(count($events) / $attended, 1) : 0,
            'breakdown'         => [
                'Meals & snacks' => $meals,
                'Naps'           => $naps,
                'Diapers'        => $totalDiapers,
                'Activities'     => $activities,
                'Other'          => max(0, count($events) - $meals - $naps - $totalDiapers - $activities),
            ],
        ];

        // ── Walks & outings on this date (with live-location availability) ──
        $walks = [];
        foreach (DB::table('field_trips as t')
            ->leftJoin('users as u', 'u.id', '=', 't.staff_lead_id')
            ->where('t.centre_id', $centreId)
            ->whereDate('t.trip_date', $date)
            ->orderByDesc('t.id')
            ->get(['t.id', 't.title', 't.destination', 't.status', 't.depart_time', 't.return_time',
                DB::raw("TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) as lead")]) as $w) {
            $pings = DB::table('field_trip_pings')->where('field_trip_id', $w->id)->count();
            $kids = DB::table('field_trip_permissions')->where('field_trip_id', $w->id)->count();
            $sum = \App\Http\Controllers\Api\WalkController::walkSummary((int) $w->id);
            // The route map is kept after the walk ends — that is when an educator
            // writes the day up and wants to see where they actually went. Rendered only
            // for finished walks: a live one is still moving, and the live map already
            // shows it.
            $mapUrl = ($w->status === 'completed' && $pings > 1)
                ? \App\Support\WalkMap::urlFor((int) $w->id)
                : null;

            $walks[] = [
                'id' => $w->id, 'title' => $w->title, 'destination' => $w->destination,
                'status' => $w->status, 'lead' => trim((string) $w->lead) ?: '—',
                'map_url' => $mapUrl,
                'children' => $kids, 'pings' => $pings, 'has_location' => $pings > 0,
                'distance_m' => $sum['distance_m'], 'steps_est' => $sum['steps_est'], 'duration_min' => $sum['duration_min'],
                // depart_time/return_time are stored as agency-LOCAL wall-clock (H:i:s),
                // not UTC — WalkController writes Carbon::now($staffTz)->format('H:i:s').
                // So format the wall clock directly; running it through AgencyTime::fmt
                // (which assumes UTC input) double-shifted it by the agency offset.
                'depart' => $w->depart_time ? \Illuminate\Support\Carbon::parse($w->depart_time)->format('g:i A') : null,
                'return' => $w->return_time ? \Illuminate\Support\Carbon::parse($w->return_time)->format('g:i A') : null,
            ];
        }

        return response()->json([
            'date' => $date,
            'week_start' => $monday,
            'walks' => $walks,
            'centre' => [
                'id' => $centre->id,
                'name' => $centre->name,
                // Provider face: match the centre to its person by email so the
                // header shows the provider's photo (their centre rarely has a logo).
                'provider_photo_url' => $centre->email
                    ? DB::table('users')->whereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $centre->email))])
                        ->whereNull('deleted_at')->value('photo_url')
                    : null,
            ],
            'summary' => [
                'enrolled' => count($childIds),
                'attended' => count($seen),
                // Not booked that day and never came: left out of "absent" (2026-11-01).
                'not_scheduled' => count(array_filter($roster, fn ($r) => $r['status'] === 'away' && $r['scheduled'] === false)),
                'still_in' => $present,
                'went_home' => $out,
                'meals' => $meals, 'naps' => $naps, 'activities' => $activities, 'incidents' => $incidents,
                'moments' => count($events),
                // Distance covered on foot today, across every walk from this centre.
                'walk_km' => round(array_sum(array_map(
                    fn ($w) => ($w['distance_m'] ?? 0) / 1000, $walks
                )), 2),
                'walk_count' => count($walks),
            ],
            'roster' => $roster,
            'qr_vs_manual' => ['qr' => $qr, 'manual' => $manual],
            // What was PLANNED for that day, beside the record of what happened. Read
            // through the same helper as the day brief and both summary emails, so a
            // director and an educator never see different plans for the same room.
            'lesson_plan' => (function () use ($centreId, $date) {
                try {
                    $p = \App\Support\LessonPlans::forDateInCentres([(int) $centreId], $date);
                    if (! $p['items']) {
                        $p = \App\Support\LessonPlans::forDate(null, (int) $centreId, $date);
                    }
                    return $p['items'] ? $p : null;
                } catch (\Throwable $e) {
                    return null;
                }
            })(),
            'staff' => $staff,
            'headcounts' => $headcounts,
            'headcount_strikes' => $headcountStrikes,
            'menu' => $menu,
            'menu_notes' => $menuNotes,
            'photos' => $photos,
            'chat' => $chat,
            'observations' => $observations,
            'analytics' => $analytics,
            'timeline' => $events,
        ]);
    }

    /** Caller's agency id (first active assignment, or active-agency header). */
    private function callerAgencyId(Request $request): ?int
    {
        $userId = $request->user()->id;
        $aid = (int) $request->header('X-Active-Agency-Id');

        $isPlatform = DB::table('role_assignments')->where('user_id', $userId)
            ->where('role', 'platform_admin')->where('active', true)->exists();

        // Honour the active-agency header ONLY when the caller can actually reach
        // that agency — a platform admin can target any live agency; anyone else
        // must hold an active role in it (directly or via an assigned centre). This
        // mirrors AdminController::getAgencyId and stops a STALE/foreign header —
        // e.g. an id left in localStorage from a previous account on the same shared
        // browser — from 403-ing a legitimate agency admin (Safia: header=6, but she
        // is agency_admin of 2 only → the old code trusted 6 → Provider Overview 403).
        if ($aid) {
            if ($isPlatform) {
                if (DB::table('agencies')->where('id', $aid)->whereNull('deleted_at')->exists()) return $aid;
            } elseif ($this->userHasAgencyAccess($userId, $aid)) {
                return $aid;
            }
            // else: stale/foreign header → ignore it, fall back to the real agency.
        }

        $a = DB::table('role_assignments')->where('user_id', $userId)
            ->where('active', true)->whereNotNull('agency_id')->value('agency_id');
        return $a ? (int) $a : null;
    }

    /** True when the user has any active tie to $agencyId (role in it, an assigned
     *  centre in it, or a guardianship of a family at one of its centres). */
    private function userHasAgencyAccess(int $userId, int $agencyId): bool
    {
        if (DB::table('role_assignments')->where('user_id', $userId)->where('active', true)
            ->where('agency_id', $agencyId)->exists()) {
            return true;
        }
        $centreIds = DB::table('role_assignments')->where('user_id', $userId)->where('active', true)
            ->whereNotNull('centre_id')->pluck('centre_id');
        if ($centreIds->isNotEmpty() && DB::table('centres')->whereIn('id', $centreIds)
            ->where('agency_id', $agencyId)->exists()) {
            return true;
        }
        $famCentreIds = DB::table('guardians')->join('families', 'families.id', '=', 'guardians.family_id')
            ->where('guardians.user_id', $userId)->pluck('families.centre_id');
        if ($famCentreIds->isNotEmpty() && DB::table('centres')->whereIn('id', $famCentreIds)
            ->where('agency_id', $agencyId)->exists()) {
            return true;
        }
        return false;
    }

    /** [centreIds, roomIds] the caller works in. */
    private function scope(Request $request, $user): array
    {
        $assignedCentres = DB::table('role_assignments')->whereIn('role', \App\Support\Visibility::STAFF_ROLES)->where('user_id', $user->id)
            ->where('active', true)->whereNotNull('centre_id')->pluck('centre_id')->unique()->all();

        $agencyId = $this->callerAgencyId($request);
        if (empty($assignedCentres) && $agencyId) {
            // Director / agency admin with no single centre → all agency centres.
            $assignedCentres = DB::table('centres')->where('agency_id', $agencyId)
                ->whereNull('deleted_at')->pluck('id')->all();
        }
        $centreIds = array_values(array_map('intval', $assignedCentres));
        $roomIds = DB::table('rooms')->whereIn('centre_id', $centreIds ?: [0])->pluck('id')->map(fn ($x) => (int) $x)->all();
        return [$centreIds, $roomIds];
    }
}
