<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\AgencyMailer;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SUBSTITUTES: THE POOL AND CLAIMING COVER (2026-11-28, Anthony: "fix all of the gaps").
 *
 * WHO A SUBSTITUTE IS: there is no substitute role. A substitute is one of the agency's own staff (an educator, home
 * visitor or director with a live role - Live::staffIds) whom an admin or director has put in the agency's pool
 * (substitute_pool), with the centres they cover (empty = every centre), how to reach them (app / email) and
 * active / inactive. Leavers drop out by themselves: every read asks Live::staffIds, which already excludes closed
 * accounts, inactive roles and HR files marked terminated.
 *
 * A COVER REQUEST (substitute_requests) moves:
 *   open --claim--> filled                    (the agency's default: first come, first served)
 *   open --claim--> claimed --confirm--> filled   (agencies.settings.substitutes.require_confirm)
 *   claimed|filled --release (manager) / withdraw (the substitute, before it starts)--> open
 *   open|claimed|filled --cancel (manager)--> cancelled
 * Claims are first come: the row is locked (lockForUpdate) and must still be open.
 *
 * THE ROTA: when the agency has the HR add-on and the request names a room, "filled" writes the real shift
 * (shifts: the substitute, the room, the wall-clock times, role floater) exactly as the HR open-shift board's confirm
 * does, and release / cancel cancels that shift. The HR open-shift board (hr_open_shifts) is NOT reused: its one rule
 * is "staff of that centre", and a substitute usually is not; its claims also always need a manager.
 */
final class SubstituteCover
{
    public const STATUS = ['open' => 'Open', 'claimed' => 'Claimed - to confirm', 'filled' => 'Filled', 'cancelled' => 'Cancelled', 'expired' => 'Expired'];

    public static function settings(int $agencyId): array
    {
        $all = json_decode((string) DB::table('agencies')->where('id', $agencyId)->value('settings'), true);
        $raw = is_array($all['substitutes'] ?? null) ? $all['substitutes'] : [];

        return ['require_confirm' => (bool) ($raw['require_confirm'] ?? false)];
    }

    public static function saveSettings(int $agencyId, array $in): array
    {
        DB::transaction(function () use ($agencyId, $in) {
            $row = DB::table('agencies')->where('id', $agencyId)->lockForUpdate()->first(['settings']);
            $all = json_decode((string) ($row->settings ?? ''), true);
            $all = is_array($all) ? $all : [];
            $all['substitutes'] = ['require_confirm' => (bool) ($in['require_confirm'] ?? false)];
            DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        });

        return self::settings($agencyId);
    }

    /** The centres a pool row covers (empty = every centre). @return int[] */
    public static function centresOf($json): array
    {
        return array_values(array_unique(array_map('intval', array_filter((array) (json_decode((string) $json, true) ?: [])))));
    }

    public static function covers($json, int $centreId): bool
    {
        $c = self::centresOf($json);

        return ! $c || in_array($centreId, $c, true);
    }

    /** This person's active, live pool row in the agency, or null. */
    public static function member(int $agencyId, int $userId): ?object
    {
        if (! in_array($userId, Live::staffIds($agencyId), true)) return null;

        return DB::table('substitute_pool')->where('agency_id', $agencyId)->where('user_id', $userId)->where('active', 1)->first();
    }

    public static function startsAt(object $r): string
    {
        return substr((string) $r->shift_date, 0, 10) . ' ' . substr((string) $r->shift_start, 0, 5) . ':00';
    }

    public static function endsAt(object $r): string
    {
        return substr((string) $r->shift_date, 0, 10) . ' ' . substr((string) $r->shift_end, 0, 5) . ':00';
    }

    /** Has it started (centre's wall clock)? */
    public static function started(object $r): bool
    {
        return self::startsAt($r) <= Carbon::now(AgencyTime::tzForCentre((int) $r->centre_id))->format('Y-m-d H:i:s');
    }

    /** "Tue, Dec 2, 7:00 AM - 3:00 PM" (wall clock, never converted). */
    public static function when(object $r): string
    {
        return Carbon::parse(self::startsAt($r))->format('D, M j, g:i A') . ' - ' . Carbon::parse(self::endsAt($r))->format('g:i A');
    }

    /** Why this person cannot take this request, or null. Asked at claim and again at confirm. */
    public static function refusal(int $agencyId, object $r, int $userId): ?string
    {
        $m = self::member($agencyId, $userId);
        if (! $m) return 'You are not on this agency\'s substitute list';
        if (! self::covers($m->centres, (int) $r->centre_id)) return 'You do not cover this centre';
        if (self::started($r)) return 'This shift has already started';
        if (Features::on($agencyId, 'hr')) {
            try {
                if ($why = HrAvailability::blocks($agencyId, $userId, substr((string) $r->shift_date, 0, 10), substr((string) $r->shift_start, 0, 5), substr((string) $r->shift_end, 0, 5))) return $why;
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $clash = DB::table('shifts as s')->join('rooms as rm', 'rm.id', '=', 's.room_id')->join('centres as c', 'c.id', '=', 'rm.centre_id')
            ->where('c.agency_id', $agencyId)->where('s.user_id', $userId)->where('s.status', '!=', 'cancelled')
            ->where('s.starts_at', '<', self::endsAt($r))->where('s.ends_at', '>', self::startsAt($r))->exists();
        if ($clash) return 'You are already on a shift at that time';
        $other = DB::table('substitute_requests')->where('agency_id', $agencyId)->where('id', '!=', $r->id)->where('assigned_user_id', $userId)
            ->whereIn('status', ['claimed', 'filled'])->where('shift_date', substr((string) $r->shift_date, 0, 10))
            ->where('shift_start', '<', $r->shift_end)->where('shift_end', '>', $r->shift_start)->exists();
        if ($other) return 'You have already taken cover at that time';

        return null;
    }

    /** Write the rota shift for a filled request when the agency has HR and the request names a room. */
    public static function writeShift(int $agencyId, object $r, int $userId, int $actorId): ?int
    {
        if (! $r->room_id || ! Features::on($agencyId, 'hr')) return null;
        $room = DB::table('rooms as rm')->join('centres as c', 'c.id', '=', 'rm.centre_id')->where('rm.id', (int) $r->room_id)
            ->where('c.agency_id', $agencyId)->where('c.id', (int) $r->centre_id)->exists();
        if (! $room) return null;
        $id = (int) DB::table('shifts')->insertGetId(['user_id' => $userId, 'room_id' => (int) $r->room_id, 'starts_at' => self::startsAt($r),
            'ends_at' => self::endsAt($r), 'role' => 'floater', 'status' => 'scheduled', 'created_at' => now()]);
        Audit::write(['user_id' => $actorId, 'agency_id' => $agencyId, 'action' => 'shift.created', 'entity_type' => 'shift', 'entity_id' => $id,
            'payload' => json_encode(['source' => 'substitute cover #' . $r->id, 'user_id' => $userId])]);

        return $id;
    }

    public static function cancelShift(?int $shiftId): void
    {
        if ($shiftId) DB::table('shifts')->where('id', $shiftId)->where('status', 'scheduled')->update(['status' => 'cancelled']);
    }

    /** Bell rows (Notify pushes them) - type sub_request, a link straight to the screen that can act on it. */
    public static function bell(int $agencyId, array $userIds, string $title, string $body, string $link): void
    {
        $rows = [];
        foreach (array_unique(array_filter(array_map('intval', $userIds))) as $u) {
            $rows[] = ['user_id' => $u, 'agency_id' => $agencyId, 'type' => 'sub_request', 'title' => $title, 'body' => $body,
                'data' => json_encode(['link' => $link]), 'created_at' => now()];
        }
        try {
            if ($rows) Notify::write($rows);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** The centre's managers (agency admins + that centre's directors), minus some people. @return int[] */
    public static function managers(int $agencyId, int $centreId, array $except = []): array
    {
        $ids = CentreScope::managers($agencyId, [$centreId])->pluck('u.id')->map(fn ($v) => (int) $v)->unique()->all();

        return array_values(array_diff($ids, array_map('intval', $except)));
    }

    /**
     * Tell the pool members who cover this centre about a new request, each the way they chose (app and/or email).
     * The poster is never told about their own request. @return int how many people were told
     */
    public static function announce(int $agencyId, object $r, int $posterId): int
    {
        $live = array_flip(Live::staffIds($agencyId));
        $centre = (string) DB::table('centres')->where('id', (int) $r->centre_id)->value('name');
        $members = DB::table('substitute_pool as sp')->join('users as u', 'u.id', '=', 'sp.user_id')
            ->where('sp.agency_id', $agencyId)->where('sp.active', 1)->where('sp.user_id', '!=', $posterId)
            ->get(['sp.user_id', 'sp.centres', 'sp.notify_app', 'sp.notify_email', 'u.email', 'u.first_name'])
            ->filter(fn ($m) => isset($live[(int) $m->user_id]) && self::covers($m->centres, (int) $r->centre_id));
        $title = 'Cover needed: ' . Carbon::parse(self::startsAt($r))->format('D, M j');
        $body = trim(($centre ? $centre . ', ' : '') . self::when($r)) . '. Open it to take the shift.';
        $link = '#cover-requests?open=' . (int) $r->id;
        $told = 0;
        foreach ($members as $m) {
            $any = false;
            if ((int) $m->notify_app === 1) {
                self::bell($agencyId, [(int) $m->user_id], $title, $body, $link);
                $any = true;
            }
            if ((int) $m->notify_email === 1 && filter_var((string) $m->email, FILTER_VALIDATE_EMAIL)) {
                self::email($agencyId, (string) $m->email, (string) $m->first_name, $r, $centre);
                $any = true;
            }
            if ($any) $told++;
        }

        return $told;
    }

    private static function email(int $agencyId, string $to, string $first, object $r, string $centre): void
    {
        try {
            $m = SubstituteMail::request($agencyId, $first, $centre, self::when($r), (string) ($r->reason ?? ''), (int) $r->id);
            $svc = AgencyMailer::forAgency($agencyId);
            $from = $svc->fromAddress();
            $fn = $svc->fromName();
            $svc->html($m['html'], function ($msg) use ($to, $from, $fn, $m, $agencyId) {
                MailScope::agency($msg, $agencyId);
                $msg->to($to)->from($from, $fn)->subject($m['subject']);
            });
        } catch (\Throwable $e) {
            Log::warning('Substitute request email failed', ['request' => $r->id ?? null, 'error' => $e->getMessage()]);
        }
    }
}
