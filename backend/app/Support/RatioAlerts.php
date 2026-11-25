<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RATIO BREACH ALERTS (2026-10-30). kiddietrac:check-ratios was scheduled only in the old app/Console/Kernel.php
 * (never read by Laravel 11) and the command never existed, so a room going out of ratio told nobody unless someone
 * had Room ratios open. Anthony chose: every 15 minutes, 6 AM-7 PM, alert staff.
 *
 * ONE FORMULA: status() is the same decision the Real-time room ratios screen shows (OperationsV2Controller::ratioStatus
 * calls it too), over App\Support\RatioRules::evaluate - children signed in vs staff on the floor, Ontario rules etc.
 * A room is ALERTED when it is 'breach' (fewer staff than needed) or 'over_group' (over the group size / home cap).
 *
 * ONE ALERT PER EPISODE: ratio_breach_alerts keeps the open episode per room. The first pass that sees the breach
 * alerts (bell + push); later passes stay quiet while it lasts; the pass that sees the room back in ratio closes it
 * with a "back in ratio" bell. An episode left open overnight (the job stops at 7 PM) is closed silently after 12 h.
 *
 * WHO (agencies.settings->ratio_alerts, edited on Room ratios → 🔔 Alerts by agency admins):
 *   enabled (true) · notify_educators (true): the room's educators on the floor - on an active shift in the room, or
 *   clocked in and assigned to it; if nobody is, everyone clocked in at the centre · notify_directors (true): the
 *   centre's directors (a centre with no director: the agency admins instead) · notify_admins (false): every agency admin.
 */
final class RatioAlerts
{
    public const ALERT = ['breach', 'over_group'];
    public const DEFAULTS = ['enabled' => true, 'notify_educators' => true, 'notify_directors' => true, 'notify_admins' => false];

    /** The room's status from a RatioRules::evaluate() result - the Room ratios screen's own rule. */
    public static function status(array $v): string
    {
        $have = (float) $v['educators_present'];
        $need = (int) $v['required_educators'];
        $kids = (int) $v['children_present'];
        $overGroup = ! empty($v['over_group']) || ! empty($v['home']['over_cap']);
        $issues = (array) ($v['compliance_issues'] ?? []);

        return match (true) {
            $have < $need => 'breach',
            $overGroup => 'over_group',
            (bool) $issues => 'attention',
            $kids > 0 && $have - $need < 1 => 'tight',
            default => 'ok',
        };
    }

    public static function settings(int $agencyId): array
    {
        $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
        $mine = is_array($s['ratio_alerts'] ?? null) ? $s['ratio_alerts'] : [];
        $out = self::DEFAULTS;
        foreach (self::DEFAULTS as $k => $d) if (array_key_exists($k, $mine)) $out[$k] = (bool) $mine[$k];
        return $out;
    }

