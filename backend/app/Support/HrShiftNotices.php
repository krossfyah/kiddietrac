<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KIDDIETRAC HR PHASE 4, BATCH 3 (2026-11-27): SHIFT NOTICES TO STAFF.
 *
 * Staff hear when a shift of theirs is added (published / assigned), changed, cancelled or removed: one bell row (which
 * rings the phone, Notify::write) and one email per person PER BATCH OF CHANGES, never one per shift.
 *
 * HOW THE BATCH WORKS. Every path that writes upcoming shifts calls queue() (via added / removed / changed): one row per
 * person per shift in hr_shift_changes, nothing sent. The hr:shift-notices job (kt:minute two) waits until a person's
 * changes have been quiet for quiet_minutes (default 5) - or 30 minutes after the first one, whichever comes first -
 * then nets them per shift (added then removed = nothing; changed back = nothing) and sends ONE notice listing them.
 * So an autofill of four weeks, a standing-hours rewrite or a director clicking through a week of edits each give a
 * person a single notice. Rows are claimed (sent_at) before anything is sent, so two runs can never send twice.
 *
 * WHO WRITES TO THE QUEUE: SchedulingController (create, edit, delete, Autofill button, standing hours "apply"),
 * StandingShifts::fill, StaffOffboardController (shifts handed to someone else), schedule:autofill (the nightly rolling
 * fill, ONLY when the agency turns nightly_fill on - it adds a day at a time and would otherwise notify every night).
 * Shift swaps and open shifts send their own immediate notices (HrScheduleController / HrOpenShifts) and are not queued.
 * A change a person makes to their own shift, and a shift that has already ended, are not queued.
 *
 * RULES: HR add-on only (Features), the agency setting agencies.settings.hr_shift_notices {enabled, email, nightly_fill,
 * quiet_minutes}; the person's "My shifts" switch (StaffNotifyPrefs topic staff_shifts: app = bell + phone, email);
 * test agencies send nothing (rows are closed with outcome "test"); super admins / integration accounts never get them
 * (Notify::write + AgencyOutsiders); first names only in the email. Shift times are agency wall-clock times.
 */
final class HrShiftNotices
{
    public const DEFAULTS = ['enabled' => true, 'email' => true, 'nightly_fill' => false, 'quiet_minutes' => 5];
    public const QUIET = [2, 5, 10, 15, 30];
    public const MAX_WAIT_MIN = 30;
    public const TOPIC = 'staff_shifts';

    private static ?bool $ready = null;
    /** @var array<string,mixed> */
    private static array $memo = [];

    public static function ready(): bool
    {
        return self::$ready ??= Schema::hasTable('hr_shift_changes');
    }

    public static function settings(int $agencyId): array
    {
        $all = json_decode((string) DB::table('agencies')->where('id', $agencyId)->value('settings'), true);
        $raw = is_array($all['hr_shift_notices'] ?? null) ? $all['hr_shift_notices'] : [];
        $s = array_replace(self::DEFAULTS, array_intersect_key($raw, self::DEFAULTS));
        $s['enabled'] = (bool) $s['enabled'];
        $s['email'] = (bool) $s['email'];
        $s['nightly_fill'] = (bool) $s['nightly_fill'];
        $s['quiet_minutes'] = in_array((int) $s['quiet_minutes'], self::QUIET, true) ? (int) $s['quiet_minutes'] : 5;

        return $s;
    }

    public static function saveSettings(int $agencyId, array $in): array
    {
        DB::transaction(function () use ($agencyId, $in) {
            $row = DB::table('agencies')->where('id', $agencyId)->lockForUpdate()->first(['settings']);
            $all = json_decode((string) ($row->settings ?? ''), true);
            $all = is_array($all) ? $all : [];
            $all['hr_shift_notices'] = array_intersect_key($in, self::DEFAULTS);
            DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        });
        unset(self::$memo['on:' . $agencyId]);

        return self::settings($agencyId);
    }

    /** Is this agency queueing notices from this source? */
    private static function queues(int $agencyId, string $source): bool
    {
        if (! $agencyId || ! self::ready()) return false;
        $k = 'on:' . $agencyId;
        if (! array_key_exists($k, self::$memo)) {
            self::$memo[$k] = Features::on($agencyId, HrLeave::FEATURE) ? self::settings($agencyId) : null;
        }
        $s = self::$memo[$k];

        return $s !== null && $s['enabled'] && ($source !== 'nightly' || $s['nightly_fill']);
    }

    private static function agencyOfCentre(int $centreId): int
    {
        return (int) (self::$memo['c:' . $centreId] ??= (int) DB::table('centres')->where('id', $centreId)->value('agency_id'));
    }

