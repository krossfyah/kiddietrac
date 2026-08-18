<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Per-agency billing defaults, stored in agencies.settings->billing.
 *
 * Only the tax default lives here today. It exists as a setting rather than a field on
 * every invoice for one reason: a rate typed forty times is a rate eventually typed wrong,
 * and 1.3% instead of 13% is not a mistake anybody catches by reading a total.
 */
final class BillingSettingsController extends Controller
{
    public const DEFAULTS = [
        'tax_rate' => 0.0,
        'tax_label' => 'Tax',
        // Off by default: an agency that is not registered to charge tax should not have a
        // rate quietly waiting to be applied.
        'tax_default_on' => false,
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
            ->whereIn('role', ['agency_admin', 'platform_admin'])->value('agency_id');
    }

    private function assertAdmin(Request $request): void
    {
        // Deliberately tighter than the other settings screens: a tax rate is a number
        // that ends up on money leaving or entering the business.
        $ok = DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', 1)
            ->whereIn('role', ['agency_admin', 'platform_admin'])->exists();
        abort_unless($ok, 403, 'Agency administrators only');
    }

    public static function read(int $agencyId): array
    {
        $row = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $b = (json_decode((string) $row, true) ?: [])['billing'] ?? [];
        return array_merge(self::DEFAULTS, is_array($b) ? $b : []);
    }

    /** GET /admin/billing-settings */
    public function show(Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $agencyId = $this->resolveAgencyId($request);
        $row = DB::table('agencies')->where('id', $agencyId)->first(['id', 'name']);
        abort_unless($row, 404, 'Agency not found');

        return response()->json([
            'agency_id' => $row->id,
            'agency_name' => $row->name,
            'billing' => self::read($agencyId),
        ]);
    }

    /** POST/PATCH /admin/billing-settings */
    public function update(Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $agencyId = $this->resolveAgencyId($request);

        $data = $request->validate([
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tax_label' => ['nullable', 'string', 'max:16'],
            'tax_default_on' => ['nullable', 'boolean'],
        ]);

        $current = self::read($agencyId);
        if (array_key_exists('tax_rate', $data) && $data['tax_rate'] !== null) {
            $current['tax_rate'] = round((float) $data['tax_rate'], 2);
        }
        if (! empty($data['tax_label'])) {
            $current['tax_label'] = trim((string) $data['tax_label']);
        }
        if ($request->has('tax_default_on')) {
            $current['tax_default_on'] = $request->boolean('tax_default_on');
        }

        $row = DB::table('agencies')->where('id', $agencyId)->first(['settings']);
        abort_unless($row, 404, 'Agency not found');
        $settings = $row->settings ? (json_decode($row->settings, true) ?: []) : [];
        $settings['billing'] = $current;

        DB::table('agencies')->where('id', $agencyId)->update([
            'settings' => json_encode($settings),
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true, 'billing' => $current]);
    }
}
