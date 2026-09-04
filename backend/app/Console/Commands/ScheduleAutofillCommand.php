<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\AccountStatus;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Keep every centre's rota filled a few weeks ahead (2026-09-02).
 *
 * The Autofill button fills the week you are looking at. That is fine for the week you
 * are looking at and no use at all for the fact that a rota is a standing thing: a month
 * later somebody opens the calendar and it is empty again, because nobody thought to
 * press a button for a week that was not yet interesting.
 *
 * So this runs nightly and keeps a rolling horizon filled. It shares its rules with the
 * button rather than reimplementing them, because two copies of "which days count" is how
 * the two drift apart:
 *
 *   - only the centre's open_days, only between its open_time and close_time;
 *   - never a closure day;
 *   - never a day that already has a shift for that person;
 *   - and never a day EARLIER than the horizon it last filled for that centre, which is
 *     what makes a deletion stick. Without that second rule the job only knew "no shift
 *     here", so a Tuesday a director had deliberately cleared came back overnight;
 *   - only the centre's DESIGNATED PROVIDER, or its educators when no provider is named.
 *     Deliberately narrower than the button, which lets a human pick anyone: a cron that
 *     guesses who should be working is a cron that puts people on rotas they never
 *     agreed to.
 *
 * It is opt-in per agency (settings.schedule_autofill). An agency that builds its rota by
 * hand must not find it built for them.
 */
class ScheduleAutofillCommand extends Command
{
    protected $signature = 'schedule:autofill
                            {--days=28 : How far ahead to keep filled}
                            {--agency= : Restrict to one agency id}
                            {--centre= : Restrict to one centre id}
                            {--force : Ignore the per-agency opt-in switch}
                            {--dry-run : Report what would be written, write nothing}';

    protected $description = "Keep each centre's staff schedule filled from its own opening hours";

    public function handle(): int
    {
        $days = max(1, min(120, (int) $this->option('days')));
        $dry = (bool) $this->option('dry-run');

        // The agency's own day, not the server's. A rota written against a UTC "today"
        // starts a day early for half the year.
        $today = Carbon::now(config('app.agency_timezone', 'America/Toronto'))->startOfDay();
        $start = $today->copy();
        $end = $today->copy()->addDays($days);

        $centres = DB::table('centres')->whereNull('deleted_at')
            ->when($this->option('centre'), fn ($q) => $q->where('id', (int) $this->option('centre')))
            ->when($this->option('agency'), fn ($q) => $q->where('agency_id', (int) $this->option('agency')))
            ->orderBy('agency_id')->orderBy('id')
            ->get();

        $agencySettings = DB::table('agencies')->pluck('settings', 'id');

        $totalCreated = 0;
        $touched = 0;

        foreach ($centres as $centre) {
            if (! $this->option('force')) {
                $s = json_decode((string) ($agencySettings[$centre->agency_id] ?? ''), true);
                if (! is_array($s) || empty($s['schedule_autofill'])) {
                    continue;
                }
            }

            try {
                $made = $this->fillCentre($centre, $start, $end, $dry);
            } catch (Throwable $e) {
                // One bad centre must not stop the rest of the agency being filled.
                $this->error(sprintf('  centre #%d %s: %s', $centre->id, $centre->name, $e->getMessage()));
                continue;
            }

            if ($made === null) {
                continue;
            }
            $touched++;
            $totalCreated += $made;
            if ($made > 0) {
                $this->line(sprintf('  centre #%-4d %-28s +%d shift(s)', $centre->id, mb_substr($centre->name, 0, 28), $made));
            }
        }

        $this->info(sprintf(
            '%s%d shift(s) across %d centre(s), %s to %s.',
            $dry ? '[dry run] would create ' : 'Created ',
            $totalCreated,
            $touched,
            $start->toDateString(),
            $end->toDateString()
        ));

        return self::SUCCESS;
    }

    /** @return int|null shifts created, or null if this centre cannot be filled */
    private function fillCentre($centre, Carbon $start, Carbon $end, bool $dry): ?int
    {
        if (empty($centre->open_time) || empty($centre->close_time)) {
            return null;
        }

        /* Only ever fill BEYOND the horizon already reached for this centre. This is the
           rule that lets a human delete a shift and have it stay deleted: the job extends
           the rota forward and never revisits a day it has already written once. */
        $settings = json_decode((string) ($centre->settings ?? ''), true);
        $through = is_array($settings) ? ($settings['schedule_autofill_through'] ?? null) : null;
        if ($through) {
            $after = Carbon::parse($through)->startOfDay()->addDay();
            if ($after->gt($start)) {
                $start = $after;
            }
        }
        if ($start->gt($end)) {
            return 0;   // already filled to the horizon; nothing to extend
        }

        $rooms = DB::table('rooms')->where('centre_id', $centre->id)->orderBy('id')->pluck('id')->all();
        if (! $rooms) {
            return null;
        }

        $userIds = $this->staffFor($centre);
        if (! $userIds) {
            return null;
        }

        $openDays = $this->openDays($centre);
        $closures = $this->closureDates((int) $centre->id, $start, $end);

        $ownRoom = DB::table('educator_rooms')
            ->whereIn('user_id', $userIds)->whereIn('room_id', $rooms)
            ->get()->groupBy('user_id');

        $existing = [];
        foreach (DB::table('shifts')
            ->whereIn('room_id', $rooms)->whereIn('user_id', $userIds)
            ->where('starts_at', '>=', $start->toDateTimeString())
            ->where('starts_at', '<=', $end->copy()->endOfDay()->toDateTimeString())
            ->get(['user_id', 'starts_at']) as $row) {
            $existing[$row->user_id . '|' . Carbon::parse($row->starts_at)->toDateString()] = true;
        }

        $open = substr((string) $centre->open_time, 0, 8);
        $close = substr((string) $centre->close_time, 0, 8);
        $now = now();
        $rows = [];

        foreach ($userIds as $uid) {
            $room = optional($ownRoom->get($uid))->first()->room_id ?? $rooms[0];
            for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
                $date = $d->toDateString();
                if (! in_array((int) $d->isoWeekday(), $openDays, true)) { continue; }
                if (isset($closures[$date])) { continue; }
                if (isset($existing[$uid . '|' . $date])) { continue; }
                $rows[] = [
                    'user_id' => $uid,
                    'room_id' => $room,
                    'starts_at' => $date . ' ' . $open,
                    'ends_at' => $date . ' ' . $close,
                    'role' => 'lead',
                    'status' => 'scheduled',
                    'created_at' => $now,
                ];
            }
        }