    private static function hm(string $dt): string
    {
        try {
            return Carbon::parse($dt)->format('Y-m-d H:i');
        } catch (\Throwable $e) {
            return substr($dt, 0, 16);
        }
    }

    /** A comparable picture of a shift (object or array): starts, ends, room, role, status. */
    public static function snap($s): array
    {
        $s = (array) $s;

        return ['starts_at' => self::hm((string) ($s['starts_at'] ?? '')), 'ends_at' => self::hm((string) ($s['ends_at'] ?? '')),
            'room_id' => (int) ($s['room_id'] ?? 0), 'role' => (string) ($s['role'] ?? ''), 'status' => (string) ($s['status'] ?? 'scheduled')];
    }

    /**
     * Queue changes at one centre. $items: [[user_id, shift_id|null, before snap|null, after snap|null], ...].
     * Never throws: a notice is never worth failing the rota change that caused it.
     */
    public static function queue(int $centreId, array $items, string $source): int
    {
        try {
            $aid = self::agencyOfCentre($centreId);
            if (! $items || ! self::queues($aid, $source)) return 0;
            $now = Carbon::now(AgencyTime::tzForCentre($centreId))->format('Y-m-d H:i');
            $actor = null;
            if ($source !== 'nightly') {
                try { $actor = optional(request()->user())->id; } catch (\Throwable $e) { $actor = null; }
            }
            $rows = [];
            $at = now();
            foreach ($items as [$uid, $sid, $b, $a]) {
                $uid = (int) $uid;
                if ($uid <= 0 || ($actor && $uid === (int) $actor)) continue;   // your own change: you know
                if ($b == $a) continue;
                $end = max((string) ($b['ends_at'] ?? ''), (string) ($a['ends_at'] ?? ''));
                if ($end !== '' && $end <= $now) continue;                      // already over
                $rows[] = ['agency_id' => $aid, 'centre_id' => $centreId, 'user_id' => $uid, 'shift_id' => $sid ? (int) $sid : null,
                    'source' => $source, 'before' => $b ? json_encode($b) : null, 'after' => $a ? json_encode($a) : null,
                    'actor_id' => $actor ? (int) $actor : null, 'created_at' => $at];
            }
            foreach (array_chunk($rows, 300) as $chunk) DB::table('hr_shift_changes')->insert($chunk);

            return count($rows);
        } catch (\Throwable $e) {
            report($e);

            return 0;
        }
    }

    /**
     * New shifts written in bulk (arrays with user_id, room_id, starts_at, ends_at, role, status, created_at). Their ids
     * are looked up afterwards so a later edit of the same shift nets against it.
     */
    public static function added(int $centreId, array $rows, string $source): int
    {
        if (! $rows) return 0;
        try {
            if (! self::queues(self::agencyOfCentre($centreId), $source)) return 0;
            $ids = [];
            if (empty($rows[0]['id'])) {
                $q = DB::table('shifts')->whereIn('room_id', array_values(array_unique(array_map(fn ($r) => (int) $r['room_id'], $rows))))
                    ->whereIn('user_id', array_values(array_unique(array_map(fn ($r) => (int) $r['user_id'], $rows))))
                    ->whereBetween('starts_at', [min(array_column($rows, 'starts_at')), max(array_column($rows, 'starts_at'))]);
                foreach ($q->get(['id', 'user_id', 'room_id', 'starts_at']) as $s) {
                    $ids[$s->user_id . '|' . $s->room_id . '|' . self::hm((string) $s->starts_at)] = (int) $s->id;
                }
            }
            $items = [];
            foreach ($rows as $r) {
                $snap = self::snap($r);
                $id = ! empty($r['id']) ? (int) $r['id'] : ($ids[(int) $r['user_id'] . '|' . (int) $r['room_id'] . '|' . $snap['starts_at']] ?? null);
                $items[] = [(int) $r['user_id'], $id, null, $snap];
            }

            return self::queue($centreId, $items, $source);
        } catch (\Throwable $e) {
            report($e);

            return 0;
        }
    }

    /** Shifts about to be deleted (rows read before the delete). */
    public static function removed(int $centreId, $shifts, string $source): int
    {
        $items = [];
        foreach ($shifts as $s) $items[] = [(int) $s->user_id, (int) $s->id, self::snap($s), null];

        return self::queue($centreId, $items, $source);
    }

