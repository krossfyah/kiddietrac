<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The sales team's availability (2026-10-23): who is on the team, their weekly hours, and
 * whether ANYONE is free for a given time - which is what the website booking wizard now
 * offers. A person is free when the time sits inside their hours, they have no time off,
 * blocked time or meeting then, and they host no confirmed booking then.
 *
 * Until anyone on the team has set hours, the wizard keeps its old fixed weekday hours
 * (configured() is false) - so switching this on is a matter of setting hours.
 */
class SalesCalendar
{
    private const COLORS = ['#0F766E', '#7C3AED', '#C2410C', '#2563EB', '#BE185D', '#4D7C0F', '#B45309', '#0369A1'];

    /** Everyone on the sales team (platform admins + sales reps), with colour and weekly hours. */
    public static function staff(): array
    {
        $rows = DB::table('role_assignments as r')->join('users as u', 'u.id', '=', 'r.user_id')
            ->whereIn('r.role', ['platform_admin', 'sales_rep'])->where('r.active', true)->whereNull('u.deleted_at')
            ->select('u.id', 'u.first_name', 'u.last_name', 'u.photo_url')->distinct()->orderBy('u.id')->get();
        $hours = DB::table('sales_availability')->whereIn('user_id', $rows->pluck('id')->all() ?: [0])->orderBy('weekday')->orderBy('start_time')->get()->groupBy('user_id');
        return $rows->values()->map(fn ($u, $i) => [
            'id' => (int) $u->id, 'name' => trim($u->first_name . ' ' . $u->last_name), 'first_name' => $u->first_name, 'photo_url' => $u->photo_url,
            'color' => self::COLORS[$i % count(self::COLORS)],
            'hours' => ($hours[$u->id] ?? collect())->map(fn ($h) => ['weekday' => (int) $h->weekday, 'start' => $h->start_time, 'end' => $h->end_time])->values()->all(),
        ])->all();
    }

    private static ?bool $configured = null;
    private static array $avail = [];
    private static array $day = [];

    public static function configured(): bool
    {
        return self::$configured ??= DB::table('sales_availability')->exists();
    }

    /** Is at least one person on the team free for [start, end)? (Carbon in any tz) */
    public static function someoneFree(Carbon $start, Carbon $end, ?int $ignoreBooking = null): bool
    {
        if (! self::configured()) return true;
        $tz = BookingMail::TZ;
        $s = $start->copy()->tz($tz); $e = $end->copy()->tz($tz);
        if ($e->toDateString() !== $s->toDateString()) return false;
        $from = $s->format('H:i'); $to = $e->format('H:i');
        if (! self::$avail) {
            foreach (DB::table('sales_availability')->get() as $h) self::$avail[(int) $h->weekday][] = [(int) $h->user_id, $h->start_time, $h->end_time];
            self::$avail['_'] = true;
        }
        // Everything that makes someone busy that day, loaded once per day (the wizard asks ~8 times a day).
        $date = $s->toDateString();
        if (! isset(self::$day[$date])) {
            $d0 = Carbon::parse($date, $tz)->startOfDay()->utc(); $d1 = Carbon::parse($date, $tz)->endOfDay()->utc();
            $busy = [];
            foreach (DB::table('sales_calendar_events')->where('starts_at', '<', $d1)->where('ends_at', '>', $d0)
                ->where(fn ($q) => $q->whereIn('kind', ['meeting', 'block', 'time_off'])->orWhere(fn ($w) => $w->where('kind', 'where')->where('where', 'out')))
                ->get(['user_id', 'starts_at', 'ends_at']) as $r) $busy[(int) $r->user_id][] = [Carbon::parse($r->starts_at, 'UTC')->timestamp, Carbon::parse($r->ends_at, 'UTC')->timestamp, 0];
            foreach (DB::table('marketing_bookings')->where('status', 'booked')->whereNotNull('host_user_id')->where('starts_at', '<', $d1)->where('ends_at', '>', $d0)
                ->get(['id', 'host_user_id', 'starts_at', 'ends_at']) as $r) $busy[(int) $r->host_user_id][] = [Carbon::parse($r->starts_at, 'UTC')->timestamp, Carbon::parse($r->ends_at, 'UTC')->timestamp, (int) $r->id];
            self::$day[$date] = $busy;
        }
        $a = $start->copy()->utc()->timestamp; $b = $end->copy()->utc()->timestamp;
        foreach (self::$avail[(int) $s->dayOfWeek] ?? [] as [$uid, $hs, $he]) {
            if ($hs > $from || $he < $to) continue;
            $clash = false;
            foreach (self::$day[$date][$uid] ?? [] as [$bs, $be, $bid]) {
                if ($ignoreBooking && $bid === $ignoreBooking) continue;
                if ($a < $be && $b > $bs) { $clash = true; break; }
            }
            if (! $clash) return true;
        }
        return false;
    }
}
