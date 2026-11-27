<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KIDDIETRAC HR PHASE 4, BATCH 8 (2026-11-27): ROTA DRAFT -> PUBLISH, COPY TOOLS, SHIFT PRESETS, LABOUR BUDGET, SHIFT
 * ACCEPTANCE. HR add-on only; an agency without HR (or with the publish step switched off, the default) rosters exactly
 * as before.
 *
 * DRAFTS NEVER TOUCH `shifts`. With agencies.settings.hr_rota.draft_mode on, the schedule's own write paths
 * (SchedulingController create / edit / delete / Autofill button, and the copy tools here) put the change in
 * hr_shift_drafts instead: add = a new shift, change = new values for a published shift, cancel = a published shift to
 * remove. Every shift that already exists counts as published. Because drafts live in their own table, nothing that
 * reads shifts can see one: clock-in and lateness, ratio alerts, timesheets and payroll, calendar feeds, a staff member's
 * own schedule, shift notices (HrShiftNotices), open-shift eligibility. Only the director's schedule grid and the rota
 * plan show them, marked "Draft" / "Changed - not published" / "To be removed".
 *
 * PUBLISH applies a centre's drafts to `shifts` (one transaction): add -> insert, change -> update, cancel -> delete,
 * each through HrShiftNotices (source "publish"), so a whole publish still gives each person ONE batched notice. A draft
 * that cannot be applied (the person is no longer staff there, it now overlaps another shift, the shift was removed
 * meanwhile) is left as a draft and reported. With require_accept on, every shift a publish adds (or moves to a new
 * person or time) waits for that person to accept it in My HR ("Shifts to accept"); a decline tells the centre's
 * directors and leaves the shift on the rota for them to sort out. The nightly rolling fill and standing hours keep
 * writing published shifts, as before (they are standing arrangements, not a new rota).
 *
 * COPY TOOLS copy what the grid shows (published shifts with pending changes applied, plus draft additions): last week
 * into this week, one person's week, or one day onto other days. Skipped, and listed: closure days, days the centre is
 * not open, people no longer staff there, approved leave / unavailability (HrAvailability), an overlapping shift, times
 * already past.
 *
 * PRESETS (hr_shift_presets) fill the shift form and colour the card (shifts.preset_id). LABOUR BUDGET
 * (hr_labour_budgets) = a weekly amount per centre against the projected cost of the week (hours x hourly pay rate),
 * shown only to people who may see pay (manager + payroll feature); admins set it.
 */
final class HrRota
{
    public const DEFAULTS = ['draft_mode' => false, 'require_accept' => false];
    public const ROLES = ['lead', 'support', 'floater', 'volunteer'];
    public const COLOURS = ['#0E7490', '#1F6080', '#16A34A', '#65A30D', '#CA8A04', '#EA580C', '#DC2626', '#DB2777', '#7C3AED', '#475569'];
    public const MAX_COPY_DAYS = 7;

    private static ?bool $ready = null;
    /** @var array<string,mixed> */
    private static array $memo = [];

    public static function ready(): bool
    {
        return self::$ready ??= rescue(fn () => Schema::hasTable('hr_shift_drafts') && Schema::hasTable('hr_shift_acceptances'), false, false);
    }

    public static function forget(): void
    {
        self::$memo = [];
    }

    public static function settings(int $agencyId): array
    {
        $all = json_decode((string) DB::table('agencies')->where('id', $agencyId)->value('settings'), true);
        $raw = is_array($all['hr_rota'] ?? null) ? $all['hr_rota'] : [];
        $s = array_replace(self::DEFAULTS, array_intersect_key($raw, self::DEFAULTS));
        $s['draft_mode'] = (bool) $s['draft_mode'];
        $s['require_accept'] = $s['draft_mode'] && (bool) $s['require_accept'];

        return $s;
    }

    public static function saveSettings(int $agencyId, array $in): array
    {
        DB::transaction(function () use ($agencyId, $in) {
            $row = DB::table('agencies')->where('id', $agencyId)->lockForUpdate()->first(['settings']);
            $all = json_decode((string) ($row->settings ?? ''), true);
            $all = is_array($all) ? $all : [];
            $all['hr_rota'] = ['draft_mode' => (bool) ($in['draft_mode'] ?? false), 'require_accept' => (bool) ($in['require_accept'] ?? false)];
            DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        });
        self::forget();

        return self::settings($agencyId);
    }

