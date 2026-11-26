<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * REMINDER TOASTS (2026-11-26, Anthony: "wire up reminders for upcoming events/meetings as a toast in the portal";
 * he chose "Like Outlook": one reminder, 15 minutes before by default, that stays until dismissed or snoozed).
 *
 * The person's own upcoming items, for the next two hours, from the sources the My day panel shows:
 *  - KiddieTrac meetings they organise or were invited to (not declined): the active agency's, plus the
 *    KiddieTrac team's platform meetings for the platform team. Parents get only meetings they were invited to.
 *  - their own connected Outlook / Google calendar (never the agency's shared one: that is not "theirs");
 *  - sales: their own sales-calendar events, website bookings they host, and lead meetings / follow-ups due
 *    today that they own (sales reps and super admins, as GET /sales/calendar);
 *  - their open tasks that have a reminder time, or are due today (staff only);
 *  - Quick-note reminders, through QuickNotes::remindersFor() once that exists (see notes()).
 *
 * Everything is the caller's own: never another person's calendar, tasks or invitations, and never another agency's
 * meetings. A timed item fires `lead` minutes before it starts; a date-only item (a task due today, an all-day
 * event, a lead follow-up) fires at 9:00 on that day in the agency's time zone. Each item's key carries its start
 * time, so a moved event reminds again while a dismissed one never comes back.
 *
 * Nothing here is ever sent anywhere: the portal shows it while it is open. No push, no email, no text.
 */
final class Reminders
{
    public const LEADS = [0, 5, 10, 15, 30, 60];
    public const DEFAULT_LEAD = 15;
    public const AHEAD_MIN = 120;      // how far ahead a fetch looks (the client re-fetches every few minutes)
    public const DAY_HOUR = 9;         // date-only items fire at 9:00 local
    public const STAFF = ['platform_admin', 'agency_admin', 'centre_director', 'educator', 'home_visitor', 'sales_rep', 'reseller_admin'];
    public const TEAM = ['platform_admin', 'sales_rep'];
    private const CACHE_SEC = 60;

    /** The person's lead time in minutes, or null when they turned reminders off (ui-prefs "reminders"). */
    public static function lead(int $userId): ?int
    {
        $j = null;
        try {
            if (Schema::hasTable('user_ui_prefs')) {
                $v = DB::table('user_ui_prefs')->where('user_id', $userId)->where('pref_key', 'reminders')->value('value');
                $j = $v ? json_decode((string) $v, true) : null;
            }
        } catch (\Throwable $e) {
        }
        $l = is_array($j) && array_key_exists('lead', $j) ? $j['lead'] : self::DEFAULT_LEAD;
        if ($l === 'off' || $l === false || $l === null) return null;
        $l = (int) $l;
        return in_array($l, self::LEADS, true) ? $l : self::DEFAULT_LEAD;
    }

    /**
     * The person's reminders with their dismissed / snoozed state.
     *
     * @param  string[]  $roles  AgencyRole::names() for this request (roles in the agency on screen)
     * @return array{lead:?int, tz:string, items:array<int,array>}
     */
    public static function forUser(int $userId, ?int $agencyId, array $roles): array
    {
        $tz = $agencyId ? AgencyTime::tz($agencyId) : BookingMail::TZ;
        $lead = self::lead($userId);
        if ($lead === null) return ['lead' => null, 'tz' => $tz, 'items' => []];

        sort($roles);
        $ck = 'kt_rem:' . $userId . ':' . (int) $agencyId . ':' . md5(implode(',', $roles)) . ':' . $lead;
        $items = Cache::remember($ck, self::CACHE_SEC, fn () => self::collect($userId, $agencyId, $roles, $tz, $lead));

        $now = Carbon::now('UTC');
        $states = [];
        if ($items && Schema::hasTable('reminder_states')) {
            foreach (DB::table('reminder_states')->where('user_id', $userId)->whereIn('item_key', array_column($items, 'key'))->get() as $s) {
                $states[$s->item_key] = $s;
            }
        }
        $out = [];
        foreach ($items as $it) {
            $s = $states[$it['key']] ?? null;
            if ($s && $s->state === 'dismissed') continue;
            $it['snooze_until'] = ($s && $s->state === 'snoozed' && $s->snooze_until && Carbon::parse($s->snooze_until, 'UTC')->gt($now))
                ? Carbon::parse($s->snooze_until, 'UTC')->toIso8601String() : null;
            $out[] = $it;
        }
        usort($out, fn ($a, $b) => strcmp($a['fire_at'], $b['fire_at']) ?: strcmp($a['start'] ?? '', $b['start'] ?? ''));
        return ['lead' => $lead, 'tz' => $tz, 'items' => array_values($out)];
    }

    private static function collect(int $uid, ?int $aid, array $roles, string $tz, int $lead): array
    {
        $now = Carbon::now('UTC');
        $staff = (bool) array_intersect($roles, self::STAFF);
        $team = (bool) array_intersect($roles, self::TEAM);
        $out = [];
        $add = function (array $it) use (&$out, $now) {
            // Fires within the look-ahead, and the thing is not over yet.
            $fire = Carbon::parse($it['fire_at']);
            if ($fire->gt($now->copy()->addMinutes(self::AHEAD_MIN))) return;
            if (Carbon::parse($it['until'])->lte($now)) return;
            $out[$it['key']] = $it;
        };
        $timed = function (string $key, string $kind, string $title, Carbon $s, Carbon $e, array $extra) use ($add, $lead) {
            $add($extra + ['key' => $key . '@' . $s->timestamp, 'kind' => $kind, 'title' => $title,
                'start' => $s->toIso8601String(), 'end' => $e->toIso8601String(), 'all_day' => false,
                'fire_at' => $s->copy()->subMinutes($lead)->toIso8601String(), 'until' => $e->toIso8601String()]);
        };
        $dayItem = function (string $key, string $kind, string $title, string $date, string $itz, array $extra) use ($add) {
            $d = Carbon::parse($date, $itz)->startOfDay();
            $add($extra + ['key' => $key . '@' . $date, 'kind' => $kind, 'title' => $title,
                'start' => $d->copy()->utc()->toIso8601String(), 'end' => null, 'all_day' => true,
                'fire_at' => $d->copy()->setTime(self::DAY_HOUR, 0)->utc()->toIso8601String(),
                'until' => $d->copy()->endOfDay()->utc()->toIso8601String()]);
        };
        $todayLocal = Carbon::now($tz)->toDateString();

        // ── KiddieTrac meetings: organiser, or invited and not declined ──────────────────────────────
        try {
            if ($aid || $team) {
                $q = DB::table('meetings')->where('status', 'scheduled')
                    ->where('starts_at', '<', $now->copy()->addMinutes(self::AHEAD_MIN + 24 * 60))
                    ->where('ends_at', '>', $now)
                    ->where(function ($w) use ($uid) {
                        $w->where('organizer_id', $uid)->orWhereExists(fn ($x) => $x->from('meeting_attendees as a')
                            ->whereColumn('a.meeting_id', 'meetings.id')->where('a.user_id', $uid)->where('a.response', '<>', 'declined'));
                    })
                    ->where(function ($w) use ($aid, $team) {
                        if ($aid) $w->orWhere(fn ($a) => $a->where('scope', 'agency')->where('agency_id', $aid));
                        if ($team) $w->orWhere('scope', 'platform');
                    });
                foreach ($q->orderBy('starts_at')->limit(50)->get() as $m) {
                    $mtz = Meetings::tzFor($m);
                    $extra = ['meeting_id' => (int) $m->id, 'location' => $m->location, 'join_url' => self::https($m->join_url),
                        'video' => $m->video, 'open' => null, 'source' => $m->scope === 'platform' ? 'sales' : 'meeting'];
                    if ($m->all_day) {
                        $dayItem('mtg:' . $m->id, 'meeting', (string) $m->title, Carbon::parse($m->starts_at, 'UTC')->tz($mtz)->toDateString(), $mtz, $extra);
                    } else {
                        $timed('mtg:' . $m->id, 'meeting', (string) $m->title, Carbon::parse($m->starts_at, 'UTC'), Carbon::parse($m->ends_at, 'UTC'), $extra);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::info('reminders: meetings', ['e' => $e->getMessage()]);
        }

        // ── Their own Outlook / Google calendar ───────────────────────────────────────────────────────
        try {
            if ($staff && Schema::hasTable('calendar_links') && ($link = ExternalCalendar::personal($uid))) {
                $from = Carbon::now($tz)->startOfDay();
                $to = Carbon::now($tz)->addDay()->endOfDay();
                $res = ExternalCalendar::events($link, $from->copy()->utc(), $to->copy()->utc(), false);
                foreach ($res['events'] ?? [] as $e) {
                    if (! empty($e['free'])) continue;
                    $extra = ['location' => $e['location'] ?? null, 'join_url' => self::https($e['join_url'] ?? null),
                        'web_link' => self::https($e['web_link'] ?? null), 'open' => null, 'source' => 'calendar', 'provider' => $e['provider'] ?? null];
                    if (! empty($e['all_day'])) {
                        $dayItem('x:' . $e['id'], 'calendar', (string) $e['title'], substr((string) $e['start'], 0, 10), $tz, $extra);
                    } else {
                        $timed('x:' . $e['id'], 'calendar', (string) $e['title'], Carbon::parse($e['start'])->utc(), Carbon::parse($e['end'])->utc(), $extra);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::info('reminders: calendar', ['e' => $e->getMessage()]);
        }

        // ── Sales (the KiddieTrac team), the same gate as GET /sales/calendar ─────────────────────────
        try {
            if ($team && self::salesOn($aid)) {
                $stz = BookingMail::TZ;
                $win = [$now->copy()->subDay(), $now->copy()->addMinutes(self::AHEAD_MIN + 60)];
                foreach (DB::table('sales_calendar_events')->where('user_id', $uid)->where('all_day', false)
                    ->whereNotIn('kind', ['where', 'time_off'])->whereBetween('starts_at', $win)->where('ends_at', '>', $now)->limit(30)->get() as $e) {
                    $timed('sev:' . $e->id, 'sales', (string) ($e->title ?: 'Sales event'), Carbon::parse($e->starts_at, 'UTC'), Carbon::parse($e->ends_at, 'UTC'),
                        ['location' => $e->location, 'join_url' => null, 'open' => $e->lead_id ? 'sales-lead?id=' . (int) $e->lead_id : 'sales-calendar', 'source' => 'sales']);
                }
                foreach (DB::table('marketing_bookings')->where('host_user_id', $uid)->whereIn('status', ['requested', 'booked'])
                    ->whereBetween('starts_at', $win)->where('ends_at', '>', $now)->limit(30)->get() as $b) {
                    $timed('bk:' . $b->id, 'sales', BookingMail::typeName($b) . ' · ' . $b->name . ($b->agency ? ' (' . $b->agency . ')' : ''),
                        Carbon::parse($b->starts_at, 'UTC'), Carbon::parse($b->ends_at, 'UTC'),
                        ['location' => null, 'join_url' => self::https($b->teams_url), 'open' => 'sales-bookings?id=' . (int) $b->id, 'source' => 'sales']);
                }
                $sToday = Carbon::now($stz)->toDateString();
                foreach (DB::table('sales_activities as a')->join('sales_leads as l', 'l.id', '=', 'a.lead_id')
                    ->whereIn('a.type', ['meeting', 'followup', 'call'])->where('a.done', false)->whereNull('l.deleted_at')
                    ->where('a.due_date', $sToday)->whereRaw('COALESCE(l.owner_id, a.user_id) = ?', [$uid])
                    ->limit(30)->get(['a.id', 'a.type', 'a.body', 'a.lead_id', 'l.company', 'l.name']) as $a) {
                    $dayItem('sact:' . $a->id, 'sales', ($a->company ?: $a->name) . ' — ' . mb_substr((string) $a->body, 0, 90), $sToday, $stz,
                        ['location' => null, 'join_url' => null, 'open' => 'sales-lead?id=' . (int) $a->lead_id, 'source' => 'sales']);
                }
                foreach (DB::table('sales_leads')->whereNull('deleted_at')->where('status', 'open')->where('owner_id', $uid)
                    ->where('follow_up_date', $sToday)->limit(30)->get(['id', 'company', 'name']) as $l) {
                    $dayItem('sfu:' . $l->id, 'sales', 'Follow up: ' . ($l->company ?: $l->name), $sToday, $stz,
                        ['location' => null, 'join_url' => null, 'open' => 'sales-lead?id=' . (int) $l->id, 'source' => 'sales']);
                }
            }
        } catch (\Throwable $e) {
            Log::info('reminders: sales', ['e' => $e->getMessage()]);
        }

        // ── Their own open tasks: a reminder time, or due today ───────────────────────────────────────
        try {
            if ($staff) {
                $manager = (bool) array_intersect($roles, ['platform_admin', 'agency_admin', 'centre_director']);
                $open = $manager ? 'tasks' : (array_intersect($roles, ['educator', 'home_visitor']) ? 'my-tasks' : null);
                $q = DB::table('tasks')->whereNull('deleted_at')->where('assigned_to', $uid)->whereIn('status', ['open', 'in_progress'])
                    ->where(fn ($w) => $w->where('is_private', 0)->orWhere('assigned_by', $uid))
                    ->where(function ($w) use ($aid, $uid) {
                        if ($aid) $w->where('agency_id', $aid)->orWhere(fn ($p) => $p->whereNull('agency_id')->where('is_private', 1)->where('assigned_by', $uid));
                        else $w->whereNull('agency_id');
                    })
                    ->where(fn ($w) => $w->where('due_date', $todayLocal)
                        ->orWhereBetween('remind_at', [$now->copy()->subDay(), $now->copy()->addMinutes(self::AHEAD_MIN)]));
                foreach ($q->limit(50)->get(['id', 'title', 'due_date', 'remind_at']) as $t) {
                    $extra = ['location' => null, 'join_url' => null, 'open' => $open, 'source' => 'task', 'task_id' => (int) $t->id];
                    if ($t->remind_at) {
                        $at = Carbon::parse($t->remind_at, 'UTC');
                        $add($extra + ['key' => 'task:' . $t->id . '@' . $at->timestamp, 'kind' => 'task', 'title' => (string) $t->title,
                            'start' => $at->toIso8601String(), 'end' => null, 'all_day' => false, 'fire_at' => $at->toIso8601String(),
                            'until' => $at->copy()->tz($tz)->endOfDay()->utc()->toIso8601String()]);
                    } else {
                        $dayItem('task:' . $t->id, 'task', (string) $t->title, $todayLocal, $tz, $extra);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::info('reminders: tasks', ['e' => $e->getMessage()]);
        }

        // ── Quick notes ───────────────────────────────────────────────────────────────────────────────
        foreach (self::notes($uid, $aid, $roles, $tz) as $n) {
            if (empty($n['id']) || empty($n['remind_at'])) continue;
            $at = Carbon::parse($n['remind_at'])->utc();
            $add(['key' => 'note:' . $n['id'] . '@' . $at->timestamp, 'kind' => 'note', 'title' => (string) ($n['title'] ?? 'Note'),
                'start' => $at->toIso8601String(), 'end' => null, 'all_day' => false, 'fire_at' => $at->toIso8601String(),
                'until' => $at->copy()->tz($tz)->endOfDay()->utc()->toIso8601String(),
                'location' => null, 'join_url' => null, 'open' => $n['open'] ?? 'notes', 'source' => 'note']);
        }
        // Quick notes' reminder DATE (quick_notes.remind_on, agency-local): the person's OWN notes only (private, or a
        // shared one they wrote) in the agency on screen, at 9:00 that day like a task due today. A shared note someone
        // else wrote still shows in their My day panel, but never rings for the whole staff.
        if ($staff && ! method_exists(QuickNotes::class, 'remindersFor')) {
            try {
                if (Schema::hasTable('quick_notes')) {
                    $today = Carbon::now($tz)->toDateString();
                    $q = DB::table('quick_notes')->whereNull('deleted_at')->where('owner_user_id', $uid)->where('remind_on', $today)
                        ->where(function ($w) use ($aid) {
                            $w->where(function ($p) use ($aid) {
                                $p->where('visibility', 'private')->where(fn ($a) => $aid ? $a->where('agency_id', $aid)->orWhereNull('agency_id') : $a->whereNull('agency_id'));
                            });
                            if ($aid) $w->orWhere(fn ($s) => $s->where('visibility', 'agency')->where('agency_id', $aid));
                        });
                    $nine = Carbon::parse($today . ' ' . sprintf('%02d:00:00', self::DAY_HOUR), $tz)->utc();
                    foreach ($q->orderBy('id')->limit(30)->get(['id', 'title', 'body']) as $n) {
                        $t = trim((string) $n->title) !== '' ? (string) $n->title : strtok(trim((string) $n->body), "\n");
                        $add(['key' => 'note:' . $n->id . '@' . $today, 'kind' => 'note', 'title' => mb_substr((string) ($t ?: 'Note'), 0, 140),
                            'start' => $nine->toIso8601String(), 'end' => null, 'all_day' => true, 'fire_at' => $nine->toIso8601String(),
                            'until' => Carbon::parse($today, $tz)->endOfDay()->utc()->toIso8601String(),
                            'location' => null, 'join_url' => null, 'open' => 'notes', 'source' => 'note']);
                    }
                }
            } catch (\Throwable $e) {
                Log::info('reminders: quick notes', ['e' => $e->getMessage()]);
            }
        }

        foreach ($out as &$it) unset($it['until']);
        unset($it);
        return array_values($out);
    }

    /**
     * Quick-note reminders. HOOK: the Quick notes feature (built alongside this) owns who may see a note, so it answers
     * through QuickNotes::remindersFor(int $userId, ?int $agencyId, array $roles, string $tz): array of
     * ['id', 'title', 'remind_at' (ISO, UTC), 'open' (screen hash)] — only notes whose reminder is this person's.
     * Until that method exists, there are no note reminders.
     */
    private static function notes(int $uid, ?int $aid, array $roles, string $tz): array
    {
        try {
            if (class_exists(QuickNotes::class) && method_exists(QuickNotes::class, 'remindersFor')) {
                $r = QuickNotes::remindersFor($uid, $aid, $roles, $tz);
                return is_array($r) ? $r : [];
            }
        } catch (\Throwable $e) {
            Log::info('reminders: notes', ['e' => $e->getMessage()]);
        }
        return [];
    }

    /** GET /sales/calendar's feature gate (CheckFeatureFlag sales_crm), for the agency on screen. */
    private static function salesOn(?int $aid): bool
    {
        if (! $aid) return true;
        try {
            $f = \App\Http\Controllers\Api\FeatureFlagController::effectiveFor($aid);
            return ! array_key_exists('sales_crm', $f) || (bool) $f['sales_crm'];
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function https($u): ?string
    {
        $u = is_string($u) ? trim($u) : '';
        return $u !== '' && preg_match('~^https://~i', $u) ? $u : null;
    }
}
