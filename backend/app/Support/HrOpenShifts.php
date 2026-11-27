<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KIDDIETRAC HR PHASE 4, BATCH 3 (2026-11-27): THE OPEN-SHIFT BOARD.
 *
 * A director or admin posts a shift nobody is on yet (hr_open_shifts: centre, room, date, hours, role, optional
 * certificate). Staff of that centre who CAN work it see it in My HR and ask for it (hr_open_shift_claims); a director
 * of that centre (or an admin) confirms one claim, which writes the real shift on the rota. Nobody confirms their own.
 *
 * ONE RULE for "may this person take it?" (refusal()), asked when the board is drawn, when someone claims and again at
 * confirmation (things change in between):
 *   1. one of the centre's staff (HrLeave::staffIds - never a super admin or a deactivated account);
 *   2. free: HrAvailability::blocks (approved leave, a can't-work date, outside their weekly availability);
 *   3. not already on a shift that overlaps it, at any centre of the agency;
 *   4. holds the certificate the shift asks for, active and not expired on the day (staff_certifications);
 *   5. the shift has not started.
 * RATIO-AWARE: the board marks each open shift that covers a ratio shortfall in its room (HrAvailability::ratioGaps,
 * the same check as HR > Rota), and lists the week's shortfalls so a director can post a shift straight from one.
 * Times are agency wall-clock times, like shifts.
 */
final class HrOpenShifts
{
    public const ROLES = ['lead' => 'Lead', 'support' => 'Support', 'floater' => 'Floater'];

    private static ?bool $ready = null;

    public static function ready(): bool
    {
        return self::$ready ??= Schema::hasTable('hr_open_shifts');
    }

    public static function certLabel(?string $t): string
    {
        $map = ['RECE' => 'RECE', 'ECE_Assistant' => 'ECE Assistant', 'First_Aid' => 'First Aid', 'CPR' => 'CPR', 'VSC' => 'Vulnerable Sector Check',
            'Vulnerable_Sector_Check' => 'Vulnerable Sector Check', 'WHMIS' => 'WHMIS', 'Food_Handler' => 'Food Handler'];

        return $t ? ($map[$t] ?? str_replace('_', ' ', $t)) : '';
    }

    /** The certificate types this agency's staff hold (for the "must hold" picker). [type => label] */
    public static function certChoices(int $agencyId): array
    {
        $out = ['First_Aid' => 'First Aid', 'CPR' => 'CPR', 'RECE' => 'RECE', 'VSC' => 'Vulnerable Sector Check'];
        foreach (DB::table('staff_certifications')->where('agency_id', $agencyId)->where('active', 1)->distinct()->pluck('cert_type') as $t) {
            if ($t && $t !== 'custom' && $t !== 'Other') $out[(string) $t] = self::certLabel((string) $t);
        }
        asort($out);

        return $out;
    }

    public static function startsAt(object $o): string
    {
        return substr((string) $o->shift_date, 0, 10) . ' ' . substr((string) $o->start_time, 0, 5) . ':00';
    }

    public static function endsAt(object $o): string
    {
        return substr((string) $o->shift_date, 0, 10) . ' ' . substr((string) $o->end_time, 0, 5) . ':00';
    }

    /** Why this person cannot take this open shift, or null when they can. */
    public static function refusal(int $agencyId, object $o, int $userId, ?array $staff = null): ?string
    {
        $staff = $staff ?? HrLeave::staffIds($agencyId, [(int) $o->centre_id]);
        if (! in_array($userId, $staff, true)) return 'Not on this centre\'s staff';
        $date = substr((string) $o->shift_date, 0, 10);
        $start = substr((string) $o->start_time, 0, 5);
        $end = substr((string) $o->end_time, 0, 5);
        if (self::startsAt($o) <= Carbon::now(AgencyTime::tzForCentre((int) $o->centre_id))->format('Y-m-d H:i:s')) return 'This shift has already started';
        if ($why = HrAvailability::blocks($agencyId, $userId, $date, $start, $end)) return $why;
        $clash = DB::table('shifts as s')->join('rooms as r', 'r.id', '=', 's.room_id')->join('centres as c', 'c.id', '=', 'r.centre_id')
            ->where('c.agency_id', $agencyId)->where('s.user_id', $userId)->where('s.status', '!=', 'cancelled')
            ->where('s.starts_at', '<', self::endsAt($o))->where('s.ends_at', '>', self::startsAt($o))
            ->first(['s.starts_at', 's.ends_at', 'r.name']);
        if ($clash) {
            return 'Already on a shift then (' . $clash->name . ', ' . Carbon::parse($clash->starts_at)->format('g:i A') . ' – ' . Carbon::parse($clash->ends_at)->format('g:i A') . ')';
        }
        if ($o->cert_type) {
            $ok = DB::table('staff_certifications')->where('user_id', $userId)->where('cert_type', $o->cert_type)->where('active', 1)
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', $date))->exists();
            if (! $ok) return 'Needs a current ' . self::certLabel($o->cert_type) . ' certificate';
        }

        return null;
    }

    /** The people at the centre who may take it: [user_id => true]. */
    public static function eligible(int $agencyId, object $o, ?int $except = null): array
    {
        $staff = HrLeave::staffIds($agencyId, [(int) $o->centre_id]);
        $out = [];
        foreach ($staff as $uid) {
            if ($except && $uid === $except) continue;
            if (self::refusal($agencyId, $o, $uid, $staff) === null) $out[$uid] = true;
        }

        return $out;
    }

    /**
     * The ratio shortfalls of a centre's week (Monday start), with the open shifts NOT counted (they are not on the
     * rota yet): [{date, room_id, room, from, to, planned, required, children}].
     */
    public static function gaps(object $centre, Carbon $weekStart): array
    {
        $rooms = DB::table('rooms')->where('centre_id', $centre->id)->orderBy('name')->get()->all();
        if (! $rooms) return [];
        $end = $weekStart->copy()->addDays(6);
        $byRoom = [];
        foreach (DB::table('shifts as s')->join('rooms as r', 'r.id', '=', 's.room_id')->where('r.centre_id', $centre->id)->where('s.status', '!=', 'cancelled')
            ->whereBetween('s.starts_at', [$weekStart->toDateString() . ' 00:00:00', $end->toDateString() . ' 23:59:59'])->get(['s.*']) as $s) {
            $byRoom[(int) $s->room_id][] = $s;
        }

        return HrAvailability::ratioGaps($centre, $rooms, $byRoom, $weekStart);
    }

    /** The shortfall an open shift covers (same room and day, overlapping hours), or null. */
    public static function covers(object $o, array $gaps): ?array
    {
        $date = substr((string) $o->shift_date, 0, 10);
        $s = substr((string) $o->start_time, 0, 5);
        $e = substr((string) $o->end_time, 0, 5);
        foreach ($gaps as $g) {
            if ($g['date'] === $date && (int) $g['room_id'] === (int) $o->room_id && $s < $g['to'] && $g['from'] < $e) return $g;
        }

        return null;
    }

    public static function hours(object $o): float
    {
        return HrAvailability::hours(self::startsAt($o), self::endsAt($o));
    }

    /** A plain line: "Tue, Dec 2, 7:00 AM – 3:00 PM". */
    public static function when(object $o): string
    {
        return Carbon::parse(self::startsAt($o))->format('D, M j, g:i A') . ' – ' . Carbon::parse(self::endsAt($o))->format('g:i A');
    }

    public static function shape(object $o, array $rooms, array $centres): array
    {
        return ['id' => (int) $o->id, 'centre_id' => (int) $o->centre_id, 'centre' => $centres[(int) $o->centre_id] ?? '', 'room_id' => (int) $o->room_id,
            'room' => $rooms[(int) $o->room_id] ?? '', 'date' => substr((string) $o->shift_date, 0, 10), 'start' => substr((string) $o->start_time, 0, 5),
            'end' => substr((string) $o->end_time, 0, 5), 'hours' => self::hours($o), 'role' => $o->role, 'cert_type' => $o->cert_type,
            'cert' => self::certLabel($o->cert_type), 'note' => $o->note, 'status' => $o->status, 'shift_id' => $o->shift_id ? (int) $o->shift_id : null,
            'filled_user_id' => $o->filled_user_id ? (int) $o->filled_user_id : null, 'created_at' => (string) $o->created_at];
    }

    /** Bell rows (one per person) through Notify, type "shift" (StaffNotifyPrefs "My shifts"). */
    public static function bell(int $agencyId, array $userIds, string $title, string $body, string $link = '#my-hr'): void
    {
        $rows = [];
        foreach (array_unique(array_filter(array_map('intval', $userIds))) as $u) {
            $rows[] = ['user_id' => $u, 'agency_id' => $agencyId, 'type' => 'shift', 'title' => $title, 'body' => $body,
                'data' => json_encode(['link' => $link, 'kind' => 'own']), 'created_at' => now()];
        }
        try {
            if ($rows) Notify::write($rows);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Bell rows for the centre's managers (a claim to confirm), type "hr" kind team (topic "Team HR reminders"). */
    public static function bellManagers(int $agencyId, int $centreId, array $except, string $title, string $body, string $link): void
    {
        $ids = CentreScope::managers($agencyId, [$centreId])->pluck('u.id')->map(fn ($v) => (int) $v)->unique()->all();
        $rows = [];
        foreach (array_diff($ids, $except) as $u) {
            $rows[] = ['user_id' => $u, 'agency_id' => $agencyId, 'type' => 'hr', 'title' => $title, 'body' => $body,
                'data' => json_encode(['link' => $link, 'kind' => 'team']), 'created_at' => now()];
        }
        try {
            if ($rows) Notify::write($rows);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
