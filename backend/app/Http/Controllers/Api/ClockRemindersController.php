<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Clock in / clock out reminder settings, stored in agencies.settings->clock_reminders.
 *
 * The reminders existed but every agency got the same ones: a fixed 10:00 nudge for
 * anybody who had not clocked in and 18:30 for anybody still on the clock, hard-coded in
 * the scheduler. A centre that opens at 06:00 was being reminded four hours late, and one
 * that closes at 21:00 was told to clock out while the children were still there.
 *
 * Same shape and storage as AutoSignOffController — the two sit together on one screen —
 * so read() merges over defaults and a partial write cannot drop a key.
 */
class ClockRemindersController extends Controller
{
    // These reproduce exactly what the hard-coded scheduler did: on, 10:00 and 18:30,
    // weekdays only. Shipping them opt-IN would have silently stopped reminders agencies
    // already receive, which is worse than a settings page nobody has opened.
    public const DEFAULTS = [
        'in_enabled' => true,
        // Reminder to somebody who has not clocked in, in the agency's timezone.
        'in_at' => '10:00',
        'out_enabled' => true,
        // Reminder to somebody still on the clock.
        'out_at' => '18:30',
        // Most centres do not run at weekends, and there is no shift-schedule table to
        // consult, so this stops the reminders becoming noise on a Saturday.
        'weekdays_only' => true,
        'push' => true,
        'email' => true,
    ];

    public static function read(int $agencyId): array
    {
        $row = DB::table('agencies')->where('id', $agencyId)->select('settings')->first();
        $settings = ($row && $row->settings) ? (json_decode($row->settings, true) ?: []) : [];
        $a = (isset($settings['clock_reminders']) && is_array($settings['clock_reminders']))
            ? $settings['clock_reminders']
            : [];

        return array_merge(self::DEFAULTS, $a);
    }

    private function resolveAgencyId(Request $request): int
    {
        $header = (int) $request->header('X-Active-Agency-Id');
        if ($header && DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)
                ->where(function ($q) use ($header) {
                    $q->where('role', 'platform_admin')->orWhere('agency_id', $header);
                })->exists()) {
            return $header;
        }

        $own = DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)
            ->whereNotNull('agency_id')->value('agency_id');
        abort_unless($own, 403, 'No agency access.');

        return (int) $own;
    }

    private function assertAdmin(Request $request): void
    {
        $ok = DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)
            ->whereIn('role', ['agency_admin', 'centre_director', 'platform_admin'])->exists();
        abort_unless($ok, 403, 'Administrator access required.');
    }

    public function show(Request $request): JsonResponse
    {
        $this->assertAdmin($request);

        return response()->json(['settings' => self::read($this->resolveAgencyId($request))]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $agencyId = $this->resolveAgencyId($request);

        $data = $request->validate([
            'in_enabled' => 'nullable|boolean',
            'in_at' => 'nullable|date_format:H:i',
            'out_enabled' => 'nullable|boolean',
            'out_at' => 'nullable|date_format:H:i',
            'weekdays_only' => 'nullable|boolean',
            'push' => 'nullable|boolean',
            'email' => 'nullable|boolean',
        ]);

        $current = self::read($agencyId);
        $next = $current;
        foreach (self::DEFAULTS as $k => $_) {
            if (array_key_exists($k, $data) && $data[$k] !== null) {
                $next[$k] = is_bool(self::DEFAULTS[$k]) ? (bool) $data[$k] : $data[$k];
            }
        }

        // Read-modify-write the whole settings blob: other features keep their own keys
        // in here, and replacing it wholesale would delete them.
        $row = DB::table('agencies')->where('id', $agencyId)->select('settings')->first();
        $settings = ($row && $row->settings) ? (json_decode($row->settings, true) ?: []) : [];
        $settings['clock_reminders'] = $next;

        DB::table('agencies')->where('id', $agencyId)->update([
            'settings' => json_encode($settings),
            'updated_at' => now(),
        ]);

        return response()->json(['settings' => $next]);
    }
}