        if ($dry) {
            return count($rows);
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('shifts')->insert($chunk);
        }

        /* Move the horizon even when nothing was written -- a week that was entirely
           closures is still a week this job has considered, and re-considering it
           tomorrow would re-open the same deletion hole. */
        $settings = is_array($settings) ? $settings : [];
        $settings['schedule_autofill_through'] = $end->toDateString();
        DB::table('centres')->where('id', $centre->id)
            ->update(['settings' => json_encode($settings), 'updated_at' => now()]);

        if (! $rows) {
            return 0;
        }

        \App\Support\Audit::write([
            'user_id' => null,
            'action' => 'shift.autofilled',
            'entity_type' => 'centre',
            'entity_id' => $centre->id,
            'payload' => json_encode([
                'source' => 'schedule:autofill (nightly)',
                'centre' => $centre->name,
                'range' => $start->toDateString() . ' to ' . $end->toDateString(),
                'hours' => $open . '-' . $close,
                'created' => count($rows),
                'staff' => DB::table('users')->whereIn('id', $userIds)
                    ->get(['first_name', 'last_name'])
                    ->map(fn ($u) => trim($u->first_name . ' ' . $u->last_name))->all(),
                'dates' => array_values(array_unique(array_map(fn ($r) => substr($r['starts_at'], 0, 10), $rows))),
            ]),
            'created_at' => $now,
        ]);

        return count($rows);
    }

    /**
     * The designated provider, else the centre's educators.
     *
     * Same rule the endpoint uses. Kept narrow on purpose: an unattended job should roster
     * only the people whose working pattern the centre record actually states.
     */
    private function staffFor($centre): array
    {
        $first = trim((string) ($centre->supervisor_first_name ?? ''));
        $last = trim((string) ($centre->supervisor_last_name ?? ''));

        if ($first !== '' || $last !== '') {
            $match = DB::table('users as u')
                ->join('role_assignments as ra', 'ra.user_id', '=', 'u.id')
                ->where('ra.centre_id', $centre->id)->where('ra.active', true)
                ->whereNull('u.deleted_at')
                ->whereNotIn('u.status', AccountStatus::CLOSED)
                ->whereRaw('LOWER(TRIM(u.first_name)) = ?', [mb_strtolower($first)])
                ->whereRaw('LOWER(TRIM(u.last_name)) = ?', [mb_strtolower($last)])
                ->distinct()->pluck('u.id');
            if ($match->count() === 1) {
                return [(int) $match->first()];
            }
        }

        return DB::table('role_assignments as ra')
            ->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.role', 'educator')->where('ra.active', true)
            ->where('ra.centre_id', $centre->id)
            ->whereNull('u.deleted_at')
            ->whereNotIn('u.status', AccountStatus::CLOSED)
            ->pluck('u.id')->unique()->map(fn ($i) => (int) $i)->values()->all();
    }

    private function openDays($centre): array
    {
        $s = json_decode((string) ($centre->settings ?? ''), true);
        $days = is_array($s) ? ($s['open_days'] ?? null) : null;
        if (! is_array($days) || ! count($days)) {
            return [1, 2, 3, 4, 5];
        }
        $out = [];
        foreach ($days as $d) {
            $d = (int) $d;
            if ($d >= 1 && $d <= 7) { $out[] = $d; }
        }

        return $out ?: [1, 2, 3, 4, 5];
    }

    private function closureDates(int $centreId, Carbon $start, Carbon $end): array
    {
        $map = [];
        foreach (DB::table('centre_closures')->where('centre_id', $centreId)
            ->where('closure_date', '<=', $end->toDateString())
            ->where(function ($q) use ($start) {
                $q->where('end_date', '>=', $start->toDateString())->orWhereNull('end_date');
            })->get() as $r) {
            $from = Carbon::parse($r->closure_date)->startOfDay();
            $to = Carbon::parse($r->end_date ?: $r->closure_date)->startOfDay();
            if ($to->lt($from)) { $to = $from->copy(); }
            for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
                $map[$d->toDateString()] = true;
            }
        }

        return $map;
    }
}
