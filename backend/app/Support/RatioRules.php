<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * RATIO ENGINE v2 — one place that answers "is this room in ratio?" (2026-10-09).
 *
 * Anthony said "go ahead" on the compliance roadmap; this is its ratio step. Before this, the
 * same arithmetic lived in three copies (RoomController::currentRatio for the educator card,
 * CentreController::getRoomStats for the centre dashboard, RatioEngine for history) and every
 * copy used the ROOM's flat ratio: ceil(children / room.ratio_children). That is Ontario's
 * rule as the rooms were set up, and nothing else.
 *
 * Now:
 *  - WHO IS HERE. Children: the check-ins still open (the same query the card always used,
 *    same-second tie-break included). Educators: active shifts first; otherwise open
 *    clock-ins at the centre — apportioned across the centre's rooms (the original rule,
 *    still the default), or, when the agency opts in, counted in the room a person is
 *    assigned to (educator_rooms) with only the unassigned apportioned. See educatorSource().
 *  - WHAT IS REQUIRED. The agency's jurisdiction (App\Support\Jurisdictions) supplies a ratio
 *    table only when it is age-mapped and verified (today: Ohio). Then the youngest child
 *    present sets the ratio and the group-size limit; home licences (Ohio Type A/B) use the
 *    home rules instead (7 per staff, no more than 3 under 2 each, a total cap). Nap relief
 *    and the second-adult rule are reported, not used to excuse a breach.
 *    Every other jurisdiction — Ontario included — keeps the room's own ratio, exactly as
 *    before, because its table is not age-mapped. Unknown is never enforced.
 */
final class RatioRules
{
    /** 'apportion' (original, default) | 'room_assignment' — per-agency, agencies.settings->ratio. */
    public static function educatorSource(int $agencyId): string
    {
        $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
        $v = $s['ratio']['educator_source'] ?? 'apportion';

        return in_array($v, ['apportion', 'room_assignment'], true) ? $v : 'apportion';
    }

    /** Children present in the room now ($at null) or at a past moment. Distinct child ids. */
    public static function childIdsPresent(object $room, ?Carbon $at = null): array
    {
        $day = $at ? $at->copy()->setTimezone(AgencyTime::tzForCentre((int) $room->centre_id))->toDateString() : null;
        [$from, $to] = AgencyTime::dayRangeForCentre((int) $room->centre_id, $day);
        $q = DB::table('check_events as ci')
            ->where('ci.room_id', $room->id)
            ->where('ci.event_type', 'check_in')
            ->where('ci.occurred_at', '>=', $from)->where('ci.occurred_at', '<', $to);
        if ($at) $q->where('ci.occurred_at', '<=', $at);
        $q->whereNotExists(function ($n) use ($at) {
            $n->select(DB::raw(1))->from('check_events as co')
                ->whereColumn('co.child_id', 'ci.child_id')
                ->where('co.event_type', 'check_out')
                ->where(fn ($w) => $w->whereColumn('co.occurred_at', '>', 'ci.occurred_at')
                    ->orWhere(fn ($w2) => $w2->whereColumn('co.occurred_at', 'ci.occurred_at')->whereColumn('co.id', '>', 'ci.id')));
            if ($at) $n->where('co.occurred_at', '<=', $at);
        });

        return array_values(array_unique(array_map('intval', $q->pluck('ci.child_id')->all())));
    }

    /** People clocked in at the centre at the moment (open punches on that agency day). */
    private static function openPunchUserIds(object $room, ?Carbon $at): array
    {
        $day = $at ? $at->copy()->setTimezone(AgencyTime::tzForCentre((int) $room->centre_id))->toDateString() : null;
        [$from, $to] = AgencyTime::dayRangeForCentre((int) $room->centre_id, $day);
        $q = DB::table('time_punches')->where('centre_id', $room->centre_id)
            ->where('punched_in_at', '>=', $from)->where('punched_in_at', '<', $to);
        if ($at) {
            $q->where('punched_in_at', '<=', $at)->where(fn ($w) => $w->whereNull('punched_out_at')->orWhere('punched_out_at', '>=', $at));
        } else {
            $q->whereNull('punched_out_at');
        }

        return $q->pluck('user_id')->map(fn ($v) => (int) $v)->all();   // one row per open punch
    }