    /** One shift edited: $before = the row before, $changes = the columns written. A new person = removed + added. */
    public static function changed(int $centreId, object $before, array $changes, string $source): int
    {
        $after = (object) array_replace((array) $before, $changes);
        $b = self::snap($before);
        $a = self::snap($after);
        if ((int) $after->user_id !== (int) $before->user_id) {
            return self::queue($centreId, [[(int) $before->user_id, (int) $before->id, $b, null], [(int) $after->user_id, (int) $before->id, null, $a]], $source);
        }

        return self::queue($centreId, [[(int) $before->user_id, (int) $before->id, $b, $a]], $source);
    }

    // ── sending ─────────────────────────────────────────────────────────────────────────────

    /** Agencies with changes waiting. @return int[] */
    public static function pendingAgencies(?int $only = null): array
    {
        if (! self::ready()) return [];

        return DB::table('hr_shift_changes')->whereNull('sent_at')->when($only, fn ($q) => $q->where('agency_id', $only))
            ->distinct()->pluck('agency_id')->map(fn ($v) => (int) $v)->all();
    }

    /** Close an agency's waiting rows without sending (feature off, notices off, test agency). */
    public static function close(int $agencyId, string $outcome): int
    {
        return DB::table('hr_shift_changes')->where('agency_id', $agencyId)->whereNull('sent_at')->update(['sent_at' => now(), 'outcome' => $outcome]);
    }

    /**
     * The net change per shift for one person's rows: [['kind', before|null, after|null, centre_id], ...] (upcoming only).
     * kind: added | changed | cancelled | removed.
     */
    public static function net($rows): array
    {
        $by = [];
        foreach (collect($rows)->sortBy('id') as $r) {
            $k = $r->shift_id ? 's' . $r->shift_id : 'r' . $r->id;
            $b = $r->before ? json_decode((string) $r->before, true) : null;
            $a = $r->after ? json_decode((string) $r->after, true) : null;
            if (! isset($by[$k])) $by[$k] = ['b' => $b, 'a' => $a, 'c' => (int) $r->centre_id];
            else $by[$k]['a'] = $a;
        }
        $out = [];
        $nowBy = [];
        foreach ($by as $x) {
            [$b, $a] = [$x['b'], $x['a']];
            if (! $b && ! $a) continue;
            if ($b && $a && $b == $a) continue;
            $nowBy[$x['c']] ??= Carbon::now(AgencyTime::tzForCentre($x['c']))->format('Y-m-d H:i');
            if ((string) (($a ?: $b)['ends_at'] ?? '') <= $nowBy[$x['c']]) continue;
            if (! $b) $kind = ($a['status'] ?? '') === 'cancelled' ? null : 'added';
            elseif (! $a) $kind = ($b['status'] ?? '') === 'cancelled' ? null : 'removed';
            elseif (($a['status'] ?? '') === 'cancelled' && ($b['status'] ?? '') !== 'cancelled') $kind = 'cancelled';
            elseif (($b['status'] ?? '') === 'cancelled' && ($a['status'] ?? '') !== 'cancelled') $kind = 'added';
            elseif (($a['status'] ?? '') === 'cancelled') $kind = null;
            else $kind = 'changed';
            if ($kind) $out[] = [$kind, $b, $a, $x['c']];
        }
        /* A rewrite deletes and re-inserts (new ids): the same shift removed and added again is no change, and a removed
           + added on the same day reads as one changed shift. */
        $sig = fn (array $s) => $s['starts_at'] . '|' . $s['ends_at'] . '|' . $s['room_id'] . '|' . $s['role'];
        $addedAt = [];
        foreach ($out as $i => $it) if ($it[0] === 'added') $addedAt[$sig($it[2])][] = $i;
        foreach ($out as $i => $it) {
            if ($it[0] !== 'removed') continue;
            $k = $sig($it[1]);
            if (! empty($addedAt[$k])) { $j = array_shift($addedAt[$k]); unset($out[$i], $out[$j]); }
        }
        foreach ($out as $i => $it) {
            if ($it[0] !== 'removed') continue;
            foreach ($out as $j => $jt) {
                if ($jt[0] === 'added' && substr($jt[2]['starts_at'], 0, 10) === substr($it[1]['starts_at'], 0, 10)) {
                    $out[$i] = ['changed', $it[1], $jt[2], $jt[3]];
                    unset($out[$j]);
                    break;
                }
            }
        }
        $out = array_values($out);
        usort($out, fn ($p, $q) => strcmp((string) (($p[2] ?: $p[1])['starts_at'] ?? ''), (string) (($q[2] ?: $q[1])['starts_at'] ?? '')));

        return $out;
    }

