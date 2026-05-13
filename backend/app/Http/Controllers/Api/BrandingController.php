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
     * GET /api/v1/branding?slug=acme  (slug optional; falls back to first agency)
     */
    public function show(Request $request): JsonResponse
    {
        $slug = $request->input('slug');
        $agency = null;

        if ($slug) {
            $agency = DB::table('agencies')->where('slug', $slug)->whereNull('deleted_at')->first();
        }

        if (!$agency) {
            // No slug or not found — use first/default agency
            $agency = DB::table('agencies')->whereNull('deleted_at')->orderBy('id')->first();
        }

        if (!$agency) {
            return response()->json(['branding' => self::DEFAULT_BRANDING]);
        }

        $settings = json_decode($agency->settings ?? '{}', true) ?: [];
        $branding = array_merge(self::DEFAULT_BRANDING, $settings['branding'] ?? []);

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

        DB::table('audit_logs')->insert([
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
        $agencyId = DB::table('role_assignments')
            ->where('user_id', $request->user()->id)
            ->where('role', 'agency_admin')
            ->where('active', true)
            ->value('agency_id');

        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);

        $request->validate([
            'logo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,svg', 'max:2048'],
            'kind' => ['nullable', 'in:logo,favicon'],
        ]);

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

        DB::table('agencies')->where('id', $agencyId)->update([
            'settings' => json_encode($settings),
            'logo_url' => $kind === 'logo' ? $url : ($agency->logo_url ?? null),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => ucfirst($kind) . ' uploaded',
            'url' => $url,
        ]);
    }
}