    /**
     * Educators present in this room. Active shifts win; otherwise clock-ins.
     * Returns [count, source] where source says how it was counted.
     */
    public static function educatorsPresent(object $room, ?Carbon $at = null, ?string $mode = null): array
    {
        $when = $at ?: now();
        $shifts = DB::table('shifts')->where('room_id', $room->id)
            ->where('starts_at', '<=', $when)->where('ends_at', '>', $when)->where('status', 'active')->count();
        if ($shifts > 0) return [$shifts, 'shifts'];

        $punches = self::openPunchUserIds($room, $at);
        if (! $punches) return [0, 'clock_ins'];
        $rooms = DB::table('rooms')->where('centre_id', $room->centre_id)->where('active', true)->pluck('id')->map(fn ($v) => (int) $v)->all();
        $nRooms = max(1, count($rooms));
        $mode = $mode ?: self::educatorSource(self::agencyOfCentre((int) $room->centre_id));

        if ($mode !== 'room_assignment') {
            return [(int) ceil(count($punches) / $nRooms), 'clock_ins_shared'];
        }
        // Assigned to exactly one active room here → counted there. Anyone else is shared.
        $assign = DB::table('educator_rooms')->whereIn('user_id', array_unique($punches))->whereIn('room_id', $rooms ?: [0])
            ->get(['user_id', 'room_id'])->groupBy('user_id');
        $here = 0; $shared = 0;
        foreach ($punches as $uid) {
            $mine = isset($assign[$uid]) ? $assign[$uid]->pluck('room_id')->map(fn ($v) => (int) $v)->unique()->values()->all() : [];
            if (count($mine) === 1) { if ($mine[0] === (int) $room->id) $here++; }
            else $shared++;
        }

        // The unassigned are shared out rounding DOWN: this option must never count a person twice.
        return [$here + intdiv($shared, $nRooms), 'clock_ins_by_room'];
    }

    public static function agencyOfCentre(int $centreId): int
    {
        static $c = [];
        return $c[$centreId] ??= (int) (DB::table('centres')->where('id', $centreId)->value('agency_id') ?: 0);
    }

    /** The jurisdiction's centre bands, only when every band is age-mapped (verified tables). */
    private static function bands(array $profile): ?array
    {
        $centre = $profile['ratios']['centre'] ?? null;
        if (! is_array($centre) || ! $centre) return null;
        foreach ($centre as $b) {
            $hasRange = array_key_exists('min_months', $b) && ($b['min_months'] !== null || ($b['band'] ?? '') === 'school_age');
            if (! $hasRange || empty($b['ratio'])) return null;
        }

        return $centre;
    }

    /** The band for a child's age (months). School-age is assumed from 72 months (6 y). */
    private static function bandFor(array $bands, int $months): ?array
    {
        $by = [];
        foreach ($bands as $b) $by[$b['band']] = $b;
        if (isset($by['school_age_older']) && $months >= 132 && $months < 192) return $by['school_age_older'];
        if (isset($by['school_age']) && $months >= 72) return $by['school_age'];
        foreach ($bands as $b) {
            if ($b['min_months'] === null) continue;
            if ($months >= $b['min_months'] && ($b['max_months'] === null || $months < $b['max_months'])) return $b;
        }

        return null;
    }