    /** People whose batch is ready (quiet long enough, or waited the maximum): [user_id => [rows]]. */
    public static function due(int $agencyId, ?Carbon $now = null): array
    {
        $now = $now ?: now();
        $quiet = self::settings($agencyId)['quiet_minutes'];
        $out = [];
        foreach (DB::table('hr_shift_changes')->where('agency_id', $agencyId)->whereNull('sent_at')->orderBy('id')->get()->groupBy('user_id') as $uid => $rows) {
            $last = Carbon::parse((string) $rows->max('created_at'));
            $first = Carbon::parse((string) $rows->min('created_at'));
            if ($last->gt($now->copy()->subMinutes($quiet)) && $first->gt($now->copy()->subMinutes(self::MAX_WAIT_MIN))) continue;
            $out[(int) $uid] = $rows->all();
        }

        return $out;
    }

    private static function day(string $dt): string
    {
        return Carbon::parse($dt)->format('D, M j');
    }

    private static function span(array $s): string
    {
        return Carbon::parse($s['starts_at'])->format('g:i A') . ' – ' . Carbon::parse($s['ends_at'])->format('g:i A');
    }

    /** One line per net change, for the bell and the email. */
    public static function lines(array $items): array
    {
        $roomIds = [];
        foreach ($items as [$k, $b, $a]) {
            foreach ([$b, $a] as $s) if ($s && ! empty($s['room_id'])) $roomIds[] = (int) $s['room_id'];
        }
        $rooms = DB::table('rooms as r')->join('centres as c', 'c.id', '=', 'r.centre_id')->whereIn('r.id', $roomIds ?: [0])
            ->get(['r.id', 'r.name', 'c.name as centre'])->keyBy('id');
        $where = function (?array $s) use ($rooms) {
            $r = $s ? ($rooms[(int) ($s['room_id'] ?? 0)] ?? null) : null;

            return $r ? $r->name : '';
        };
        $out = [];
        foreach ($items as [$kind, $b, $a]) {
            $s = $a ?: $b;
            $when = self::day($s['starts_at']) . ', ' . self::span($s);
            $room = $where($s);
            if ($kind === 'changed') {
                $was = [];
                if (substr($b['starts_at'], 0, 10) !== substr($a['starts_at'], 0, 10)) $was[] = self::day($b['starts_at']) . ', ' . self::span($b);
                elseif ($b['starts_at'] !== $a['starts_at'] || $b['ends_at'] !== $a['ends_at']) $was[] = self::span($b);
                if ((int) $b['room_id'] !== (int) $a['room_id'] && $where($b) !== '') $was[] = $where($b);
                if ($b['role'] !== $a['role'] && $b['role'] !== '') $was[] = ucfirst($b['role']);
                $out[] = ['kind' => $kind, 'label' => 'Changed', 'when' => $when, 'room' => $room, 'was' => implode(', ', $was)];
            } else {
                $out[] = ['kind' => $kind, 'label' => ['added' => 'New', 'cancelled' => 'Cancelled', 'removed' => 'Removed'][$kind], 'when' => $when, 'room' => $room, 'was' => ''];
            }
        }

        return $out;
    }

    /** The bell title for a batch. */
    public static function title(array $lines): string
    {
        $n = count($lines);
        $kinds = array_values(array_unique(array_column($lines, 'kind')));
        if ($n === 1) {
            $l = $lines[0];
            $d = explode(', ', $l['when'])[0] . ', ' . (explode(', ', $l['when'])[1] ?? '');

            return ['added' => 'New shift: ', 'changed' => 'Shift changed: ', 'cancelled' => 'Shift cancelled: ', 'removed' => 'Shift removed: '][$l['kind']] . $d;
        }
        if ($kinds === ['added']) return $n . ' new shifts on your schedule';
        if (! array_diff($kinds, ['cancelled', 'removed'])) return $n . ' of your shifts were cancelled';

        return 'Your schedule changed (' . $n . ' updates)';
    }

    /**
     * Send every ready batch of one agency. The caller has checked the HR add-on (Features) and the test agency.
     * @return array{people:int, sent:int, nothing:int, preview:array}
     */
    public static function sendAgency(int $agencyId, bool $dry = false, ?Carbon $now = null): array
    {
        $res = ['people' => 0, 'sent' => 0, 'nothing' => 0, 'preview' => []];
        $s = self::settings($agencyId);
        $staff = array_flip(HrLeave::staffIds($agencyId));
        foreach (self::due($agencyId, $now) as $uid => $rows) {
            $res['people']++;
            $items = self::net($rows);
            $ids = array_map(fn ($r) => (int) $r->id, $rows);
            if ($dry) {
                $res['preview'][] = ['user_id' => $uid, 'changes' => count($rows), 'lines' => $items ? self::lines($items) : []];
                continue;
            }
            // Claim the rows first: a second run (or an overlapping one) finds them taken and sends nothing.
            $outcome = $items && isset($staff[$uid]) ? 'sent' : 'nothing';
            $got = DB::table('hr_shift_changes')->whereIn('id', $ids)->whereNull('sent_at')->update(['sent_at' => now(), 'outcome' => $outcome]);
            if ($got !== count($ids) || $outcome !== 'sent') {
                $res['nothing']++;
                continue;
            }
            self::deliver($agencyId, $uid, self::lines($items), $s);
            $res['sent']++;
        }

        return $res;
    }