    public static function saveSettings(int $agencyId, array $in): array
    {
        $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
        $cur = self::settings($agencyId);
        foreach (self::DEFAULTS as $k => $d) if (array_key_exists($k, $in)) $cur[$k] = (bool) $in[$k];
        $s['ratio_alerts'] = $cur;
        DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($s), 'updated_at' => now()]);
        return $cur;
    }

    /** The people to tell about this room, per the agency's settings. */
    public static function recipients(object $room, int $agencyId, array $cfg): array
    {
        $ids = [];
        $now = now();
        if ($cfg['notify_educators']) {
            [$from, $to] = AgencyTime::dayRangeForCentre((int) $room->centre_id, null);
            $clocked = DB::table('time_punches')->where('centre_id', $room->centre_id)
                ->where('punched_in_at', '>=', $from)->where('punched_in_at', '<', $to)->whereNull('punched_out_at')
                ->pluck('user_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
            $onShift = DB::table('shifts')->where('room_id', $room->id)->where('status', 'active')
                ->where('starts_at', '<=', $now)->where('ends_at', '>', $now)->pluck('user_id')->map(fn ($v) => (int) $v)->all();
            $assigned = DB::table('educator_rooms')->where('room_id', $room->id)->pluck('user_id')->map(fn ($v) => (int) $v)->all();
            $here = array_values(array_unique(array_merge($onShift, array_intersect($assigned, $clocked))));
            $ids = array_merge($ids, $here ?: $clocked);
        }
        $admins = false;
        if ($cfg['notify_directors']) {
            $dirs = DB::table('role_assignments')->where('role', 'centre_director')->where('active', 1)
                ->where('agency_id', $agencyId)->where('centre_id', $room->centre_id)->pluck('user_id')->map(fn ($v) => (int) $v)->all();
            $ids = array_merge($ids, $dirs);
            $admins = ! $dirs;   // a centre with no director: its agency admins stand in, so management always hears
        }
        if ($cfg['notify_admins'] || $admins) {
            $ids = array_merge($ids, DB::table('role_assignments')->where('role', 'agency_admin')->where('active', 1)
                ->where('agency_id', $agencyId)->pluck('user_id')->map(fn ($v) => (int) $v)->all());
        }
        // Only people who can still sign in to THIS agency (Notify::write drops the rest too; push must not reach them).
        $ids = array_values(array_unique(array_filter($ids)));
        if (! $ids) return [];
        return DB::table('users')->whereIn('id', $ids)->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'active'))
            ->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * One pass over every agency. $dry: evaluate and report only (nothing written, nothing sent).
     * Returns ['rooms' => n, 'alerted' => [...], 'cleared' => [...], 'quiet' => n].
     */
    public static function run(bool $dry = false, ?int $onlyAgency = null): array
    {
        $out = ['rooms' => 0, 'alerted' => [], 'cleared' => [], 'ongoing' => 0, 'off' => 0];
        // An episode open more than 12 hours (left over from yesterday) is closed without a message.
        if (! $dry) {
            DB::table('ratio_breach_alerts')->whereNull('cleared_at')->where('started_at', '<', now()->subHours(12))
                ->update(['cleared_at' => now(), 'cleared_quietly' => 1]);
        }
        $agencies = DB::table('agencies')->whereNull('deleted_at')->when($onlyAgency, fn ($q) => $q->where('id', $onlyAgency))->pluck('id');
        foreach ($agencies as $agencyId) {
            $agencyId = (int) $agencyId;
            $cfg = self::settings($agencyId);
            $centres = DB::table('centres as c')->where('c.agency_id', $agencyId)->where(fn ($q) => Live::centres($q, 'c'))->pluck('c.id', 'c.id')->all();
            if (! $centres) continue;
            if (! $cfg['enabled']) {
                $out['off']++;
                if (! $dry) DB::table('ratio_breach_alerts')->where('agency_id', $agencyId)->whereNull('cleared_at')
                    ->update(['cleared_at' => now(), 'cleared_quietly' => 1]);
                continue;
            }
            $names = DB::table('centres')->whereIn('id', array_keys($centres))->pluck('name', 'id');
            $rooms = DB::table('rooms')->whereIn('centre_id', array_keys($centres))->where('active', true)->get();
            foreach ($rooms as $room) {
                $out['rooms']++;
                try {
                    $v = RatioRules::evaluate($room);
                } catch (\Throwable $e) {
                    Log::warning('check-ratios: evaluate failed', ['room' => $room->id, 'error' => $e->getMessage()]);
                    continue;
                }
                $st = self::status($v);
                $open = DB::table('ratio_breach_alerts')->where('room_id', $room->id)->whereNull('cleared_at')->orderByDesc('id')->first();
                $place = $room->name . ' · ' . ($names[$room->centre_id] ?? '');
                if (in_array($st, self::ALERT, true)) {
                    if ($open) { $out['ongoing']++; continue; }
                    $who = self::recipients($room, $agencyId, $cfg);
                    $kids = (int) $v['children_present'];
                    $have = (float) $v['educators_present'];
                    $need = (int) $v['required_educators'];
                    $title = '⚠ Out of ratio: ' . $room->name;
                    $body = $st === 'over_group'
                        ? $kids . ' children in ' . $place . ' - over the group size. Move children or split the group.'
                        : $kids . ' children, ' . self::n($have) . ' of ' . $need . ' staff needed in ' . $place . '. Send another educator in.';
                    $out['alerted'][] = ['agency' => $agencyId, 'room' => (int) $room->id, 'place' => $place, 'status' => $st, 'to' => count($who), 'body' => $body];
                    if ($dry) continue;
                    DB::table('ratio_breach_alerts')->insert([
                        'agency_id' => $agencyId, 'centre_id' => (int) $room->centre_id, 'room_id' => (int) $room->id, 'status' => $st,
                        'children' => $kids, 'staff' => $have, 'required' => $need, 'recipients' => json_encode($who),
                        'started_at' => now(), 'alerted_at' => now(), 'created_at' => now(),
                    ]);
                    self::tell($who, $title, $body, true, $agencyId);
                } elseif ($open) {
                    $out['cleared'][] = ['agency' => $agencyId, 'room' => (int) $room->id, 'place' => $place];
                    if ($dry) continue;
                    DB::table('ratio_breach_alerts')->where('id', $open->id)->update(['cleared_at' => now()]);
                    $who = array_values(array_filter(array_map('intval', (array) json_decode((string) $open->recipients, true))));
                    $mins = max(1, (int) round(Carbon::parse($open->started_at)->diffInMinutes(now(), true)));
                    self::tell($who, '✓ Back in ratio: ' . $room->name, $place . ' is back in ratio (out for about ' . $mins . ' min).', false, $agencyId);
                }
            }
        }
        return $out;
    }

    private static function n(float $v): string
    {
        return floor($v) == $v ? (string) (int) $v : number_format($v, 1);
    }

    private static function tell(array $userIds, string $title, string $body, bool $push, ?int $agencyId = null): void
    {
        if (\App\Support\TestAgency::skips($agencyId, 'ratio alert')) return;   // test agency (2026-11-25)
        foreach ($userIds as $uid) {
            try {
                Notify::write(['agency_id' => $agencyId, 'user_id' => $uid, 'type' => 'ratio_breach', 'title' => $title, 'body' => $body,
                    'data' => json_encode(['link' => '#room-ratios']), 'created_at' => now()]);
                if ($push) app(\App\Services\FcmService::class)->sendToUser($uid, $title, $body, '#room-ratios');
            } catch (\Throwable $e) {
                Log::warning('check-ratios: notify failed', ['user' => $uid, 'error' => $e->getMessage()]);
            }
        }
    }
}
