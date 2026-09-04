<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * White-label branding per agency.
 * Stored in agencies.settings JSON column under the `branding` key.
 * Public GET so login page can fetch it before auth.
 */
final class BrandingController extends Controller
{
    private const DEFAULT_BRANDING = [
        'product_name' => 'Kiddietrac',
        'tagline' => 'Smart childcare platform',
        'primary_color' => '#1F6080',
        'accent_color' => '#8EC73C',
        'logo_url' => null,
        'favicon_url' => null,
        'login_subtitle' => "Sign in to see how your little one's day is going.",
        'support_email' => null,
        'email_from_name' => null,
    ];

    /**
     * Public — used by login page + every screen to apply branding
     * GET /api/v1/branding
     *   ?agency_id=N   (preferred — used by the in-app loader after the user has picked
     *                   an active agency; honored regardless of auth)
     *   ?slug=acme     (used by the login page when only the URL is known)
     *   no params      (falls back to first non-deleted agency by id)
     *
     * Merge order (later wins):
     *   DEFAULT_BRANDING  <  settings.branding.* (legacy)  <  agencies.brand_* columns
     *
     * The column-based fields (brand_logo_url, brand_primary_color, brand_support_email,
     * powered_by_visible) are the new source of truth as of v22p24 — PlatformController
     * writes there. The legacy settings.branding.* path is still honored so older saves
     * keep applying, but column values override when both exist.
     */
    public function show(Request $request): JsonResponse
    {
        $agencyId = $request->input('agency_id');
        $slug = $request->input('slug');
        $agency = null;

        if ($agencyId) {
            $agency = DB::table('agencies')->where('id', (int) $agencyId)->whereNull('deleted_at')->first();
        }
        if (!$agency && $slug) {
            $agency = DB::table('agencies')->where('slug', $slug)->whereNull('deleted_at')->first();
        }
        if (!$agency) {
            // No usable hint — use first/default agency
            $agency = DB::table('agencies')->whereNull('deleted_at')->orderBy('id')->first();
        }

        if (!$agency) {
            return response()->json([
                'branding' => self::DEFAULT_BRANDING + ['powered_by_visible' => true],
            ]);
        }

        $settings = json_decode($agency->settings ?? '{}', true) ?: [];
        $branding = array_merge(self::DEFAULT_BRANDING, $settings['branding'] ?? []);

        // v22p29: column-based brand fields override legacy settings.branding.*
        if (!empty($agency->brand_logo_url)) {
            $branding['logo_url'] = $agency->brand_logo_url;
        }
        if (!empty($agency->brand_primary_color)) {
            $branding['primary_color'] = $agency->brand_primary_color;
        }
        if (!empty($agency->brand_support_email)) {
            $branding['support_email'] = $agency->brand_support_email;
        }
        // Tenant may opt out of "Powered by Kiddietrac" footer (white-label add-on)
        $branding['powered_by_visible'] = (bool) ($agency->powered_by_visible ?? 1);

        // Always include the agency name as fallback for product_name
        if (empty($branding['product_name']) || $branding['product_name'] === 'Kiddietrac') {
            $branding['product_name'] = $agency->name;
        }

        return response()->json([
            'agency_id' => $agency->id,
            'agency_name' => $agency->name,
            'agency_slug' => $agency->slug,
            'branding' => $branding,
        ]);
    }

    /**
     * Agency-admin only — update branding for the admin's own agency
     * PUT /api/v1/admin/branding
     */
    public function update(Request $request): JsonResponse
    {
        $agencyId = DB::table('role_assignments')
            ->where('user_id', $request->user()->id)
            ->where('role', 'agency_admin')
            ->where('active', true)
            ->value('agency_id');

        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);

        $data = $request->validate([
            'product_name' => ['sometimes', 'string', 'max:80'],
            'tagline' => ['sometimes', 'nullable', 'string', 'max:200'],
            'primary_color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'accent_color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'logo_url' => ['sometimes', 'nullable', 'string', 'max:500'],
            'favicon_url' => ['sometimes', 'nullable', 'string', 'max:500'],
            'login_subtitle' => ['sometimes', 'nullable', 'string', 'max:300'],
            'support_email' => ['sometimes', 'nullable', 'email', 'max:180'],
            'email_from_name' => ['sometimes', 'nullable', 'string', 'max:80'],
        ]);

