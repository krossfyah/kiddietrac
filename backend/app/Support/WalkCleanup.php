<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Walks nobody ended (2026-10-21).
 *
 * A walk is a record that children are off the property, and four of them had been left
 * "active" for days or weeks (the oldest since 10 August): the educator came back and
 * never pressed End. Anthony chose to close those quietly, and to have forgotten walks
 * closed automatically from now on.
 *
 * A walk still open once its own day has ended (in the AGENCY's day, AgencyTime) is
 * closed at the last GPS point it recorded (or the time it left, with no points), with
 * an audit row. QUIET: nothing goes to parents or other staff — telling a family hours
 * later that a walk "ended" would be false news. The educator who started it gets one
 * bell, unless $notify is false (the one-off backfill of old walks).
 */
final class WalkCleanup
{
    /** @return array<int, array{id:int, return_time:string, agency_id:?int}> */
    public static function closeForgotten(bool $notify = true, bool $dryRun = false, ?array $onlyIds = null): array
    {
        $rows = DB::table('field_trips as t')
            ->leftJoin('centres as c', 'c.id', '=', 't.centre_id')
            ->where('t.status', 'active')
            ->when($onlyIds, fn ($q) => $q->whereIn('t.id', $onlyIds))
            ->get(['t.id', 't.title', 't.trip_date', 't.depart_time', 't.staff_lead_id', 't.centre_id',
                   DB::raw('COALESCE(t.agency_id, c.agency_id) as agency_id')]);

        $closed = [];
        foreach ($rows as $t) {
            $agencyId = $t->agency_id ? (int) $t->agency_id : null;
            $tz = $t->centre_id ? AgencyTime::tzForCentre((int) $t->centre_id) : AgencyTime::tz($agencyId);
            $today = Carbon::now($tz)->toDateString();
            $tripDate = substr((string) $t->trip_date, 0, 10);
            // Still the walk's own day: it may genuinely be out. Not ours to close.
            if ($tripDate === '' || $tripDate >= $today) {
                continue;
            }

            // Closed where it was last seen: the last point, in the agency's clock.
            $last = DB::table('field_trip_pings')->where('field_trip_id', $t->id)->max('recorded_at');
            $return = (string) ($t->depart_time ?: '00:00:00');
            if ($last) {
                $local = Carbon::parse($last, 'UTC')->setTimezone($tz);
                if ($local->toDateString() === $tripDate && $local->format('H:i:s') > $return) {
                    $return = $local->format('H:i:s');
                } elseif ($local->toDateString() > $tripDate) {
                    $return = '23:59:00';       // tracking ran past midnight: end of its day
                }
            }

            if (! $dryRun) {
                DB::table('field_trips')->where('id', $t->id)->where('status', 'active')->update([
                    'status' => 'completed',
                    'return_time' => $return,
                    'updated_at' => now(),
                ]);
                try {
                    Audit::write([
                        'user_id' => null,
                        'agency_id' => $agencyId,
                        'action' => 'walk.auto_closed',
                        'entity_type' => 'field_trip',
                        'entity_id' => (int) $t->id,
                        'payload' => json_encode([
                            'summary' => 'Walk "' . ($t->title ?: 'Walk') . '" on ' . $tripDate
                                . ' was never ended, so the system closed it at ' . substr($return, 0, 5)
                                . ($last ? ' (its last recorded point).' : ' (no points were recorded).')
                                . ' Nobody was notified' . ($notify ? ' except the educator who started it.' : '.'),
                            'trip_date' => $tripDate, 'return_time' => $return, 'by' => 'system cleanup',
                        ]),
                        'created_at' => now(),
                    ]);
                } catch (\Throwable $e) {
                    report($e);
                }
                if ($notify && $t->staff_lead_id) {
                    Notify::write([
                        'agency_id' => $agencyId,
                        'user_id' => (int) $t->staff_lead_id,
                        'type' => 'walk_auto_closed',
                        'title' => 'Your walk was closed for you',
                        'body' => mb_substr('"' . ($t->title ?: 'Walk') . '" on ' . Carbon::parse($tripDate)->format('M j')
                            . ' was still open, so it was closed at ' . Carbon::parse($return)->format('g:i A')
                            . '. Please press End when you are back next time.', 0, 240),
                        'data' => json_encode(['link' => '#walk', 'trip_id' => (int) $t->id]),
                    ]);
                }
            }
            $closed[] = ['id' => (int) $t->id, 'return_time' => $return, 'agency_id' => $agencyId];
        }

        return $closed;
    }
}
