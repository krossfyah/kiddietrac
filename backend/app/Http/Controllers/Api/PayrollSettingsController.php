<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Per-agency pay schedule, stored in agencies.settings->payroll.
 *
 * Agencies pay on different rhythms and there is no sensible default to inherit — a
 * fortnightly agency told "you are paid monthly" would plan around the wrong date. So
 * this stays off until somebody sets it, and the overview card says so rather than
 * inventing a date.
 *
 * The anchor is a real payday, not a day-of-month. Fortnightly pay does not land on the
 * same date each month, so the only way to know which Friday is a payday is to count from
 * one that was.
 */
final class PayrollSettingsController extends Controller
{
    private const DEFAULTS = [
        'enabled' => false,
        // weekly | biweekly | semimonthly | monthly
        'cadence' => 'biweekly',
        // A date payroll actually landed on. Everything else is counted from here.
        'anchor_date' => null,
        // Semi-monthly pays twice a month on fixed dates; 31 clamps to the month end.
        'semimonthly_days' => [15, 31],
    ];

    private function resolveAgencyId(Request $request): int
    {
        $header = (int) $request->header('X-Active-Agency-Id');
        if ($header && DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)
                ->where(function ($q) use ($header) {
                    $q->where('role', 'platform_admin')->orWhere('agency_id', $header);
                })->exists()) {
            return $header;
        }

