<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Per-agency birthday email settings, stored in agencies.settings->birthdays.
 *
 * Four separate switches rather than one, because the recipients are not
 * interchangeable: an agency may well want its educators reminded that a birthday is
 * coming without mail going to families about it, and staff birthdays are a different
 * decision again from children's.
 *
 * Everything defaults OFF. Mail about somebody's child, or about a colleague's
 * birthday, is not a default anyone should inherit without asking for it.
 */
final class BirthdaySettingsController extends Controller
{
    private const DEFAULTS = [
        'enabled' => false,
        // How many days BEFORE the birthday the mail goes out. 0 sends on the day; the
        // default gives a room one day to plan rather than finding out on the morning.
        'days_ahead' => 1,
        'children_notify_guardians' => true,
        'children_notify_educators' => true,
        'staff_notify_person' => true,
        'staff_notify_leads' => false,
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

    private function read(int $agencyId): array
    {
        $row = DB::table('agencies')->where('id', $agencyId)->select('settings')->first();
        $settings = ($row && $row->settings) ? (json_decode($row->settings, true) ?: []) : [];
        $b = (isset($settings['birthdays']) && is_array($settings['birthdays'])) ? $settings['birthdays'] : [];
        return array_merge(self::DEFAULTS, $b);
    }

    /** GET /admin/birthday-settings */
    public function show(Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $agencyId = $this->resolveAgencyId($request);
        $row = DB::table('agencies')->where('id', $agencyId)->select('id', 'name')->first();
        abort_unless($row, 404, 'Agency not found');

        // How many people this would actually reach, so the screen can say plainly that
        // staff birthdays will do nothing until dates of birth are recorded.
        $centreIds = DB::table('centres')->where('agency_id', $agencyId)->pluck('id');
        $childrenWithDob = DB::table('children as ch')
            ->join('families as f', 'f.id', '=', 'ch.family_id')
            ->whereIn('f.centre_id', $centreIds)
            ->whereNull('ch.deleted_at')->whereNotNull('ch.date_of_birth')->count();
        $staffWithDob = DB::table('users as u')
            ->join('role_assignments as ra', 'ra.user_id', '=', 'u.id')
            ->where('ra.active', 1)->where('ra.role', '!=', 'guardian')
            ->where(function ($q) use ($agencyId, $centreIds) {
                $q->where('ra.agency_id', $agencyId)->orWhereIn('ra.centre_id', $centreIds);
            })
            ->whereNull('u.deleted_at')->whereNotNull('u.date_of_birth')
            ->distinct()->count('u.id');

        return response()->json([
            'agency_id' => $row->id,
            'agency_name' => $row->name,
            'birthdays' => $this->read($agencyId),
            'coverage' => [
                'children_with_dob' => $childrenWithDob,
                'staff_with_dob' => $staffWithDob,
            ],
        ]);
    }

    /** PATCH /admin/birthday-settings */
    public function update(Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $agencyId = $this->resolveAgencyId($request);

        $data = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'days_ahead' => ['nullable', 'integer', 'min:0', 'max:14'],
            'children_notify_guardians' => ['nullable', 'boolean'],
            'children_notify_educators' => ['nullable', 'boolean'],
            'staff_notify_person' => ['nullable', 'boolean'],
            'staff_notify_leads' => ['nullable', 'boolean'],
        ]);

        $current = $this->read($agencyId);
        foreach (self::DEFAULTS as $k => $def) {
            if (is_bool($def)) {
                // has() not filled(): "false" is a value here, and filled() discards it.
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
        $settings['birthdays'] = $current;

        DB::table('agencies')->where('id', $agencyId)->update([
            'settings' => json_encode($settings),
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true, 'birthdays' => $current]);
    }
}