        $agency = DB::table('agencies')->where('id', $agencyId)->first();
        $settings = json_decode($agency->settings ?? '{}', true) ?: [];
        $settings['branding'] = array_merge($settings['branding'] ?? [], $data);

        DB::table('agencies')->where('id', $agencyId)->update([
            'settings' => json_encode($settings),
            'updated_at' => now(),
        ]);

        \App\Support\Audit::write([
            'user_id' => $request->user()->id,
            'action' => 'branding.updated',
            'entity_type' => 'agency',
            'entity_id' => $agencyId,
            'payload' => json_encode($data),
            'created_at' => now(),
        ]);

        return response()->json([
            'message' => 'Branding updated',
            'branding' => array_merge(self::DEFAULT_BRANDING, $settings['branding']),
        ]);
    }

    /**
     * Upload a logo file
     * POST /api/v1/admin/branding/logo
     */
    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate([
            'logo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'kind' => ['nullable', 'in:logo,favicon'],
            // Which tenant this logo belongs to. Honoured for a platform admin only.
            'agency_id' => ['nullable', 'integer'],
        ]);

        $isPlatform = DB::table('role_assignments')
            ->where('user_id', $request->user()->id)
            ->where('role', 'platform_admin')->where('active', true)->exists();

        /* A platform admin is editing somebody else's tenant, so they must be able to name
           it. Without this the upload resolved to the UPLOADER's agency — a 403 for a pure
           platform admin, or silently branding the wrong agency for one who also holds an
           agency_admin row. An agency admin still gets their own and cannot pass an id. */
        $agencyId = null;
        if ($isPlatform && $request->filled('agency_id')) {
            $agencyId = (int) $request->input('agency_id');
            if (! DB::table('agencies')->where('id', $agencyId)->whereNull('deleted_at')->exists()) {
                return response()->json(['message' => 'Agency not found'], 404);
            }
        }
        if (! $agencyId) {
            $agencyId = DB::table('role_assignments')
                ->where('user_id', $request->user()->id)
                ->where('role', 'agency_admin')
                ->where('active', true)
                ->value('agency_id');
        }

        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);

        $file = $request->file('logo');
        $kind = $request->input('kind', 'logo');
        $ext = strtolower($file->getClientOriginalExtension());
        $filename = "agency-{$agencyId}-{$kind}-" . Str::random(8) . ".{$ext}";
        $path = "branding/{$filename}";

        Storage::disk('public')->putFileAs('branding', $file, $filename);

        $url = '/storage/' . $path;

        // Update the agency settings
        $agency = DB::table('agencies')->where('id', $agencyId)->first();
        $settings = json_decode($agency->settings ?? '{}', true) ?: [];
        $settings['branding'] = $settings['branding'] ?? [];
        $settings['branding'][$kind === 'favicon' ? 'favicon_url' : 'logo_url'] = $url;

        $write = [
            'settings' => json_encode($settings),
            'logo_url' => $kind === 'logo' ? $url : ($agency->logo_url ?? null),
            'updated_at' => now(),
        ];
        /* brand_logo_url is the column the white-label header and the platform editor
           read. Only settings.branding.logo_url and the legacy logo_url were being
           written, so a logo uploaded here never showed up in the branded header. */
        if ($kind === 'logo') {
            $write['brand_logo_url'] = $url;
        }
        DB::table('agencies')->where('id', $agencyId)->update($write);

        // Uploading a logo is a change to the agency; it belongs in the same trail.
        try {
            \App\Support\Audit::write([
                'user_id' => $request->user()->id,
                'agency_id' => $agencyId,
                'action' => 'agency.' . $kind . '_uploaded',
                'entity_type' => 'agency',
                'entity_id' => $agencyId,
                'payload' => json_encode([
                    'agency' => $agency->name ?? null,
                    'kind' => $kind,
                    'url' => $url,
                    'from' => $kind === 'logo' ? ($agency->brand_logo_url ?? null) : null,
                ]),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) { /* never fail an upload over its audit row */ }

        return response()->json([
            'message' => ucfirst($kind) . ' uploaded',
            'url' => $url,
        ]);
    }
}