    private static function deliver(int $agencyId, int $uid, array $lines, array $s): void
    {
        $title = self::title($lines);
        $body = implode("\n", array_map(fn ($l) => $l['label'] . ': ' . $l['when'] . ($l['room'] !== '' ? ' (' . $l['room'] . ')' : '') . ($l['was'] !== '' ? ' - was ' . $l['was'] : ''), array_slice($lines, 0, 4)))
            . (count($lines) > 4 ? "\n+" . (count($lines) - 4) . ' more' : '');
        try {
            Notify::write(['user_id' => $uid, 'agency_id' => $agencyId, 'type' => 'shift', 'title' => $title, 'body' => $body,
                'data' => json_encode(['link' => '#my-hr', 'kind' => 'own', 'count' => count($lines)]), 'created_at' => now()]);
        } catch (\Throwable $e) {
            report($e);
        }
        if (! $s['email'] || ! StaffNotifyPrefs::wants($uid, self::TOPIC, 'email', $agencyId)) return;
        try {
            if (Suppression::isUser($uid) || AgencyOutsiders::isOutsider($uid) || TestAgency::skips($agencyId, 'shift notice email', ['user' => $uid])) return;
            $u = DB::table('users')->where('id', $uid)->whereNull('deleted_at')->first(['id', 'first_name', 'email']);
            if (! $u || ! filter_var((string) $u->email, FILTER_VALIDATE_EMAIL)) return;
            $html = EmailTemplate::wrap($agencyId, self::emailBody($lines, (string) $u->first_name), [
                'eyebrow' => 'YOUR SCHEDULE', 'title' => $title,
                'preheader' => count($lines) === 1 ? $lines[0]['label'] . ': ' . $lines[0]['when'] : count($lines) . ' changes to your shifts',
            ]);
            AgencyMailer::forAgency($agencyId)->html($html, function ($m) use ($u, $title) {
                $m->to($u->email, trim((string) $u->first_name) ?: null)->subject($title);
            });
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Shift notice email failed', ['user' => $uid, 'error' => $e->getMessage()]);
        }
    }

    /** The email body (first name only; colours with dark-mode-safe tints, as the house emails do). */
    public static function emailBody(array $lines, string $firstName): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $tone = ['added' => ['#DCFCE7', '#166534'], 'changed' => ['#E0F2FE', '#075985'], 'cancelled' => ['#FEE2E2', '#991B1B'], 'removed' => ['#FEE2E2', '#991B1B']];
        $rows = '';
        foreach ($lines as $l) {
            [$bg, $fg] = $tone[$l['kind']];
            $rows .= '<tr><td style="padding:8px 0;border-bottom:1px solid #E2E8F0;vertical-align:top;width:92px;">'
                . '<span style="display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:700;background:' . $bg . ';color:' . $fg . ';">' . $e($l['label']) . '</span></td>'
                . '<td style="padding:8px 0 8px 10px;border-bottom:1px solid #E2E8F0;font-size:14px;line-height:1.5;color:#0F172A;"><strong>' . $e($l['when']) . '</strong>'
                . ($l['room'] !== '' ? '<br><span style="color:#64748B;">' . $e($l['room']) . '</span>' : '')
                . ($l['was'] !== '' ? '<br><span style="color:#64748B;">Was: ' . $e($l['was']) . '</span>' : '') . '</td></tr>';
        }

        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">'
            . '<tr><td style="font-size:15px;line-height:1.6;color:#334155;padding:0 0 12px;">' . ($firstName !== '' ? 'Hi ' . $e($firstName) . ', y' : 'Y')
            . 'our schedule has ' . (count($lines) === 1 ? 'a change' : count($lines) . ' changes') . ':</td></tr>'
            . '<tr><td><table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">' . $rows . '</table></td></tr>'
            . '<tr><td style="padding:16px 0 0;font-size:13px;line-height:1.6;color:#64748B;">See all your shifts in My HR. You can choose how you hear about shift changes in Settings &gt; Notifications &gt; My shifts.</td></tr></table>';
    }
}