    /**
     * The whole verdict for a room: counts, required educators, the rule used, and any
     * group-size / home-composition / second-adult findings. $educators may be passed in to
     * evaluate a hypothetical (tests, forecasts).
     */
    public static function evaluate(object $room, ?Carbon $at = null, ?array $childIds = null, ?array $educators = null): array
    {
        $centre = DB::table('centres')->where('id', $room->centre_id)->first(['id', 'agency_id', 'license_type', 'email']);
        $agencyId = (int) ($centre->agency_id ?? 0);
        $profile = Jurisdictions::forAgency($agencyId);
        $ids = $childIds ?? self::childIdsPresent($room, $at);
        $n = count($ids);
        [$have, $source] = $educators ?? self::educatorsPresent($room, $at);
        $when = $at ?: now();

        $out = [
            'children_present' => $n, 'educators_present' => (int) $have, 'educator_source' => $source,
            'jurisdiction' => $profile['code'], 'rule' => 'room', 'reason' => null,
            'group_limit' => null, 'over_group' => false, 'group_note' => null,
            'home' => null, 'nap' => null, 'second_adult' => null, 'warnings' => [],
        ];
        $roomRatio = max(1, (int) $room->ratio_children);
        $out['required_educators'] = $n === 0 ? 0 : (int) ceil($n / $roomRatio);
        $out['ratio_target'] = "{$room->ratio_educators}:{$room->ratio_children}";
        if ($n === 0) return $out;

        // Ages of the children present.
        $ages = DB::table('children')->whereIn('id', $ids)->get(['id', 'date_of_birth', 'family_id'])
            ->map(fn ($c) => ['id' => (int) $c->id, 'family_id' => (int) $c->family_id,
                'months' => $c->date_of_birth ? (int) Carbon::parse($c->date_of_birth)->diffInMonths($when) : null])->all();
        $known = array_values(array_filter($ages, fn ($a) => $a['months'] !== null));

        $home = $profile['ratios']['home'] ?? null;
        $lt = (string) ($centre->license_type ?? '');
        $isHome = is_array($home) && preg_match('/type_a|type_b|_home$/', $lt);

        if ($isHome) {
            $under2 = count(array_filter($known, fn ($a) => $a['months'] < 24));
            $per = max(1, (int) $home['per_staff']);
            $perU2 = max(1, (int) $home['max_under_2_per_staff']);
            $req = max((int) ceil($n / $per), (int) ceil($under2 / $perU2));
            $cap = str_contains($lt, 'type_b') ? ($home['type_b_max'] ?? null) : (str_contains($lt, 'type_a') ? ($home['type_a_max'] ?? null) : null);
            $out['rule'] = 'jurisdiction_home';
            $out['required_educators'] = $req;
            $out['ratio_target'] = '1:' . $per . ' (max ' . $perU2 . ' under 2)';
            $out['reason'] = $profile['label'] . ' home rule: ' . $per . ' children per caregiver, no more than ' . $perU2 . ' under age 2'
                . ($under2 ? ' (' . $under2 . ' under 2 here)' : '');
            $out['home'] = ['under_2' => $under2, 'cap' => $cap, 'over_cap' => $cap !== null && $n > $cap,
                'own_children_note' => ! empty($home['counts_own_under']) ? 'Your own children under ' . $home['counts_own_under'] . ' count toward these limits — sign them in too.' : null];
            if ($cap !== null && $n > $cap) $out['warnings'][] = $n . ' children — over this home\'s limit of ' . $cap;
            if (count($known) < $n) $out['warnings'][] = (count($ages) - count($known)) . ' child(ren) have no date of birth — the under-2 limit cannot be checked for them';

            return $out;
        }

        $bands = self::bands($profile);
        if ($bands) {
            $youngest = null;
            foreach ($known as $a) if ($youngest === null || $a['months'] < $youngest['months']) $youngest = $a;
            $band = $youngest ? self::bandFor($bands, $youngest['months']) : null;
            if ($band) {
                $ratio = max(1, (int) $band['ratio']);
                $out['rule'] = 'jurisdiction';
                $out['required_educators'] = (int) ceil($n / $ratio);
                $out['ratio_target'] = '1:' . $ratio;
                $out['reason'] = 'Youngest child: ' . $band['label'] . ' → 1:' . $ratio . ' (' . $profile['label'] . ')';
                if (! empty($band['group'])) {
                    $out['group_limit'] = (int) $band['group'];
                    $out['over_group'] = $n > (int) $band['group'];
                    $out['group_note'] = 'Group size limit ' . (int) $band['group'];
                    if ($out['over_group']) $out['warnings'][] = $n . ' children — over the group size limit of ' . (int) $band['group'];
                }
                $nap = $profile['ratios']['nap_relief'] ?? null;
                if (is_array($nap)) {
                    $infants = count(array_filter($known, fn ($a) => $a['months'] < 18));
                    $out['nap'] = ($nap['no_infants'] ?? false) && $infants
                        ? ['allowed' => false, 'note' => 'Nap-time relief does not apply: infants present']
                        : ['allowed' => true, 'required' => (int) ceil($n / ($ratio * max(1, (int) ($nap['factor'] ?? 2)))),
                           'note' => 'During nap time (up to ' . ($nap['max_hours'] ?? 2) . ' h) ' . (int) ceil($n / ($ratio * max(1, (int) ($nap['factor'] ?? 2)))) . ' educator(s) may supervise'];
                }
                $second = $profile['ratios']['second_adult_at'] ?? null;
                if ($second && $n >= (int) $second) {
                    $adults = count(array_unique(self::openPunchUserIds($room, $at)));
                    $out['second_adult'] = ['required' => true, 'adults_on_site' => $adults, 'met' => $adults >= 2];
                    if ($adults < 2) $out['warnings'][] = $n . ' children present — a second adult must be on site (' . $adults . ' clocked in)';
                }
                if (count($known) < $n) $out['warnings'][] = ($n - count($known)) . ' child(ren) have no date of birth — the youngest-child rule may be wrong';
            }
        }

        return $out;
    }
}
