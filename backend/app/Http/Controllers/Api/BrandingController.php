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

        // Looked up by NUMBER: only a member of that agency is told who it is (see the end of this method).
        $byId = false;
        if ($agencyId) {
            $agency = DB::table('agencies')->where('id', (int) $agencyId)->whereNull('deleted_at')->first();
            $byId = (bool) $agency;
        }
        if (!$agency && $slug) {
            $agency = DB::table('agencies')->where('slug', $slug)->whereNull('deleted_at')->first();
            /* A reseller's own app build opens with ?tenant=<reseller slug> (2026-10-21):
               brand the pre-login screens as the reseller, with no agency attached. */
            if (!$agency) {
                $reseller = DB::table('resellers')->where('slug', $slug)->where('status', 'active')->first();
                if ($reseller) {
                    $theme = \App\Support\Brand::forReseller((int) $reseller->id);
                    return response()->json([
                        'agency_id' => null, 'reseller_id' => (int) $reseller->id, 'reseller_slug' => $reseller->slug,
                        'branding' => self::fromTheme($theme), 'theme' => $theme,
                    ]);
                }
            }
        }
        /* NO HINT NEVER MEANS "THE FIRST AGENCY" (2026-10-21). It used to fall back to the
           lowest agency id, so any screen calling /branding bare (the forms screen, the
           admin branding tab) could be handed another tenant's logo and name. A signed-in
           caller gets their own agency; anyone else gets KiddieTrac's own brand. */
        if (!$agency && !$agencyId && !$slug) {
            $own = $this->callerAgencyId($request);
            if ($own) {
                $agency = DB::table('agencies')->where('id', $own)->whereNull('deleted_at')->first();
            }
        }

        if (!$agency) {
            $theme = \App\Support\Brand::forAgency(null);
            return response()->json([
                'agency_id' => null,
                'branding' => self::fromTheme($theme),
                'theme' => $theme,
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

        // The full resolved theme (reseller -> agency -> KiddieTrac), see App\Support\Brand.
        $theme = \App\Support\Brand::forAgency((int) $agency->id);
        if ($theme['white_label']) {
            $branding['powered_by_visible'] = false;
            $branding['product_name'] = $theme['name'];
            $branding['logo_url'] = $theme['logo_wordmark_url'];
            $branding['primary_color'] = $theme['primary_color'];
        }

        /* NO CUSTOMER LIST BY COUNTING (2026-11-05 leak sweep). ?agency_id= answers anyone, and counted
           1, 2, 3... it named every agency on the platform with its slug, country and support address.
           The callers that ask by number are the signed-in app's own (the launch scene, the dashboard's
           first frame, the brand loader, the admin branding tab) and all they need without a session is
           the look: colours, logo, white-label flag. A caller whose bearer token belongs to this agency
           (or a platform admin) still gets the full answer. Lookups by slug are unchanged: a slug is the
           address a white-label app is built for, so whoever holds it already knows the brand. */
        if ($byId && ! $this->callerMayKnow($request, (int) $agency->id)) {
            // The theme names the agency and its reseller too; the brand it wears (name, logo) stays.
            unset($theme['agency_name'], $theme['reseller_name'], $theme['reseller_id']);
            return response()->json([
                'agency_id' => $agency->id,
                'branding' => array_merge(self::fromTheme($theme), [
                    'primary_color' => $branding['primary_color'], 'accent_color' => $branding['accent_color'],
                    'logo_url' => $theme['white_label'] ? $branding['logo_url'] : null,
                    'powered_by_visible' => $branding['powered_by_visible'],
                ]),
                'theme' => $theme,
            ]);
        }

        return response()->json([
            'agency_id' => $agency->id,
            'agency_name' => $agency->name,
            'agency_slug' => $agency->slug,
            'country' => $agency->country ?: ($settings['country'] ?? null),
            'reseller_id' => $theme['reseller_id'],
            'branding' => $branding,
            // May this agency set its own logo/colours/banner (2026-10-21)?
            'agency_may_override' => \App\Support\Brand::agencyMayOverride((int) $agency->id),
            'theme' => $theme,
        ]);
    }

    /**
     * GET /branding/boot.js?slug=<tenant> - THE BRAND BEFORE THE FIRST PAINT (2026-10-30, Anthony: "the white label on the
     * emulator should be a full white label with no kiddietrac logo during bootup replaced with white label logo and colour
     * scheme"). A white-label app opens app.kiddietrac.com/?tenant=<slug> (android/whitelabel flavors, the website demo
     * phone's Starlight app). Its sign-in page could only brand itself from a previous visit's cache, so a first launch -
     * or one after another brand's - played KiddieTrac's launch intro, logo and slogan until /branding answered.
     * index.html now loads this as a parser-blocking script in <head> when it has a tenant and no cached brand for it: it
     * writes the same kt_branding / kt_brand_a<id> entries branding-loader.js writes, so the launch scene, the sign-in
     * scheme and the logo all read the partner's brand from frame one. Not white-label (or unknown): it writes nothing.
     */
    public function bootJs(Request $request)
    {
        $slug = strtolower(trim((string) $request->query('slug', '')));
        $js = '/* no brand */';
        if (preg_match('/^[a-z0-9-]{1,80}$/', $slug)) {
            $data = $this->show(new Request(['slug' => $slug]))->getData(true);
            $theme = $data['theme'] ?? null;
            if (is_array($theme) && ! empty($theme['white_label'])) {
                $entry = [
                    'branding' => $data['branding'] ?? null, 'theme' => $theme, 'agency_id' => $data['agency_id'] ?? null,
                    'agency_slug' => $data['agency_slug'] ?? null, 'reseller_slug' => $data['reseller_slug'] ?? null,
                    'country' => $data['country'] ?? null, 'agency_name' => $data['agency_name'] ?? null,
                ];
                $json = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                $js = '(function(){try{var e=' . $json . ';e.expires=Date.now()+300000;var L=localStorage;'
                    . 'L.setItem("kt_branding",JSON.stringify(e));'
                    . 'if(e.agency_id)L.setItem("kt_brand_a"+e.agency_id,JSON.stringify({theme:e.theme,at:Date.now()}));'
                    . 'window.KT_BOOT_BRAND=e;}catch(x){}})();';
            }
        }
        return response($js, 200, ['Content-Type' => 'application/javascript; charset=utf-8', 'Cache-Control' => 'public, max-age=300']);
    }

    /** The legacy `branding` shape, filled from a resolved theme. */
    private static function fromTheme(array $t): array
    {
        return array_merge(self::DEFAULT_BRANDING, [
            'product_name' => $t['name'],
            'primary_color' => $t['primary_color'],
            'accent_color' => $t['accent_color'],
            'logo_url' => $t['white_label'] ? $t['logo_wordmark_url'] : null,
            'favicon_url' => $t['favicon_url'],
            'support_email' => $t['support_email'],
            'powered_by_visible' => ! $t['white_label'],
        ]);
    }

    /** Does the bearer token on this public request belong to a member of the agency (or a platform admin)? */
    private function callerMayKnow(Request $request, int $agencyId): bool
    {
        try {
            $token = $request->bearerToken();
            if (! $token) return false;
            $pat = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
            $user = $pat ? $pat->tokenable : null;
            if (! $user) return false;
            return DB::table('role_assignments')->where('user_id', $user->id)->where('active', true)
                ->where(function ($q) use ($agencyId) {
                    $q->where('role', 'platform_admin')->orWhere('agency_id', $agencyId)
                      ->orWhereIn('centre_id', DB::table('centres')->where('agency_id', $agencyId)->pluck('id'));
                })->exists()
                || DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')
                    ->join('centres as c', 'c.id', '=', 'f.centre_id')
                    ->where('g.user_id', $user->id)->where('c.agency_id', $agencyId)->whereNull('f.deleted_at')->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** The agency of whoever sent a bearer token (this route is public, so read it by hand). */
    private function callerAgencyId(Request $request): ?int
    {
        try {
            $token = $request->bearerToken();
            if (! $token) return null;
            $pat = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
            $user = $pat ? $pat->tokenable : null;
            if (! $user) return null;
            $header = (int) $request->header('X-Active-Agency-Id');
            if ($header) {
                $isPlatform = DB::table('role_assignments')->where('user_id', $user->id)
                    ->where('role', 'platform_admin')->where('active', true)->exists();
                $member = $isPlatform || DB::table('role_assignments')->where('user_id', $user->id)->where('active', true)
                    ->where(function ($q) use ($header) {
                        $q->where('agency_id', $header)
                          ->orWhereIn('centre_id', DB::table('centres')->where('agency_id', $header)->pluck('id'));
                    })->exists();
                if ($member) return $header;
            }
            return \App\Support\AuditScope::ownAgency((int) $user->id);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Agency-admin only — update branding for the admin's own agency
     * PUT /api/v1/admin/branding
     */
    public function update(Request $request): JsonResponse
    {
        /* The agency being worked in (2026-10-21). This took the FIRST agency the person
           administers, so someone who admins several (a Reseller) saved one agency's brand
           onto another. The active agency is used when they administer it; otherwise their
           own, as before. */
        $admins = DB::table('role_assignments')
            ->where('user_id', $request->user()->id)
            ->where('role', 'agency_admin')
            ->where('active', true)
            ->whereNotNull('agency_id')
            ->pluck('agency_id')->map(fn ($v) => (int) $v)->all();
        $hdr = (int) $request->header('X-Active-Agency-Id');
        $agencyId = ($hdr && in_array($hdr, $admins, true)) ? $hdr : ($admins[0] ?? null);

        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);

        $hero = $request->validate([
            'hero_from' => ['sometimes', 'nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'hero_via' => ['sometimes', 'nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'hero_to' => ['sometimes', 'nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'hero_angle' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:360'],
            'soft_tint' => ['sometimes', 'nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);
        // The banner gradient is part of the agency's own look: only where it may show one.
        if ($hero && ! \App\Support\Brand::agencyMayOverride((int) $agencyId)) {
            return response()->json(['message' => 'Your banner colours are set by your provider.'], 403);
        }

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
        $data += $hero;
        $settings['branding'] = array_merge($settings['branding'] ?? [], $data);
        // A cleared banner colour goes back to the default rather than staying as null.
        foreach (['hero_from', 'hero_via', 'hero_to', 'hero_angle', 'soft_tint'] as $k) {
            if (array_key_exists($k, $settings['branding']) && ($settings['branding'][$k] === null || $settings['branding'][$k] === '')) {
                unset($settings['branding'][$k]);
            }
        }

        DB::table('agencies')->where('id', $agencyId)->update([
            'settings' => json_encode($settings),
            'updated_at' => now(),
        ]);
        \App\Support\Brand::forget();

        \App\Support\Audit::write([
            'user_id' => $request->user()->id,
            'action' => 'branding.updated',
            'agency_id' => $agencyId,
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
