<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\AutoSignOffController;
use App\Support\AgencyTime;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Close shifts and days that somebody forgot to close.
 *
 * The point of this is REPORTING, not tidiness. A punch left open does not read as "we
 * don't know when they left" — it reads as a shift still running, so ratio counts a
 * person who went home hours ago and payroll sees an ever-growing day. A child left
 * checked in is worse: attendance and ratio both say they are present.
 *
 * The critical decision here is WHEN the closure is stamped. Closing at "now" would
 * invent hours — a punch opened Tuesday and closed by this job on Thursday would record
 * a 48-hour shift, which is a worse number than the missing one it replaced. Everything
 * is closed at the configured sign-off time on the day it STARTED.
 *
 * Every auto-closure is marked as such, so a report can tell a real clock-out from one
 * this job wrote. That distinction is the difference between a record and a guess.
 */
class AutoSignOffCommand extends Command
{
    protected $signature = 'kiddietrac:auto-signoff
        {--dry-run : Show what would be closed without changing anything}
        {--agency= : Restrict to one agency id}';

    protected $description = 'Close staff punches and child check-ins left open past the agency’s sign-off time';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $agencies = DB::table('agencies')
            ->when($this->option('agency'), fn ($q) => $q->where('id', (int) $this->option('agency')))
            ->get(['id', 'name']);

        $staffClosed = 0;
        $childClosed = 0;

        foreach ($agencies as $agency) {
            $cfg = AutoSignOffController::read((int) $agency->id);
            if (! $cfg['staff_enabled'] && ! $cfg['children_enabled']) {
                continue;
            }

            $tz = AgencyTime::tz((int) $agency->id) ?: 'America/Toronto';
            $now = Carbon::now($tz);
            $centreIds = DB::table('centres')->where('agency_id', $agency->id)->pluck('id')->all();
            if (! $centreIds) {
                continue;
            }

            if ($cfg['staff_enabled']) {
                $staffClosed += $this->closeStaff($centreIds, $cfg, $tz, $now, $dry);
            }
            if ($cfg['children_enabled']) {
                $childClosed += $this->closeChildren($centreIds, $cfg, $tz, $now, $dry);
            }
        }

