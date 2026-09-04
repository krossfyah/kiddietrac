<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\StatHolidays;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Put the statutory holidays on the closure calendar (2026-09-02).
 *
 * Everything downstream already works off centre_closures: the schedule paints closure
 * bands, autofill refuses to roster a closure day, attendance stops expecting children,
 * and ClosureReminderCommand writes to families. So statutory holidays do not need a
 * parallel system -- they need to be closures, generated from the rules rather than typed
 * in by hand each December and forgotten each January.
 *
 * Rules that matter:
 *
 *   - Opt-in per agency (settings.stat_holidays.enabled). Some operators work Family Day.
 *     Creating closures nobody asked for would cancel real care.
 *   - Country per agency, so a Canadian agency gets Victoria Day and an American one gets
 *     Memorial Day. Derived from the agency's own record, falling back to its centres'.
 *   - A date that ALREADY has a closure is never touched. A hand-entered Christmas
 *     closure, possibly with its own reason and billing decision, outranks a generated
 *     one, and the two must not both exist.
 *   - Generated rows are marked closure_type = 'stat_holiday' so they can be told from a
 *     manual 'holiday' -- which is what makes --prune safe.
 *
 * Idempotent: run it as often as you like.
 */
class HolidaySyncCommand extends Command
{
    protected $signature = 'holidays:sync
                            {--months=14 : how far ahead to generate}
                            {--agency= : restrict to one agency}
                            {--force : ignore the per-agency opt-in switch}
                            {--prune : remove FUTURE generated holidays that are no longer in the rules}
                            {--dry-run : report what would change, change nothing}';

    protected $description = 'Generate statutory holiday closures from the holiday rules';

    public const GENERATED_TYPE = 'stat_holiday';