        return (int) DB::table('role_assignments')
            ->where('user_id', $request->user()->id)->where('active', 1)
            ->whereIn('role', ['agency_admin', 'platform_admin', 'centre_director'])
            ->value('agency_id');
    }

    private function assertAdmin(Request $request): void
    {
        $ok = DB::table('role_assignments')
            ->where('user_id', $request->user()->id)->where('active', 1)
            ->whereIn('role', ['agency_admin', 'platform_admin'])
            ->exists();
        abort_unless($ok, 403, 'Admin only');
    }

    public static function read(int $agencyId): array
    {
        $row = DB::table('agencies')->where('id', $agencyId)->select('settings')->first();
        $settings = ($row && $row->settings) ? (json_decode($row->settings, true) ?: []) : [];
        $p = (isset($settings['payroll']) && is_array($settings['payroll'])) ? $settings['payroll'] : [];

        return array_merge(self::DEFAULTS, $p);
    }

    /**
     * The next payday on or after $from, or null when nothing is configured.
     *
     * Counted in the AGENCY's timezone: "the next payday" read from a browser three
     * hours west must not come out a day early.
     */
    public static function nextPayday(int $agencyId, ?Carbon $from = null): ?array
    {
        $cfg = self::read($agencyId);
        if (empty($cfg['enabled'])) {
            return null;
        }

        $tz = \App\Support\AgencyTime::tz($agencyId) ?: 'America/Toronto';
        $today = ($from ? $from->copy() : Carbon::now($tz))->setTimezone($tz)->startOfDay();

        $cadence = (string) ($cfg['cadence'] ?? 'biweekly');

        if ($cadence === 'semimonthly') {
            $days = array_values(array_filter(array_map('intval', (array) ($cfg['semimonthly_days'] ?? [15, 31]))));
            sort($days);
            if (! $days) {
                return null;
            }
            // Look through this month and the next, so a date past the last payday of
            // the month rolls forward properly.
            foreach ([0, 1] as $addMonths) {
                $m = $today->copy()->addMonths($addMonths);
                foreach ($days as $d) {
                    // 31 on a 30-day month means the last day of it, not the 1st of next.
                    $cand = $m->copy()->startOfMonth()->addDays(min($d, $m->daysInMonth) - 1);
                    if ($cand->gte($today)) {
                        return self::describe($cand, $today);
                    }
                }
            }

            return null;
        }

        if ($cadence === 'monthly') {
            $anchor = ! empty($cfg['anchor_date']) ? Carbon::parse($cfg['anchor_date'], $tz)->startOfDay() : null;
            if (! $anchor) {
                return null;
            }
            $day = (int) $anchor->format('j');
            foreach ([0, 1] as $addMonths) {
                $m = $today->copy()->addMonths($addMonths);
                $cand = $m->copy()->startOfMonth()->addDays(min($day, $m->daysInMonth) - 1);
                if ($cand->gte($today)) {
                    return self::describe($cand, $today);
                }
            }

            return null;
        }

        // weekly / biweekly — counted forward from a real payday.
        $anchor = ! empty($cfg['anchor_date']) ? Carbon::parse($cfg['anchor_date'], $tz)->startOfDay() : null;
        if (! $anchor) {
            return null;
        }
        $step = $cadence === 'weekly' ? 7 : 14;

        // Whole periods elapsed since the anchor, then round up to the next one. Works
        // for an anchor in the future as well as the past.
        $diff = $anchor->diffInDays($today, false);
        $periods = (int) ceil($diff / $step);
        $cand = $anchor->copy()->addDays($periods * $step);
        if ($cand->lt($today)) {
            $cand->addDays($step);
        }

        return self::describe($cand, $today);
    }

    private static function describe(Carbon $date, Carbon $today): array
    {
        $days = (int) $today->diffInDays($date, false);

        return [
            'date' => $date->toDateString(),
            'label' => $date->format('M j'),
            'long' => $date->format('D, j M Y'),
            'days_away' => $days,
            'when' => $days === 0 ? 'Today' : ($days === 1 ? 'Tomorrow' : 'In '.$days.' days'),
        ];
    }

    /** GET /admin/payroll-settings */
    public function show(Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $agencyId = $this->resolveAgencyId($request);
        $row = DB::table('agencies')->where('id', $agencyId)->select('id', 'name')->first();
        abort_unless($row, 404, 'Agency not found');

        return response()->json([
            'agency_id' => $row->id,
            'agency_name' => $row->name,
            'payroll' => self::read($agencyId),
            'next_payday' => self::nextPayday($agencyId),
        ]);
    }

    /** PATCH /admin/payroll-settings */
    public function update(Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $agencyId = $this->resolveAgencyId($request);

        $data = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'cadence' => ['nullable', 'in:weekly,biweekly,semimonthly,monthly'],
            'anchor_date' => ['nullable', 'date'],
            'semimonthly_days' => ['nullable', 'array', 'max:2'],
            'semimonthly_days.*' => ['integer', 'min:1', 'max:31'],
        ]);

        $current = self::read($agencyId);
        if ($request->has('enabled')) {
            $current['enabled'] = $request->boolean('enabled');
        }
        foreach (['cadence', 'anchor_date', 'semimonthly_days'] as $k) {
            if (array_key_exists($k, $data) && $data[$k] !== null) {
                $current[$k] = $data[$k];
            }
        }
        if (! empty($current['anchor_date'])) {
            $current['anchor_date'] = Carbon::parse($current['anchor_date'])->toDateString();
        }

        // Turning it on with nothing to count from would show a card that cannot
        // compute a date. Say so instead of saving something unusable.
        if (! empty($current['enabled'])
            && in_array($current['cadence'], ['weekly', 'biweekly', 'monthly'], true)
            && empty($current['anchor_date'])) {
            return response()->json([
                'message' => 'Give the date of a recent payday — the schedule is counted from it.',
            ], 422);
        }

        $row = DB::table('agencies')->where('id', $agencyId)->select('settings')->first();
        abort_unless($row, 404, 'Agency not found');
        $settings = $row->settings ? (json_decode($row->settings, true) ?: []) : [];
        $settings['payroll'] = $current;

        DB::table('agencies')->where('id', $agencyId)->update([
            'settings' => json_encode($settings),
            'updated_at' => now(),
        ]);

        return response()->json([
            'ok' => true,
            'payroll' => $current,
            'next_payday' => self::nextPayday($agencyId),
        ]);
    }
}