    public static function agencyOfCentre(int $centreId): int
    {
        return (int) (self::$memo['c:' . $centreId] ??= (int) DB::table('centres')->where('id', $centreId)->value('agency_id'));
    }

    /** Does this agency hold rota changes as drafts? (HR add-on + the publish step switched on.) */
    public static function draftMode(int $agencyId): bool
    {
        if ($agencyId <= 0 || ! self::ready()) return false;

        return (bool) (self::$memo['d:' . $agencyId] ??= Features::on($agencyId, HrLeave::FEATURE) && self::settings($agencyId)['draft_mode']);
    }

    public static function draftModeForCentre(int $centreId): bool
    {
        return self::draftMode(self::agencyOfCentre($centreId));
    }

    public static function requireAccept(int $agencyId): bool
    {
        return self::draftMode($agencyId) && self::settings($agencyId)['require_accept'];
    }

    /** "2026-12-01T09:00" / "2026-12-01 09:00:00" -> "2026-12-01 09:00:00" (wall clock, as shifts store it). */
    public static function wall(string $v): string
    {
        return Carbon::parse(str_replace('T', ' ', $v))->format('Y-m-d H:i:s');
    }

    /** May this person be put on a shift at this centre? The same rule as the schedule's own picker. */
    public static function mayRoster(int $userId, int $centreId): bool
    {
        if ($userId <= 0 || $centreId <= 0) return false;
        $agencyId = self::agencyOfCentre($centreId);

        return DB::table('users')->join('role_assignments', 'role_assignments.user_id', '=', 'users.id')
            ->where('users.id', $userId)
            ->where(fn ($q) => $q->where('role_assignments.centre_id', $centreId)->orWhere('role_assignments.agency_id', $agencyId ?: -1))
            ->whereIn('role_assignments.role', \App\Http\Controllers\Api\SchedulingController::STAFF_ROLES)
            ->where('role_assignments.active', true)
            ->whereNotIn('users.status', AccountStatus::CLOSED)->whereNull('users.deleted_at')
            ->exists() && ! in_array($userId, AgencySignatories::outsiderIds(), true);
    }