    public function handle(): int
    {
        $months = max(1, min(36, (int) $this->option('months')));
        $dry = (bool) $this->option('dry-run');

        $agencies = DB::table('agencies')->whereNull('deleted_at')
            ->when($this->option('agency'), fn ($q) => $q->where('id', (int) $this->option('agency')))
            ->get(['id', 'name', 'settings', 'country', 'timezone']);

        $created = 0;
        $pruned = 0;
        $skipped = 0;
        $offDay = 0;

        foreach ($agencies as $agency) {
            $cfg = self::configFor($agency);
            if (! $cfg['enabled'] && ! $this->option('force')) {
                continue;
            }

            $tz = $agency->timezone ?: 'America/Toronto';
            $from = Carbon::now($tz)->startOfDay();
            $to = $from->copy()->addMonths($months);

            $holidays = StatHolidays::between($from, $to, $cfg['country'], $cfg['optional']);
            $wanted = [];
            foreach ($holidays as $h) {
                /* The OBSERVED date is the day the centre shuts. Boxing Day 2026 falls on
                   a Saturday and is observed on Monday 28 December; closing on the 26th
                   would be closing on a day nobody was coming in, and skipping it
                   altogether -- which this did at first -- loses the day off entirely. */
                $when = $h['observed'] ?? $h['date'];
                // Named so the substitute day is obviously deliberate, not a typo.
                $wanted[$when] = $h['name'] . (! empty($h['moved']) ? ' (observed)' : '');
            }

            $centres = DB::table('centres')->where('agency_id', $agency->id)->whereNull('deleted_at')
                ->get(['id', 'name', 'settings']);

            $this->line(sprintf('%s (%s, %d holidays, %d centres)',
                $agency->name, $cfg['country'], count($holidays), $centres->count()));

            foreach ($centres as $centre) {
                // Everything already on this centre's calendar in the window. A generated
                // holiday must never sit on top of a real closure somebody entered.
                $existing = DB::table('centre_closures')
                    ->where('centre_id', $centre->id)
                    ->whereBetween('closure_date', [$from->toDateString(), $to->toDateString()])
                    ->get(['id', 'closure_date', 'closure_type', 'reason']);

                $taken = [];
                foreach ($existing as $e) {
                    $taken[substr((string) $e->closure_date, 0, 10)] = $e;
                }

                /* Which weekdays this centre actually operates. A holiday landing outside
                   them is not a closure -- there was nothing to close. */
                $cs = json_decode((string) ($centre->settings ?? ''), true);
                $openDays = is_array($cs) ? ($cs['open_days'] ?? null) : null;
                if (! is_array($openDays) || ! count($openDays)) {
                    $openDays = [1, 2, 3, 4, 5];
                }
                $openDays = array_map('intval', $openDays);

                foreach ($wanted as $date => $name) {
                    if (isset($taken[$date])) {
                        $skipped++;
                        continue;
                    }
                    if (! in_array((int) Carbon::parse($date)->isoWeekday(), $openDays, true)) {
                        // e.g. Boxing Day on a Saturday at a Monday-to-Friday centre.
                        $offDay++;
                        continue;
                    }
                    if ($dry) {
                        $this->line(sprintf('  [dry] %-26s %s  %s', $centre->name, $date, $name));
                        $created++;
                        continue;
                    }
                    try {
                        DB::table('centre_closures')->insert([
                            'centre_id' => $centre->id,
                            'closure_date' => $date,
                            'end_date' => $date,
                            'closure_type' => self::GENERATED_TYPE,
                            'reason' => $name,
                            /* Statutory holidays are normally paid/charged as usual, and
                               this flag only records intent -- no invoicing code reads it.
                               Left false so the reminder does not promise a credit that
                               nothing will produce. */
                            'affects_billing' => 0,
                            'created_by_id' => null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $created++;
                    } catch (Throwable $e) {
                        $this->error(sprintf('  %s %s: %s', $centre->name, $date, $e->getMessage()));
                    }
                }

                if ($this->option('prune')) {
                    /* Only ever future, only ever rows this command made, and only ones
                       the rules no longer produce -- an agency that turned off an optional
                       holiday, or changed country. A past closure is a record of something
                       that happened and is never removed. */
                    foreach ($existing as $e) {
                        $d = substr((string) $e->closure_date, 0, 10);
                        if ($e->closure_type !== self::GENERATED_TYPE) { continue; }
                        if ($d <= $from->toDateString()) { continue; }
                        if (isset($wanted[$d]) && $wanted[$d] === $e->reason) { continue; }
                        if ($dry) {
                            $this->line(sprintf('  [dry] prune %-22s %s  %s', $centre->name, $d, $e->reason));
                            $pruned++;
                            continue;
                        }
                        DB::table('centre_closures')->where('id', $e->id)->delete();
                        $pruned++;
                    }
                }
            }
        }

        if (! $dry && ($created > 0 || $pruned > 0)) {
            \App\Support\Audit::write([
                'user_id' => null,
                'action' => 'holidays.synced',
                'entity_type' => 'agency',
                'entity_id' => $this->option('agency') ? (int) $this->option('agency') : null,
                'payload' => json_encode([
                    'source' => 'holidays:sync',
                    'months_ahead' => $months,
                    'created' => $created,
                    'pruned' => $pruned,
                    'left_alone' => $skipped,
                ]),
                'created_at' => now(),
            ]);
        }

        $this->info(sprintf('%s%d created, %d pruned, %d already on the calendar, %d fell on a day the centre is not open.',
            $dry ? '[dry run] ' : '', $created, $pruned, $skipped, $offDay));

        return self::SUCCESS;
    }

    /**
     * An agency's holiday settings, defaulted the way the settings screen shows them.
     *
     * @return array{enabled:bool,country:string,optional:string[],notice_days:int[]}
     */
    public static function configFor($agency): array
    {
        $s = json_decode((string) ($agency->settings ?? ''), true);
        $s = is_array($s) ? $s : [];
        $h = is_array($s['stat_holidays'] ?? null) ? $s['stat_holidays'] : [];

        /* Country, in order of how much it was actually meant: the holiday setting, then
           the agency's own country, then whatever its centres say. iLearn's centres record
           "Canada" while others record "CA", which is why this goes through the
           normaliser rather than comparing strings. */
        $country = $h['country'] ?? ($s['country'] ?? ($agency->country ?? null));
        if (! $country) {
            $country = DB::table('centres')->where('agency_id', $agency->id)
                ->whereNull('deleted_at')->whereNotNull('country')->value('country');
        }

        $days = $h['notice_days'] ?? [1];
        if (is_string($days)) {
            $days = explode(',', $days);
        }
        $days = array_values(array_filter(array_map(fn ($d) => (int) trim((string) $d), (array) $days), fn ($d) => $d > 0));

        return [
            'enabled' => (bool) ($h['enabled'] ?? false),
            'country' => StatHolidays::countryCode(is_string($country) ? $country : null),
            'optional' => array_values(array_filter((array) ($h['optional'] ?? []), 'is_string')),
            'notice_days' => $days ?: [1],
        ];
    }
}