        $this->info(($dry ? 'Would close ' : 'Closed ') . $staffClosed . ' staff punch(es) and ' . $childClosed . ' child check-in(s)');
        return self::SUCCESS;
    }

    private function closeStaff(array $centreIds, array $cfg, string $tz, Carbon $now, bool $dry): int
    {
        $open = DB::table('time_punches as p')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->whereIn('p.centre_id', $centreIds)
            ->whereNull('p.punched_out_at')
            ->get(['p.id', 'p.punched_in_at', 'p.notes', 'u.first_name', 'u.last_name']);

        $n = 0;
        foreach ($open as $p) {
            $in = Carbon::parse($p->punched_in_at)->timezone($tz);
            $cutoff = $this->stampFor($in, (string) $cfg['staff_at'], $tz);

            // Not yet past the sign-off time on the day it started — the shift may still
            // legitimately be running, so leave it alone.
            if ($now->lt($cutoff)) {
                continue;
            }
            // A punch that started AFTER its own sign-off time (a late shift) gets the
            // max-hours rule instead, so an evening shift is not closed before it began.
            if ($cutoff->lte($in)) {
                $cutoff = $in->copy()->addHours((int) $cfg['staff_max_hours']);
                if ($now->lt($cutoff)) {
                    continue;
                }
            }

            $name = trim($p->first_name . ' ' . $p->last_name);
            $this->line(sprintf('    %-24s in %s -> out %s', $name, $in->format('D H:i'), $cutoff->format('D H:i')));

            if (! $dry) {
                DB::table('time_punches')->where('id', $p->id)->update([
                    'punched_out_at' => $cutoff->clone()->utc(),
                    // Marked, so a timesheet can show this was not a real clock-out.
                    'source' => 'auto',
                    'notes' => trim((string) ($p->notes ?? '') . ' [auto sign-off: no clock-out recorded]'),
                ]);

                // Deliberately NOT written to daily_events: that table is keyed on a
                // child (child_id is NOT NULL), and filing an educator's clock-out against
                // some arbitrary child would put a false entry on a real child's day. The
                // punch carries the marker, and the admin digest reports it.
            }
            $n++;
        }
        return $n;
    }

    private function closeChildren(array $centreIds, array $cfg, string $tz, Carbon $now, bool $dry): int
    {
        // The last event per child decides whether they are still in. Looking only at
        // check-ins would re-close a child who left properly.
        $since = $now->copy()->subDays(7)->utc();
        $events = DB::table('check_events as e')
            ->join('children as ch', 'ch.id', '=', 'e.child_id')
            ->join('families as f', 'f.id', '=', 'ch.family_id')
            ->whereIn('f.centre_id', $centreIds)
            ->where('e.occurred_at', '>=', $since)
            ->orderBy('e.occurred_at')
            ->get(['e.child_id', 'e.room_id', 'e.event_type', 'e.occurred_at', 'ch.first_name', 'ch.last_name']);

        $last = [];
        foreach ($events as $e) {
            $last[$e->child_id] = $e;
        }

        $n = 0;
        foreach ($last as $childId => $e) {
            if (! str_contains(strtolower((string) $e->event_type), 'in')) {
                continue;   // already out
            }
            $in = Carbon::parse($e->occurred_at)->timezone($tz);
            $cutoff = $this->stampFor($in, (string) $cfg['children_at'], $tz);
            if ($cutoff->lte($in)) {
                $cutoff = $in->copy()->addHours(12);
            }
            if ($now->lt($cutoff)) {
                continue;
            }

            $name = trim($e->first_name . ' ' . $e->last_name);
            $this->line(sprintf('    %-24s in %s -> out %s', $name, $in->format('D H:i'), $cutoff->format('D H:i')));

            if (! $dry) {
                DB::table('check_events')->insert([
                    'child_id' => $childId,
                    'room_id' => $e->room_id,
                    'event_type' => 'check_out',
                    'occurred_at' => $cutoff->clone()->utc(),
                    // kiosk_source is a tinyint(1), not a label: writing 'auto' there
                    // would have recorded "this came from a kiosk", which is false. The
                    // note is what marks it, and it is the thing a human reads anyway.
                    'notes' => 'Auto sign-off — no check-out was recorded.',
                    'created_at' => now(),
                ]);

                // And on the day's log, where people actually look. Without this an
                // automatically closed day is indistinguishable from a properly closed one
                // to everyone except whoever opens the timesheet.
                $this->logToDay(
                    (int) $childId,
                    $e->room_id ? (int) $e->room_id : null,
                    $cutoff->clone()->utc(),
                    'Signed out automatically at ' . $cutoff->format('g:i A')
                        . ' — no sign-out was recorded. Please sign children out at pickup so the day is accurate.'
                );
            }
            $n++;
        }
        return $n;
    }

    /**
     * A line on a child's day. daily_events.event_type is an ENUM, so 'note' is used
     * rather than inventing a value the column would reject outright.
     */
    private function logToDay(int $childId, ?int $roomId, Carbon $occurredAtUtc, string $text): void
    {
        try {
            // room_id and recorded_by_id are NOT NULL. The entry is attributed to whoever
            // checked this child IN — the person who should have signed them out, and the
            // one a director needs to see against it. Without a room or a user there is
            // nothing honest to write, so nothing is written.
            $lastIn = DB::table('check_events')->where('child_id', $childId)
                ->where('event_type', 'check_in')->orderByDesc('occurred_at')
                ->first(['room_id', 'by_user_id', 'recorded_by_id']);
            $roomId = $roomId ?: ($lastIn->room_id ?? null);
            $by = $lastIn->recorded_by_id ?? $lastIn->by_user_id ?? null;
            if (! $roomId || ! $by) {
                return;
            }

            DB::table('daily_events')->insert([
                'child_id' => $childId,
                'room_id' => $roomId,
                'recorded_by_id' => $by,
                'event_type' => 'note',
                'occurred_at' => $occurredAtUtc,
                // NOT NULL, and worth carrying properly: a reader can then tell an
                // automatic closure from a typed note without parsing the sentence.
                'payload' => json_encode(['auto_sign_off' => true, 'reason' => 'no_sign_out_recorded']),
                'notes' => $text,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // A log line is not worth failing the sign-off it describes.
            \Illuminate\Support\Facades\Log::warning('Auto sign-off day-log failed', [
                'child' => $childId, 'error' => $e->getMessage(),
            ]);
        }
    }

    /** The configured HH:MM on the date the punch or check-in started, in agency time. */
    private function stampFor(Carbon $startedAt, string $hhmm, string $tz): Carbon
    {
        [$h, $m] = array_pad(explode(':', $hhmm), 2, '0');
        return $startedAt->copy()->timezone($tz)->setTime((int) $h, (int) $m, 0);
    }
}
