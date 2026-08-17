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

            if ($plan['items']) {
                $n = count($plan['items']);
                $first = $plan['items'][0];
                $add(
                    'lesson-plan', '📘', 'Today’s plan',
                    $plan['theme'] ?: ($n . ' activit' . ($n === 1 ? 'y' : 'ies')),
                    trim(($first['time_label'] ? $first['time_label'] . ' · ' : '') . $first['title'])
                        . ($n > 1 ? ' +' . ($n - 1) . ' more' : ''),
                    'info', '#lesson-plans'
                );
            }
        } catch (\Throwable $e) {
            // A day brief is more useful without this tile than not at all.
        }

        // ── Attendance: expected / in / out / late pickups ──────────────
        try {
            $childIds = DB::table('children as c')
                ->join('families as f', 'f.id', '=', 'c.family_id')
                ->whereIn('f.centre_id', $centreIds ?: [0])
                ->where('c.enrollment_status', 'enrolled')
                ->whereNull('f.suspended_at')
                ->whereNull('c.deleted_at')
                ->pluck('c.id')->all();
            $expected = count($childIds);
            $lastByChild = [];
            if ($childIds) {
                foreach (DB::table('check_events')->whereIn('child_id', $childIds)
                    ->whereDate('occurred_at', $todayStr)->orderBy('occurred_at')
                    ->get(['child_id', 'event_type', 'occurred_at']) as $e) {
                    $lastByChild[$e->child_id] = $e;
                }
            }
            $present = 0; $out = 0;
            foreach ($lastByChild as $e) { $e->event_type === 'check_in' ? $present++ : $out++; }
            $notInYet = $expected - count($lastByChild);
            $add('attendance', '👶', 'Attendance',
                "{$present} in · {$notInYet} not in yet · {$out} left",
                "{$expected} enrolled today", $present > 0 ? 'good' : 'info', '#today');

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
                ->whereDate('occurred_at', $todayStr)
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
            $ann = DB::table('announcements')
                ->whereNotNull('sent_at')->where('sent_at', '>=', now($tz)->subDays(3))
                ->where(function ($q) use ($centreIds) {
                    $q->where('scope_type', 'agency')->orWhere(function ($qq) use ($centreIds) {
                        $qq->where('scope_type', 'centre')->whereIn('scope_id', $centreIds ?: [0]);
                    });
                })->count();
            if ($ann > 0) $add('announcements', '📣', 'News', $ann . ' new', 'In the last 3 days', 'info', '#announcements');
        } catch (\Throwable $e) {}

        // ── Today's incidents in the centre ─────────────────────────────
        try {
            $inc = DB::table('incidents')->whereIn('room_id', $roomIds ?: [0])
                ->whereDate('occurred_at', $todayStr)->count();
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
            ->get(['c.id', 'c.first_name', 'c.last_name', 'c.preferred_name', 'c.photo_url', 'c.gender']);
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
            $ICON = ['meal' => '🍽️', 'snack' => '🍎', 'nap_start' => '😴', 'nap_end' => '🌅', 'diaper' => '🧷', 'bathroom' => '🚽', 'activity' => '✨', 'mood' => '🙂', 'note' => '📝', 'bottle' => '🍼'];
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
                        'activity' => $p['name'] ?? 'Activity',
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
                    'detail' => $isMedia ? (string) ($p['note'] ?? $e->notes ?? '') : ($e->notes ?? ''),
                    'media_type' => $isMedia ? ($isVideoEvent ? 'video' : 'photo') : null,
                    'photo_id' => $isMedia ? ($p['photo_id'] ?? null) : null,
                ];
            }
            // ALSO the "Log a moment" entries — these write to daily_care_logs, a
            // SEPARATE table from the roster quick-log's daily_events. Reading only
            // daily_events meant a provider's logged moments never showed here.
            $CICON = ['diaper' => '🧷', 'bathroom' => '🚽', 'nap' => '😴', 'meal' => '🍽️', 'snack' => '🍎', 'bottle' => '🍼', 'sunscreen' => '☀️', 'mood' => '🙂'];
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
                ->get(['child_id', 'event_type', 'occurred_at', 'kiosk_source']) as $ce) {
                $checksByChild[$ce->child_id][] = $ce;
            }
        }
        $roster = [];
        $qr = 0; $manual = 0;
        foreach ($children as $c) {
            $evs = $checksByChild[$c->id] ?? [];
            $inEv = null; $outEv = null;
            foreach ($evs as $e) {
                if ($e->event_type === 'check_in') { if (! $inEv) $inEv = $e; ((int) ($e->kiosk_source ?? 0) > 0) ? $qr++ : $manual++; }
                if ($e->event_type === 'check_out') $outEv = $e;
            }
            $last = $evs ? end($evs) : null;
            $status = ! $evs ? 'away' : ($last->event_type === 'check_in' ? 'in' : 'out');
            $roster[] = [
                'id' => $c->id, 'name' => $nameById[$c->id], 'photo_url' => $c->photo_url, 'gender' => $c->gender,
                'status' => $status,
                'in' => $inEv ? AgencyTime::fmt($inEv->occurred_at, $tz) : null,
                'out' => $outEv ? AgencyTime::fmt($outEv->occurred_at, $tz) : null,
                'source' => $inEv ? ((int) ($inEv->kiosk_source ?? 0) > 0 ? 'QR' : 'Manual') : null,
            ];
        }
        $ord = ['in' => 0, 'out' => 1, 'away' => 2];
        usort($roster, fn ($a, $b) => ($ord[$a['status']] <=> $ord[$b['status']]) ?: strcmp((string) $a['name'], (string) $b['name']));

        // ── Staff (provider/educator) clock in/out on the date ──────────
        $staff = [];
        foreach (DB::table('time_punches as tp')->join('users as u', 'u.id', '=', 'tp.user_id')
            ->where('tp.centre_id', $centreId)->whereBetween('tp.punched_in_at', [$dayStart, $dayEnd])
            ->orderBy('tp.punched_in_at')
            ->get(['u.id as uid', 'u.first_name', 'u.last_name', 'u.photo_url', 'u.sex', 'tp.punched_in_at', 'tp.punched_out_at', 'tp.source']) as $tp) {
            $staff[] = [
                'user_id' => (int) $tp->uid,
                'name' => trim(($tp->first_name ?? '') . ' ' . ($tp->last_name ?? '')) ?: 'Staff',
                'photo_url' => $tp->photo_url, 'sex' => $tp->sex,
                'in' => AgencyTime::fmt($tp->punched_in_at, $tz),
                'out' => $tp->punched_out_at ? AgencyTime::fmt($tp->punched_out_at, $tz) : null,
                'source' => $tp->source,
            ];
        }
        // Presence: green = actively using the app now, amber = on shift but idle.
        // Clocked-out staff get no dot (presence null).
        $__pres = \App\Support\Presence::forUsers(array_column($staff, 'user_id'));
        foreach ($staff as &$__s) { $__s['presence'] = $__s['out'] ? null : ($__pres[$__s['user_id']] ?? 'idle'); }
        unset($__s);

        // ── Weekly meal plan (menu) for the week containing the date ────
        $monday = Carbon::parse($date, $tz)->startOfWeek(Carbon::MONDAY)->toDateString();
        $menu = [];
        $menuWeek = DB::table('menu_weeks')->where('centre_id', $centreId)
            ->whereDate('week_start', $monday)->orderByDesc('id')->first();
        if ($menuWeek) {
            foreach (DB::table('menu_items')->where('menu_week_id', $menuWeek->id)
                ->get(['day_of_week', 'meal_type', 'name']) as $mi) {
                $menu[(string) $mi->day_of_week][] = ['meal' => $mi->meal_type, 'name' => $mi->name];
            }
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
        foreach (DB::table('photos')->where('centre_id', $centreId)
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
            $walks[] = [
                'id' => $w->id, 'title' => $w->title, 'destination' => $w->destination,
                'status' => $w->status, 'lead' => trim((string) $w->lead) ?: '—',
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
                'still_in' => $present,
                'went_home' => $out,
                'meals' => $meals, 'naps' => $naps, 'activities' => $activities, 'incidents' => $incidents,
                'moments' => count($events),
            ],
            'roster' => $roster,
            'qr_vs_manual' => ['qr' => $qr, 'manual' => $manual],
            'staff' => $staff,
            'menu' => $menu,
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
        $assignedCentres = DB::table('role_assignments')->where('user_id', $user->id)
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
