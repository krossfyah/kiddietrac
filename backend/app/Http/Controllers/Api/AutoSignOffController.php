<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Auto sign-off settings, stored in agencies.settings->auto_signoff.
 *
 * Staff and children are configured separately because they are different problems. An
 * educator who forgets to clock out corrupts payroll; a child left checked in corrupts
 * ratio and attendance, and is the more serious of the two. An agency may well want the
 * second without the first.
 *
 * Both are OFF by default. Closing somebody's shift on their behalf writes a number that
 * a person will be paid against, and closing a child's day writes an attendance record
 * that may be produced to a regulator — neither is a default anyone should inherit.
 */
final class AutoSignOffController extends Controller
{
    public const DEFAULTS = [
        'staff_enabled' => false,
        'staff_at' => '19:00',
        // A punch left open past this many hours is abandoned rather than long. It is
        // closed at the configured time on the day it STARTED, never at "now" — see the
        // command for why that distinction is the whole point of this feature.
        'staff_max_hours' => 14,
        'children_enabled' => false,
        'children_at' => '18:30',
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
            ->whereIn('role', ['agency_admin', 'platform_admin', 'centre_director'])
            ->exists();
        abort_unless($ok, 403, 'Directors and admins only');
    }

    public static function read(int $agencyId): array
    {
        $row = DB::table('agencies')->where('id', $agencyId)->select('settings')->first();
        $settings = ($row && $row->settings) ? (json_decode($row->settings, true) ?: []) : [];
        $a = (isset($settings['auto_signoff']) && is_array($settings['auto_signoff'])) ? $settings['auto_signoff'] : [];
        return array_merge(self::DEFAULTS, $a);
    }

    /** GET /admin/auto-signoff */
    public function show(Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $agencyId = $this->resolveAgencyId($request);
        $row = DB::table('agencies')->where('id', $agencyId)->select('id', 'name')->first();
        abort_unless($row, 404, 'Agency not found');

        $centreIds = DB::table('centres')->where('agency_id', $agencyId)->pluck('id');

        // What this would act on right now, so the screen can say plainly what turning it
        // on would have done rather than leaving an admin to guess.
        $openPunches = DB::table('time_punches')->whereIn('centre_id', $centreIds)
            ->whereNull('punched_out_at')->count();

        return response()->json([
            'agency_id' => $row->id,
            'agency_name' => $row->name,
            'auto_signoff' => self::read($agencyId),
            'pending' => ['open_punches' => $openPunches],
        ]);
    }

    /** POST/PATCH /admin/auto-signoff */
    public function update(Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $agencyId = $this->resolveAgencyId($request);

        $data = $request->validate([
            'staff_enabled' => ['nullable', 'boolean'],
            'staff_at' => ['nullable', 'date_format:H:i'],
            'staff_max_hours' => ['nullable', 'integer', 'min:1', 'max:24'],
            'children_enabled' => ['nullable', 'boolean'],
            'children_at' => ['nullable', 'date_format:H:i'],
        ]);

        $current = self::read($agencyId);
        foreach (self::DEFAULTS as $k => $def) {
            if (is_bool($def)) {
                if ($request->has($k)) {
                    $current[$k] = $request->boolean($k);
                }
            } elseif (array_key_exists($k, $data) && $data[$k] !== null) {
                $current[$k] = $data[$k];
            }
        }

        $row = DB::table('agencies')->where('id', $agencyId)->select('settings')->first();
        abort_unless($row, 404, 'Agency not found');
        $settings = $row->settings ? (json_decode($row->settings, true) ?: []) : [];
        $settings['auto_signoff'] = $current;

        DB::table('agencies')->where('id', $agencyId)->update([
            'settings' => json_encode($settings),
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true, 'auto_signoff' => $current]);
    }
}