    private static function actor(): ?int
    {
        try {
            return optional(request()->user())->id;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ── holding a change as a draft (called by SchedulingController) ───────────────────────

    /** A new shift in draft mode: the draft id, or null when the agency publishes straight away. */
    public static function holdNew(int $centreId, array $d): ?int
    {
        if (! self::draftModeForCentre($centreId)) return null;
        $a = self::actor();

        return (int) DB::table('hr_shift_drafts')->insertGetId(['agency_id' => self::agencyOfCentre($centreId), 'centre_id' => $centreId, 'kind' => 'add',
            'shift_id' => null, 'user_id' => (int) $d['user_id'], 'room_id' => (int) $d['room_id'], 'starts_at' => self::wall((string) $d['starts_at']),
            'ends_at' => self::wall((string) $d['ends_at']), 'role' => in_array($d['role'] ?? '', self::ROLES, true) ? $d['role'] : 'support',
            'preset_id' => ! empty($d['preset_id']) ? (int) $d['preset_id'] : null, 'created_by_id' => $a, 'updated_by_id' => $a,
            'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * An edit of a published shift in draft mode (times, person, room, role, or status cancelled): held as the shift's one
     * pending change. Returns the draft id, or null when it should be written straight away (no draft mode, or only a
     * status change such as active / completed).
     */
    public static function holdChange(object $shift, int $destCentre, array $update): ?int
    {
        if (! self::draftModeForCentre($destCentre)) return null;
        if (($update['status'] ?? null) === 'cancelled') return self::holdCancel($shift, $destCentre);
        $keys = array_intersect_key($update, array_flip(['starts_at', 'ends_at', 'user_id', 'room_id', 'role', 'preset_id']));
        if (! $keys) return null;
        $cur = DB::table('hr_shift_drafts')->where('shift_id', $shift->id)->first();
        $base = $cur && $cur->kind === 'change' ? (array) $cur : (array) $shift;
        $row = ['user_id' => (int) ($keys['user_id'] ?? $base['user_id']), 'room_id' => (int) ($keys['room_id'] ?? $base['room_id']),
            'starts_at' => self::wall((string) ($keys['starts_at'] ?? $base['starts_at'])), 'ends_at' => self::wall((string) ($keys['ends_at'] ?? $base['ends_at'])),
            'role' => (string) ($keys['role'] ?? $base['role']),
            'preset_id' => array_key_exists('preset_id', $keys) ? ($keys['preset_id'] ? (int) $keys['preset_id'] : null) : ($base['preset_id'] ?? null)];
        $same = $row['user_id'] === (int) $shift->user_id && $row['room_id'] === (int) $shift->room_id && $row['role'] === (string) $shift->role
            && $row['starts_at'] === self::wall((string) $shift->starts_at) && $row['ends_at'] === self::wall((string) $shift->ends_at)
            && (int) ($row['preset_id'] ?? 0) === (int) ($shift->preset_id ?? 0);
        if ($same) {   // changed back to what is published: nothing pending
            if ($cur) DB::table('hr_shift_drafts')->where('id', $cur->id)->delete();

            return $cur ? (int) $cur->id : 0;
        }
        $row += ['agency_id' => self::agencyOfCentre($destCentre), 'centre_id' => $destCentre, 'kind' => 'change', 'updated_by_id' => self::actor(), 'updated_at' => now()];
        if ($cur) {
            DB::table('hr_shift_drafts')->where('id', $cur->id)->update($row);

            return (int) $cur->id;
        }

        return (int) DB::table('hr_shift_drafts')->insertGetId($row + ['shift_id' => (int) $shift->id, 'created_by_id' => self::actor(), 'created_at' => now()]);
    }

    /** Removing a published shift in draft mode: held as "to be removed" (replacing a pending change). */
    public static function holdCancel(object $shift, int $centreId): ?int
    {
        if (! self::draftModeForCentre($centreId)) return null;
        $row = ['agency_id' => self::agencyOfCentre($centreId), 'centre_id' => $centreId, 'kind' => 'cancel', 'user_id' => (int) $shift->user_id,
            'room_id' => (int) $shift->room_id, 'starts_at' => self::wall((string) $shift->starts_at), 'ends_at' => self::wall((string) $shift->ends_at),
            'role' => (string) $shift->role, 'preset_id' => $shift->preset_id ?? null, 'updated_by_id' => self::actor(), 'updated_at' => now()];
        $cur = DB::table('hr_shift_drafts')->where('shift_id', $shift->id)->first();
        if ($cur) {
            DB::table('hr_shift_drafts')->where('id', $cur->id)->update($row);

            return (int) $cur->id;
        }

        return (int) DB::table('hr_shift_drafts')->insertGetId($row + ['shift_id' => (int) $shift->id, 'created_by_id' => self::actor(), 'created_at' => now()]);
    }

    // ── reading ───────────────────────────────────────────────────────────────────────────

    /** Drafts touching a centre (its own, and changes / removals of shifts that are at it now). */
    public static function draftsQuery(int $centreId)
    {
        $roomIds = DB::table('rooms')->where('centre_id', $centreId)->pluck('id')->all();

        return DB::table('hr_shift_drafts as d')->where(function ($w) use ($centreId, $roomIds) {
            $w->where('d.centre_id', $centreId)->orWhereIn('d.shift_id', function ($q) use ($roomIds) {
                $q->select('id')->from('shifts')->whereIn('room_id', $roomIds ?: [0]);
            });
        });
    }

    public static function shape(object $d, array $names, array $rooms): array
    {
        return ['id' => (int) $d->id, 'kind' => $d->kind, 'shift_id' => $d->shift_id ? (int) $d->shift_id : null, 'user_id' => (int) $d->user_id,
            'user_name' => $names[(int) $d->user_id] ?? 'Unknown', 'room_id' => (int) $d->room_id, 'room_name' => $rooms[(int) $d->room_id] ?? 'Room',
            'starts_at' => (string) $d->starts_at, 'ends_at' => (string) $d->ends_at, 'starts_hm' => substr((string) $d->starts_at, 11, 5),
            'ends_hm' => substr((string) $d->ends_at, 11, 5), 'date' => substr((string) $d->starts_at, 0, 10), 'role' => $d->role,
            'preset_id' => $d->preset_id ? (int) $d->preset_id : null, 'centre_id' => (int) $d->centre_id];
    }

    /** Drafts for a centre, from..to (dates, inclusive; null = all), shaped. */
    public static function drafts(int $centreId, ?string $from = null, ?string $to = null): array
    {
        $rows = self::draftsQuery($centreId)
            ->when($from, fn ($q) => $q->where('d.starts_at', '>=', $from . ' 00:00:00'))
            ->when($to, fn ($q) => $q->where('d.starts_at', '<=', $to . ' 23:59:59'))
            ->orderBy('d.starts_at')->get(['d.*']);
        $names = DB::table('users')->whereIn('id', $rows->pluck('user_id')->all() ?: [0])->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn ($u) => [(int) $u->id => trim($u->first_name . ' ' . $u->last_name)])->all();
        $rooms = DB::table('rooms')->whereIn('id', $rows->pluck('room_id')->all() ?: [0])->pluck('name', 'id')->all();

        return $rows->map(fn ($d) => self::shape($d, $names, $rooms))->values()->all();
    }

    /** {add, change, cancel, total} waiting at a centre (every date). */
    public static function pendingCounts(int $centreId): array
    {
        $c = self::draftsQuery($centreId)->selectRaw('d.kind, COUNT(*) n')->groupBy('d.kind')->pluck('n', 'kind')->all();
        $out = ['add' => (int) ($c['add'] ?? 0), 'change' => (int) ($c['change'] ?? 0), 'cancel' => (int) ($c['cancel'] ?? 0)];
        $out['total'] = array_sum($out);

        return $out;
    }

    /**
     * The week as the director's grid shows it, for copying and costing: published shifts (not cancelled) with any
     * pending change applied and pending removals left out, plus draft additions. Each: user_id, room_id, starts_at,
     * ends_at, role, preset_id, draft (add|change|null), shift_id.
     */
    public static function asPlanned(int $centreId, string $from, string $to, ?int $userId = null): array
    {
        $roomIds = DB::table('rooms')->where('centre_id', $centreId)->pluck('id')->all();
        $pending = self::draftsQuery($centreId)->get(['d.*']);
        $byShift = $pending->whereNotNull('shift_id')->keyBy('shift_id');
        $out = [];
        foreach (DB::table('shifts')->whereIn('room_id', $roomIds ?: [0])->where('status', '!=', 'cancelled')
            ->where('starts_at', '>=', $from . ' 00:00:00')->where('starts_at', '<=', $to . ' 23:59:59')->orderBy('starts_at')->get() as $s) {
            $p = $byShift[$s->id] ?? null;
            if ($p && $p->kind === 'cancel') continue;
            $src = $p ?: $s;
            if ($p && (int) $p->centre_id !== $centreId) continue;   // moved to another centre
            $out[] = ['user_id' => (int) $src->user_id, 'room_id' => (int) $src->room_id, 'starts_at' => self::wall((string) $src->starts_at),
                'ends_at' => self::wall((string) $src->ends_at), 'role' => (string) $src->role, 'preset_id' => $src->preset_id ?? null,
                'draft' => $p ? 'change' : null, 'shift_id' => (int) $s->id];
        }
        foreach ($pending as $p) {
            if ($p->kind !== 'add' || (int) $p->centre_id !== $centreId) continue;
            $d = substr((string) $p->starts_at, 0, 10);
            if ($d < $from || $d > $to) continue;
            $out[] = ['user_id' => (int) $p->user_id, 'room_id' => (int) $p->room_id, 'starts_at' => self::wall((string) $p->starts_at),
                'ends_at' => self::wall((string) $p->ends_at), 'role' => (string) $p->role, 'preset_id' => $p->preset_id, 'draft' => 'add', 'shift_id' => null];
        }
        if ($userId) $out = array_values(array_filter($out, fn ($r) => $r['user_id'] === $userId));
        usort($out, fn ($a, $b) => strcmp($a['starts_at'], $b['starts_at']));

        return $out;
    }

    /** Does this person already work [start, end) anywhere in the agency, published or drafted? Returns where, or null. */
    public static function clash(int $agencyId, int $userId, string $start, string $end, ?int $exceptShift = null, ?int $exceptDraft = null): ?string
    {
        $s = DB::table('shifts as s')->join('rooms as r', 'r.id', '=', 's.room_id')->join('centres as c', 'c.id', '=', 'r.centre_id')
            ->where('c.agency_id', $agencyId)->where('s.user_id', $userId)->where('s.status', '!=', 'cancelled')
            ->when($exceptShift, fn ($q) => $q->where('s.id', '!=', $exceptShift))
            ->whereNotIn('s.id', DB::table('hr_shift_drafts')->where('agency_id', $agencyId)->where('kind', 'cancel')->select('shift_id'))
            ->where('s.starts_at', '<', $end)->where('s.ends_at', '>', $start)->first(['s.starts_at', 's.ends_at', 'r.name']);
        if ($s) return 'Already on a shift then (' . $s->name . ', ' . Carbon::parse($s->starts_at)->format('g:i A') . ' – ' . Carbon::parse($s->ends_at)->format('g:i A') . ')';
        $d = DB::table('hr_shift_drafts')->where('agency_id', $agencyId)->where('user_id', $userId)->whereIn('kind', ['add', 'change'])
            ->when($exceptDraft, fn ($q) => $q->where('id', '!=', $exceptDraft))
            ->when($exceptShift, fn ($q) => $q->where(fn ($w) => $w->whereNull('shift_id')->orWhere('shift_id', '!=', $exceptShift)))
            ->where('starts_at', '<', $end)->where('ends_at', '>', $start)->first(['starts_at', 'ends_at']);

        return $d ? 'Already on a draft shift then (' . Carbon::parse($d->starts_at)->format('g:i A') . ' – ' . Carbon::parse($d->ends_at)->format('g:i A') . ')' : null;
    }

    // ── publish ───────────────────────────────────────────────────────────────────────────

    /**
     * Publish a centre's drafts (from..to on the draft's start date; null = all of them).
     * @return array{added:int, changed:int, removed:int, kept:array, asked:int}
     */
    public static function publish(int $agencyId, int $centreId, ?string $from, ?string $to, int $actorId): array
    {
        $res = ['added' => 0, 'changed' => 0, 'removed' => 0, 'kept' => [], 'asked' => 0];
        $ask = self::requireAccept($agencyId);
        $added = [];
        $notices = [];
        DB::transaction(function () use ($agencyId, $centreId, $from, $to, $actorId, $ask, &$res, &$added, &$notices) {
            $ids = self::draftsQuery($centreId)->where('d.agency_id', $agencyId)
                ->when($from, fn ($q) => $q->where('d.starts_at', '>=', $from . ' 00:00:00'))
                ->when($to, fn ($q) => $q->where('d.starts_at', '<=', $to . ' 23:59:59'))->pluck('d.id')->all();
            $drafts = DB::table('hr_shift_drafts')->whereIn('id', $ids ?: [0])->orderBy('starts_at')->orderBy('id')->lockForUpdate()->get();
            $names = DB::table('users')->whereIn('id', $drafts->pluck('user_id')->all() ?: [0])->get(['id', 'first_name', 'last_name'])
                ->mapWithKeys(fn ($u) => [(int) $u->id => trim($u->first_name . ' ' . $u->last_name)])->all();
            $keep = function (object $d, string $why) use (&$res, $names) {
                $res['kept'][] = ['id' => (int) $d->id, 'kind' => $d->kind, 'person' => $names[(int) $d->user_id] ?? '', 'date' => substr((string) $d->starts_at, 0, 10),
                    'start' => substr((string) $d->starts_at, 11, 5), 'end' => substr((string) $d->ends_at, 11, 5), 'why' => $why];
            };
            foreach ($drafts as $d) {
                $dest = (int) $d->centre_id;
                $roomOk = DB::table('rooms')->where('id', $d->room_id)->where('centre_id', $dest)->exists();
                if ($d->kind === 'cancel') {
                    $s = DB::table('shifts')->where('id', $d->shift_id)->lockForUpdate()->first();
                    if ($s) {
                        $c = (int) DB::table('rooms')->where('id', $s->room_id)->value('centre_id');
                        DB::table('shifts')->where('id', $s->id)->delete();
                        DB::table('hr_shift_acceptances')->where('shift_id', $s->id)->delete();
                        $notices[] = ['removed', $c, $s, null];
                        $res['removed']++;
                    }
                    DB::table('hr_shift_drafts')->where('id', $d->id)->delete();
                    continue;
                }
                if (! $roomOk) { $keep($d, 'The room is no longer at this centre'); continue; }
                if (! self::mayRoster((int) $d->user_id, $dest)) { $keep($d, 'Not on this centre\'s staff any more'); continue; }
                if ($d->kind === 'add') {
                    if ($why = self::clash($agencyId, (int) $d->user_id, (string) $d->starts_at, (string) $d->ends_at, null, (int) $d->id)) { $keep($d, $why); continue; }
                    $row = ['user_id' => (int) $d->user_id, 'room_id' => (int) $d->room_id, 'starts_at' => (string) $d->starts_at, 'ends_at' => (string) $d->ends_at,
                        'role' => (string) $d->role, 'status' => 'scheduled', 'created_at' => now()];
                    if (Schema::hasColumn('shifts', 'preset_id')) $row['preset_id'] = $d->preset_id;
                    $sid = (int) DB::table('shifts')->insertGetId($row);
                    $added[$dest][] = ['id' => $sid] + $row;
                    if ($ask) self::askAccept($agencyId, $dest, $sid, (int) $d->user_id);
                    $res['added']++;
                } else {
                    $s = DB::table('shifts')->where('id', $d->shift_id)->lockForUpdate()->first();
                    if (! $s) { DB::table('hr_shift_drafts')->where('id', $d->id)->delete(); continue; }   // removed meanwhile: nothing to change
                    if ($why = self::clash($agencyId, (int) $d->user_id, (string) $d->starts_at, (string) $d->ends_at, (int) $s->id, (int) $d->id)) { $keep($d, $why); continue; }
                    $upd = [];
                    foreach (['user_id', 'room_id', 'role'] as $k) if ((string) $s->$k !== (string) $d->$k) $upd[$k] = $d->$k;
                    foreach (['starts_at', 'ends_at'] as $k) if (self::wall((string) $s->$k) !== self::wall((string) $d->$k)) $upd[$k] = (string) $d->$k;
                    if (Schema::hasColumn('shifts', 'preset_id') && (int) ($s->preset_id ?? 0) !== (int) ($d->preset_id ?? 0)) $upd['preset_id'] = $d->preset_id;
                    if ($upd) {
                        DB::table('shifts')->where('id', $s->id)->update($upd);
                        $notices[] = ['changed', $dest, $s, $upd];
                        if ($ask && (isset($upd['user_id']) || isset($upd['starts_at']) || isset($upd['ends_at']))) self::askAccept($agencyId, $dest, (int) $s->id, (int) $d->user_id);
                        $res['changed']++;
                    }
                }
                DB::table('hr_shift_drafts')->where('id', $d->id)->delete();
            }
        });
        // Shift notices (batched per person by hr:shift-notices), after the rows are committed.
        foreach ($added as $cid => $rows) HrShiftNotices::added((int) $cid, $rows, 'publish');
        foreach ($notices as [$kind, $cid, $s, $upd]) {
            if ($kind === 'removed') HrShiftNotices::removed((int) $cid, [$s], 'publish');
            else HrShiftNotices::changed((int) $cid, $s, $upd, 'publish');
        }
        $res['asked'] = $ask ? $res['added'] : 0;

        return $res;
    }

    private static function askAccept(int $agencyId, int $centreId, int $shiftId, int $userId): void
    {
        DB::table('hr_shift_acceptances')->updateOrInsert(['shift_id' => $shiftId],
            ['agency_id' => $agencyId, 'centre_id' => $centreId, 'user_id' => $userId, 'status' => 'pending', 'note' => null, 'responded_at' => null,
                'updated_at' => now(), 'created_at' => now()]);
    }

    // ── acceptance ────────────────────────────────────────────────────────────────────────

    /** [shift_id => pending|accepted|declined] for shifts still held by the person they were published to. */
    public static function acceptanceMap(array $shiftIds): array
    {
        if (! self::ready() || ! $shiftIds) return [];

        return DB::table('hr_shift_acceptances as a')->join('shifts as s', 's.id', '=', 'a.shift_id')->whereIn('a.shift_id', $shiftIds)
            ->whereColumn('a.user_id', 's.user_id')->get(['a.shift_id', 'a.status', 'a.note'])
            ->mapWithKeys(fn ($r) => [(int) $r->shift_id => ['status' => $r->status, 'note' => $r->note]])->all();
    }

    /** The person's upcoming shifts waiting for them to accept. */
    public static function toAccept(int $agencyId, int $userId): array
    {
        if (! self::ready()) return [];
        $rows = DB::table('hr_shift_acceptances as a')->join('shifts as s', 's.id', '=', 'a.shift_id')->join('rooms as r', 'r.id', '=', 's.room_id')
            ->join('centres as c', 'c.id', '=', 'r.centre_id')->where('a.agency_id', $agencyId)->where('a.user_id', $userId)->whereColumn('a.user_id', 's.user_id')
            ->where('a.status', 'pending')->where('s.status', '!=', 'cancelled')->orderBy('s.starts_at')->limit(100)
            ->get(['s.id', 's.starts_at', 's.ends_at', 's.role', 'r.name as room', 'c.name as centre', 'c.id as centre_id']);
        $out = [];
        foreach ($rows as $s) {
            $now = Carbon::now(AgencyTime::tzForCentre((int) $s->centre_id))->format('Y-m-d H:i:s');
            if ((string) $s->ends_at <= $now) continue;
            $out[] = ['shift_id' => (int) $s->id, 'date' => substr((string) $s->starts_at, 0, 10), 'start' => substr((string) $s->starts_at, 11, 5),
                'end' => substr((string) $s->ends_at, 11, 5), 'role' => $s->role, 'room' => $s->room, 'centre' => $s->centre,
                'hours' => HrAvailability::hours((string) $s->starts_at, (string) $s->ends_at)];
        }

        return $out;
    }

    public static function toAcceptCount(int $agencyId, int $userId): int
    {
        return count(self::toAccept($agencyId, $userId));
    }

    // ── copy tools ────────────────────────────────────────────────────────────────────────

    /**
     * Copy shifts at a centre. $pairs = [[source date, target date], ...] (each source day onto its target day);
     * $userId limits it to one person. Returns {added, drafted (bool), skipped: [{person, date, why}]}.
     */
    public static function copy(int $agencyId, int $centreId, array $pairs, ?int $userId, int $actorId): array
    {
        $centre = DB::table('centres')->where('id', $centreId)->first();
        $draft = self::draftMode($agencyId);
        $tz = AgencyTime::tzForCentre($centreId);
        $now = Carbon::now($tz)->format('Y-m-d H:i:s');
        $settings = json_decode((string) ($centre->settings ?? ''), true);
        $openDays = array_values(array_filter(array_map('intval', (array) ($settings['open_days'] ?? [])), fn ($d) => $d >= 1 && $d <= 7)) ?: [1, 2, 3, 4, 5];
        $dates = array_merge(array_column($pairs, 0), array_column($pairs, 1));
        $closed = [];
        foreach (DB::table('centre_closures')->where('centre_id', $centreId)->where('closure_date', '<=', max($dates))
            ->where(fn ($q) => $q->where('end_date', '>=', min($dates))->orWhereNull('end_date'))->get(['closure_date', 'end_date']) as $c) {
            for ($d = (string) $c->closure_date, $i = 0; $d <= (string) ($c->end_date ?: $c->closure_date) && $i < 400; $i++, $d = date('Y-m-d', strtotime($d . ' +1 day'))) $closed[$d] = true;
        }
        $names = [];
        $nameOf = function (int $uid) use (&$names) {
            if (! isset($names[$uid])) {
                $u = DB::table('users')->where('id', $uid)->first(['first_name', 'last_name']);
                $names[$uid] = $u ? trim($u->first_name . ' ' . $u->last_name) : '';
            }

            return $names[$uid];
        };
        $res = ['added' => 0, 'drafted' => $draft, 'skipped' => []];
        $rows = [];
        $skip = function (int $uid, string $date, string $why) use (&$res, $nameOf) { $res['skipped'][] = ['person' => $nameOf($uid), 'date' => $date, 'why' => $why]; };
        foreach ($pairs as [$src, $dst]) {
            $shift = (int) round((strtotime($dst) - strtotime($src)) / 86400);
            if ($shift === 0) continue;
            foreach (self::asPlanned($centreId, $src, $src, $userId) as $s) {
                $start = Carbon::parse($s['starts_at'])->addDays($shift)->format('Y-m-d H:i:s');
                $end = Carbon::parse($s['ends_at'])->addDays($shift)->format('Y-m-d H:i:s');
                $uid = $s['user_id'];
                if (! empty($closed[$dst])) { $skip($uid, $dst, 'The centre is closed that day'); continue; }
                if (! in_array((int) Carbon::parse($dst)->isoWeekday(), $openDays, true)) { $skip($uid, $dst, 'The centre is not open that day'); continue; }
                if ($start <= $now) { $skip($uid, $dst, 'That time has already passed'); continue; }
                if (! self::mayRoster($uid, $centreId)) { $skip($uid, $dst, 'Not on this centre\'s staff any more'); continue; }
                if ($why = HrAvailability::blocks($agencyId, $uid, $dst, substr($start, 11, 5), substr($end, 11, 5))) { $skip($uid, $dst, $why); continue; }
                if ($why = self::clash($agencyId, $uid, $start, $end)) { $skip($uid, $dst, $why); continue; }
                $row = ['user_id' => $uid, 'room_id' => $s['room_id'], 'starts_at' => $start, 'ends_at' => $end, 'role' => $s['role'], 'preset_id' => $s['preset_id']];
                if ($draft) {
                    DB::table('hr_shift_drafts')->insert($row + ['agency_id' => $agencyId, 'centre_id' => $centreId, 'kind' => 'add', 'created_by_id' => $actorId,
                        'updated_by_id' => $actorId, 'created_at' => now(), 'updated_at' => now()]);
                } else {
                    if (! Schema::hasColumn('shifts', 'preset_id')) unset($row['preset_id']);
                    $sid = (int) DB::table('shifts')->insertGetId($row + ['status' => 'scheduled', 'created_at' => now()]);
                    $rows[] = ['id' => $sid, 'status' => 'scheduled'] + $row;
                }
                $res['added']++;
            }
        }
        if ($rows) HrShiftNotices::added($centreId, $rows, 'copy');

        return $res;
    }

    // ── presets + labour budget ───────────────────────────────────────────────────────────

    public static function presetShape(object $p, array $centres = []): array
    {
        return ['id' => (int) $p->id, 'centre_id' => $p->centre_id ? (int) $p->centre_id : null, 'centre' => $p->centre_id ? ($centres[(int) $p->centre_id] ?? '') : null,
            'name' => $p->name, 'start' => substr((string) $p->start_time, 0, 5), 'end' => substr((string) $p->end_time, 0, 5), 'role' => $p->role,
            'colour' => $p->colour, 'archived' => (bool) $p->archived_at,
            'hours' => HrAvailability::hours('2000-01-01 ' . substr((string) $p->start_time, 0, 5), (substr((string) $p->end_time, 0, 5) <= substr((string) $p->start_time, 0, 5) ? '2000-01-02 ' : '2000-01-01 ') . substr((string) $p->end_time, 0, 5))];
    }

    /** Presets a centre can use: its own and the agency-wide ones (not archived). */
    public static function presetsFor(int $agencyId, int $centreId): array
    {
        if (! Schema::hasTable('hr_shift_presets')) return [];

        return DB::table('hr_shift_presets')->where('agency_id', $agencyId)->whereNull('archived_at')
            ->where(fn ($q) => $q->whereNull('centre_id')->orWhere('centre_id', $centreId))->orderBy('sort')->orderBy('start_time')->orderBy('name')
            ->get()->map(fn ($p) => self::presetShape($p))->all();
    }

    /** A preset id this centre may use, or null. */
    public static function presetOk(int $agencyId, int $centreId, $presetId): ?int
    {
        if (! $presetId || ! Schema::hasTable('hr_shift_presets')) return null;
        $ok = DB::table('hr_shift_presets')->where('id', (int) $presetId)->where('agency_id', $agencyId)
            ->where(fn ($q) => $q->whereNull('centre_id')->orWhere('centre_id', $centreId))->exists();

        return $ok ? (int) $presetId : null;
    }

    /**
     * The projected labour cost of a centre's week from..to as planned (published + drafts), hourly staff only.
     * @return array{hours:float, cost:float, salaried:int, no_rate:int, budget:?float, drafts_hours:float}
     */
    public static function projected(int $centreId, string $from, string $to): array
    {
        $plan = self::asPlanned($centreId, $from, $to);
        $rates = HrAvailability::rates(array_values(array_unique(array_column($plan, 'user_id'))));
        $out = ['hours' => 0.0, 'cost' => 0.0, 'salaried' => 0, 'no_rate' => 0, 'drafts_hours' => 0.0];
        foreach ($plan as $s) {
            $h = HrAvailability::hours($s['starts_at'], $s['ends_at']);
            $out['hours'] += $h;
            if ($s['draft']) $out['drafts_hours'] += $h;
            [$rate, $type] = $rates[$s['user_id']] ?? [null, 'hourly'];
            if ($type !== 'hourly') $out['salaried']++;
            elseif ($rate === null) $out['no_rate']++;
            else $out['cost'] += $h * $rate;
        }
        $out['hours'] = round($out['hours'], 2);
        $out['drafts_hours'] = round($out['drafts_hours'], 2);
        $out['cost'] = round($out['cost'], 2);
        $b = Schema::hasTable('hr_labour_budgets') ? DB::table('hr_labour_budgets')->where('centre_id', $centreId)->value('weekly_amount') : null;
        $out['budget'] = $b !== null ? (float) $b : null;

        return $out;
    }
}
