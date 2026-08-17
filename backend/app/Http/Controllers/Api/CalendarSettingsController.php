<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Which layers the calendar draws, per agency — the Outlook idea of turning calendars on
 * and off, applied to the things this platform already knows about.
 *
 * Filtering happens SERVER-side, in the overlay endpoint. Fetching everything and hiding
 * it in the browser would still ship a list of every child's absence and every staff
 * birthday to a client that has been told not to show them, which is not the same as not
 * showing them.
 *
 * Everything defaults ON: these are all things the agency already records, and a calendar
 * that silently omits a closure is worse than a busy one. The switches exist to quieten a
 * view, not to make it usable in the first place.
 */
final class CalendarSettingsController extends Controller
{
    public const DEFAULTS = [
        'show_closures' => true,
        'show_birthdays' => true,
        'show_absences' => true,
        'show_timeoff' => true,
        'show_vacations' => true,
        // Pending leave is a separate decision from leave: some directors want to see
        // what might happen, others only what is settled.
        'show_pending' => true,
        'show_staff_birthdays' => true,
        'show_child_birthdays' => true,
    ];

    public static function read(int $agencyId): array
    {
        $row = DB::table('agencies')->where('id', $agencyId)->select('settings')->first();
        $settings = ($row && $row->settings) ? (json_decode($row->settings, true) ?: []) : [];
        $c = (isset($settings['calendar']) && is_array($settings['calendar'])) ? $settings['calendar'] : [];
        return array_merge(self::DEFAULTS, $c);
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
        return (int) DB::table('role_assignments')
            ->where('user_id', $request->user()->id)->where('active', 1)
            ->whereIn('role', ['agency_admin', 'platform_admin', 'centre_director'])
            ->value('agency_id');
    }

    private function assertAdmin(Request $request): void
    {
        $ok = DB::table('role_assignments')
            ->where('user_id', $request->user()->id)->where('active', 1)
            ->whereIn('role', ['agency_admin', 'platform_admin', 'centre_director'])
            ->exists();
        abort_unless($ok, 403, 'Directors and admins only');
    }

    /** GET /admin/calendar-settings */
    public function show(Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $agencyId = $this->resolveAgencyId($request);
        $row = DB::table('agencies')->where('id', $agencyId)->select('id', 'name')->first();
        abort_unless($row, 404, 'Agency not found');

        return response()->json([
            'agency_id' => $row->id,
            'agency_name' => $row->name,
            'calendar' => self::read($agencyId),
        ]);
    }

    /** POST/PATCH /admin/calendar-settings */
    public function update(Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $agencyId = $this->resolveAgencyId($request);

        $rules = [];
        foreach (array_keys(self::DEFAULTS) as $k) {
            $rules[$k] = ['nullable', 'boolean'];
        }
        $request->validate($rules);

        $current = self::read($agencyId);
        foreach (array_keys(self::DEFAULTS) as $k) {
            // has() not filled(): false is a value here, and filled() discards it.
            if ($request->has($k)) {
                $current[$k] = $request->boolean($k);
            }
        }

        $row = DB::table('agencies')->where('id', $agencyId)->select('settings')->first();
        abort_unless($row, 404, 'Agency not found');
        $settings = $row->settings ? (json_decode($row->settings, true) ?: []) : [];
        $settings['calendar'] = $current;

        DB::table('agencies')->where('id', $agencyId)->update([
            'settings' => json_encode($settings),
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true, 'calendar' => $current]);
    }
}
