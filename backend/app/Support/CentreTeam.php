<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * WHO WORKS AT THIS HOME — every educator assigned to a centre, whether or not they
 * have clocked in yet (2026-10-21).
 *
 * Anthony: "if two educators are assigned to the same centre, does this show up at the
 * agency overview and daily overview accordingly? Bruni's centre has her as a provider
 * and one other". It did not: both screens built their staff list from the clock tables,
 * so Rachel Blane (assigned to Bruni's home since 1 September, never clocked in) was
 * invisible on both, and nothing said a second person belonged there at all.
 *
 * AGENCY STAFF ARE LEFT OUT. The agency's own office account "safia-educator"
 * (info@ilearnhcc.com) holds educator assignments at seven provider homes so it can
 * reach them; listing it would put "Safia Ali · staff" on seven cards. Anthony chose to
 * leave those assignments alone and skip anyone who — on this account or another account
 * with the same email — holds an agency-wide role. Such a person still appears the moment
 * they actually clock in at a home, because the screens add clock-ins on top of this list.
 *
 * Closed records never show: active assignment, active and undeleted user.
 */
final class CentreTeam
{
    /** Roles that make a person agency staff rather than part of one home's team. */
    private const AGENCY_WIDE = ['platform_admin', 'agency_admin', 'home_visitor'];

    /**
     * @param  int[]        $centreIds
     * @param  string|null  $localDate  Y-m-d in the agency zone; fills each member's rostered
     *                                  shift that day (shifts store local wall time, as the
     *                                  overview's provider row already assumes)
     * @return array<int, array<int, array{user_id:int, name:string, photo_url:?string, sex:?string,
     *               is_provider:bool, scheduled:bool, shift_end:?string}>>  centre id → user id → member
     */
    public static function forCentres(array $centreIds, ?string $localDate = null): array
    {
        $centreIds = array_values(array_unique(array_map('intval', $centreIds)));
        if (! $centreIds) {
            return [];
        }

        $rows = DB::table('role_assignments as ra')
            ->join('users as u', 'u.id', '=', 'ra.user_id')
            ->join('centres as c', 'c.id', '=', 'ra.centre_id')
            ->whereIn('ra.centre_id', $centreIds)
            ->where('ra.role', 'educator')->where('ra.active', true)
            ->where('u.status', 'active')->whereNull('u.deleted_at')
            ->orderBy('u.first_name')->orderBy('u.last_name')
            ->get(['ra.centre_id', 'u.id', 'u.first_name', 'u.last_name', 'u.preferred_name', 'u.photo_url', 'u.sex',
                'u.email', 'u.phone', 'c.email as centre_email']);
        if ($rows->isEmpty()) {
            return [];
        }

        $agencyPeople = self::agencyWidePeople($rows);

        $shiftEnd = [];
        if ($localDate) {
            foreach (DB::table('shifts as s')->join('rooms as r', 'r.id', '=', 's.room_id')
                ->whereIn('r.centre_id', $centreIds)
                ->whereIn('s.user_id', $rows->pluck('id')->unique()->all())
                ->whereIn('s.status', ['scheduled', 'active'])
                ->whereRaw('DATE(s.starts_at) = ?', [$localDate])
                ->groupBy('r.centre_id', 's.user_id')
                ->selectRaw('r.centre_id, s.user_id, MAX(s.ends_at) as ends_at')->get() as $s) {
                $shiftEnd[(int) $s->centre_id][(int) $s->user_id] = (string) $s->ends_at;
            }
        }

        $team = [];
        foreach ($rows as $r) {
            $uid = (int) $r->id;
            $cid = (int) $r->centre_id;
            if (isset($agencyPeople[$uid]) || isset($team[$cid][$uid])) {
                continue;
            }
            $email = strtolower(trim((string) $r->email));
            $team[$cid][$uid] = [
                'user_id' => $uid,
                'name' => trim($r->first_name . ' ' . ($r->last_name ?? '')) ?: 'Staff',
                'first_name' => $r->first_name,
                'last_name' => $r->last_name,
                'preferred_name' => $r->preferred_name,
                'photo_url' => $r->photo_url,
                'sex' => $r->sex,
                'email' => $r->email,
                'phone' => $r->phone,
                // The provider is the person whose email is the centre's — the match the
                // overview card and the Daily Overview already use for name and photo.
                'is_provider' => $email !== '' && $email === strtolower(trim((string) $r->centre_email)),
                'scheduled' => isset($shiftEnd[$cid][$uid]),
                'shift_end' => $shiftEnd[$cid][$uid] ?? null,
            ];
        }

        // Provider first, then everyone else by name (rows arrive name-ordered).
        foreach ($team as &$members) {
            uasort($members, fn ($a, $b) => (int) $b['is_provider'] <=> (int) $a['is_provider']);
        }
        unset($members);

        return $team;
    }

    /**
     * The educators of each ROOM, as parents should see them (2026-10-21).
     *
     * educator_rooms alone is not that list: the agency's office account (Safia Ali) and
     * both of Lloydene King's home-visitor accounts sit in every iLearn home's room so
     * they can reach it, so the parent app's "Your team" card at Bruni Meeser's read
     * "Bruni, Lloydene King, Lloydene King, Rachel Blane, Safia Ali". This is the room's
     * people who are on its home's team; when nobody on the team holds a row for the
     * room, the whole team (a home has one room, and a provider is still its educator).
     *
     * @param  int[]  $roomIds
     * @return array<int, array<int, array>>  room id → user id → member (provider first)
     */
    public static function forRooms(array $roomIds): array
    {
        $roomIds = array_values(array_unique(array_filter(array_map('intval', $roomIds))));
        if (! $roomIds) {
            return [];
        }
        $centreOf = DB::table('rooms')->whereIn('id', $roomIds)->pluck('centre_id', 'id')->map(fn ($v) => (int) $v)->all();
        $teams = self::forCentres(array_values($centreOf));
        $inRoom = [];
        foreach (DB::table('educator_rooms')->whereIn('room_id', $roomIds)->get(['room_id', 'user_id']) as $er) {
            $inRoom[(int) $er->room_id][(int) $er->user_id] = true;
        }
        $out = [];
        foreach ($roomIds as $rid) {
            $team = $teams[$centreOf[$rid] ?? 0] ?? [];
            $mine = array_filter($team, fn ($m) => isset($inRoom[$rid][$m['user_id']]));
            $out[$rid] = $mine ?: $team;
        }

        return $out;
    }

    /** User ids (from $rows) whose account, or another account with the same email, holds an agency-wide role. */
    private static function agencyWidePeople($rows): array
    {
        $ids = $rows->pluck('id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        $emails = $rows->pluck('email')->map(fn ($e) => strtolower(trim((string) $e)))->filter()->unique()->values()->all();

        $wide = DB::table('role_assignments as ra')
            ->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.active', true)->whereNull('u.deleted_at')
            ->where(function ($q) {
                $q->whereIn('ra.role', self::AGENCY_WIDE)
                  ->orWhere(fn ($q2) => $q2->where('ra.role', 'centre_director')->whereNull('ra.centre_id'));
            })
            ->where(function ($q) use ($ids, $emails) {
                $q->whereIn('u.id', $ids ?: [0]);
                if ($emails) {
                    $q->orWhereIn(DB::raw('LOWER(u.email)'), $emails);
                }
            })
            ->get(['u.id', 'u.email']);

        $wideIds = $wide->pluck('id')->map(fn ($v) => (int) $v)->flip()->all();
        $wideEmails = $wide->pluck('email')->map(fn ($e) => strtolower(trim((string) $e)))->filter()->flip()->all();

        $out = [];
        foreach ($rows as $r) {
            $e = strtolower(trim((string) $r->email));
            if (isset($wideIds[(int) $r->id]) || ($e !== '' && isset($wideEmails[$e]))) {
                $out[(int) $r->id] = true;
            }
        }

        return $out;
    }
}
