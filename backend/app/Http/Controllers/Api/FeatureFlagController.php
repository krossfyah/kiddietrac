<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * v15: Feature flag management for platform admin + agency admin.
 *
 * Platform admin can set flags on any agency.
 * Agency admin can VIEW their own flags but not change them.
 */
final class FeatureFlagController extends Controller
{
    /**
     * Catalog of features that can be flagged. Add new ones here as
     * we ship features that should be gateable.
     */
    public const FEATURES = [
        'lesson_plans'      => ['label' => 'Lesson plans (HDLH)',          'plan_min' => 'starter'],
        'staff_scheduling'  => ['label' => 'Staff scheduling',             'plan_min' => 'starter'],
        'timesheets'        => ['label' => 'Timesheet CSV export',         'plan_min' => 'starter'],
        'certifications'    => ['label' => 'Staff certification tracking', 'plan_min' => 'starter'],
        'announcements'     => ['label' => 'Centre-wide announcements',    'plan_min' => 'free'],
        'chat'              => ['label' => 'Parent ↔ provider messaging',  'plan_min' => 'free'],
        'push_notifications'=> ['label' => 'Web push notifications',       'plan_min' => 'free'],
        'autopay'           => ['label' => 'Stripe autopay',               'plan_min' => 'growth'],
        'waitlist'          => ['label' => 'Waitlist management',          'plan_min' => 'starter'],
        'white_label'       => ['label' => 'White-label invoicing',        'plan_min' => 'growth'],
        'cwelcc_reporting'  => ['label' => 'CWELCC subsidy reporting',     'plan_min' => 'starter'],
        'analytics'         => ['label' => 'Agency analytics dashboard',   'plan_min' => 'growth'],
        'mrr_dashboard'     => ['label' => 'MRR / billing dashboard',      'plan_min' => 'enterprise'],
        'multi_centre'      => ['label' => 'Multiple centres per agency',  'plan_min' => 'starter'],
    ];

    /**
     * Plan tiers (informational — actual gating is per-flag).
     */
    public const PLANS = [
        'free'       => ['label' => 'Free',       'monthly_cents' => 0],
        'starter'    => ['label' => 'Starter',    'monthly_cents' => 4900],
        'growth'     => ['label' => 'Growth',     'monthly_cents' => 14900],
        'enterprise' => ['label' => 'Enterprise', 'monthly_cents' => 34900],
    ];

    /**
     * GET /api/v1/admin/features/catalog
     * Returns the full catalog of features + plans.
     */
    public function catalog(): JsonResponse
    {
        return response()->json([
            'features' => self::FEATURES,
            'plans'    => self::PLANS,
        ]);
    }

    /**
     * GET /api/v1/admin/agencies/{id}/features
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $agency = DB::table('agencies')->where('id', $id)->first();
        if (! $agency) return response()->json(['message' => 'Agency not found'], 404);

        $this->authorizeAccess($request, $agency);

        $flags = [];
        if (! empty($agency->feature_flags)) {
            $flags = json_decode($agency->feature_flags, true) ?: [];
        }

        // Compute effective flags: every feature in catalog gets an explicit value
        // (either from DB or defaulted to "on" because unset = allow)
        $effective = [];
        foreach (self::FEATURES as $key => $meta) {
            $effective[$key] = array_key_exists($key, $flags) ? (bool) $flags[$key] : true;
        }

        // Raw flags as stored (for the admin UI's "Auto / On / Off" tri-state)
        $rawFlags = $flags;

        return response()->json([
            'agency_id'   => $agency->id,
            'plan_code'   => $agency->plan_code ?? 'free',
            'plan_amount_cents' => (int) ($agency->plan_amount_cents ?? 0),
            'plan_currency'     => $agency->plan_currency ?? 'CAD',
            'billing_status'    => $agency->billing_status ?? 'trial',
            'billing_starts_at' => $agency->billing_starts_at ?? null,
            'flags'         => $effective,   // effective {feature: bool}
            'feature_flags' => $rawFlags,    // raw {feature: bool} (only explicit ones)
            'catalog'       => self::FEATURES,
            'branding'      => [
                'brand_name'          => $agency->name ?? null,
                'brand_logo_url'      => $agency->brand_logo_url ?? null,
                'brand_primary_color' => $agency->brand_primary_color ?? null,
                'brand_support_email' => $agency->brand_support_email ?? null,
                'brand_bank_info'     => $agency->brand_bank_info ?? null,
                'brand_address'       => $this->agencySetting($agency, 'brand_address'),
                'brand_privacy_url'   => $this->agencySetting($agency, 'brand_privacy_url'),
                'brand_terms_url'     => $this->agencySetting($agency, 'brand_terms_url'),
                'powered_by_visible'  => isset($agency->powered_by_visible) ? (int) $agency->powered_by_visible : 1,
            ],
        ]);
    }

    /**
     * Read a free-form branding value stored in the agency's settings JSON
     * (used for fields that have no dedicated column — brand_address,
     * brand_privacy_url, brand_terms_url).
     */
    private function agencySetting($agency, string $key): ?string
    {
        if (empty($agency->settings)) return null;
        $s = json_decode((string) $agency->settings, true);
        return (is_array($s) && !empty($s[$key])) ? (string) $s[$key] : null;
    }

    /**
     * PATCH /api/v1/admin/agencies/{id}/features
     * Body: { flags: { feature_key: bool, ... } }   (partial — merges with existing)
     *       optional: { plan_code, plan_amount_cents, billing_status }
     *
     * Only platform admins can change flags. Agency admins can read but not write.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $agency = DB::table('agencies')->where('id', $id)->first();
        if (! $agency) return response()->json(['message' => 'Agency not found'], 404);

        $isPlatform = $this->isPlatformAdmin($user);
        $isAgencyOwner = (isset($user->agency_id) && (int) $user->agency_id === (int) $agency->id
            && in_array($user->primary_role ?? '', ['agency_admin', 'centre_director']));

        if (! $isPlatform && ! $isAgencyOwner) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            // Platform-admin-only fields
            'flags'             => ['nullable', 'array'],
            'flags.*'           => ['nullable', 'boolean'],
            'feature_flags'     => ['nullable', 'array'],   // alias accepted from new UI
            'feature_flags.*'   => ['nullable', 'boolean'],
            'plan_code'         => ['nullable', 'string', 'in:free,starter,growth,enterprise'],
            'plan_amount_cents' => ['nullable', 'integer', 'min:0'],
            'billing_status'    => ['nullable', 'string', 'in:trial,active,past_due,cancelled'],
            // Agency-owner-editable branding fields
            'brand_logo_url'      => ['nullable', 'string', 'max:500'],
            'brand_primary_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'brand_support_email' => ['nullable', 'email', 'max:255'],
            'brand_bank_info'     => ['nullable', 'string', 'max:2000'],
            'brand_address'       => ['nullable', 'string', 'max:500'],
            'brand_privacy_url'   => ['nullable', 'string', 'max:500'],
            'brand_terms_url'     => ['nullable', 'string', 'max:500'],
            'powered_by_visible'  => ['nullable', 'integer', 'in:0,1'],
        ]);

        $update = [];

        // ─── Platform-admin-only writes ────────────────────────────
        if ($isPlatform) {
            $flagInput = $data['feature_flags'] ?? $data['flags'] ?? null;
            if (is_array($flagInput)) {
                $existing = [];
                if (! empty($agency->feature_flags)) {
                    $existing = json_decode($agency->feature_flags, true) ?: [];
                }
                // Allow caller to send {} to mean "wipe all overrides" — replace entirely
                // when they sent a complete payload from the UI.
                $existing = [];
                foreach ($flagInput as $k => $v) {
                    if (! array_key_exists($k, self::FEATURES)) continue;
                    $existing[$k] = (bool) $v;
                }
                $update['feature_flags'] = json_encode($existing);
            }

            if (isset($data['plan_code'])) {
                $update['plan_code'] = $data['plan_code'];
                if (! isset($data['plan_amount_cents']) && isset(self::PLANS[$data['plan_code']])) {
                    $update['plan_amount_cents'] = self::PLANS[$data['plan_code']]['monthly_cents'];
                }
            }
            if (isset($data['plan_amount_cents'])) {
                $update['plan_amount_cents'] = $data['plan_amount_cents'];
            }
            if (isset($data['billing_status'])) {
                $update['billing_status'] = $data['billing_status'];
                if ($data['billing_status'] === 'cancelled' && empty($agency->cancelled_at)) {
                    $update['cancelled_at'] = now();
                }
                if ($data['billing_status'] === 'active' && empty($agency->billing_starts_at)) {
                    $update['billing_starts_at'] = now()->toDateString();
                }
            }
        } else {
            // Agency owner tried to set flag/plan/status fields — silently ignore them
            // but reject the request if those were the ONLY thing they sent (to avoid
            // surprising "Saved" responses with no effect).
            $sentPrivileged = isset($data['flags']) || isset($data['feature_flags'])
                || isset($data['plan_code']) || isset($data['plan_amount_cents'])
                || isset($data['billing_status']);
            if ($sentPrivileged) {
                $brandKeys = ['brand_logo_url','brand_primary_color','brand_support_email','brand_bank_info','brand_address','brand_privacy_url','brand_terms_url','powered_by_visible'];
                $sentBrand = false;
                foreach ($brandKeys as $bk) { if (array_key_exists($bk, $data)) { $sentBrand = true; break; } }
                if (! $sentBrand) {
                    return response()->json(['message' => 'Forbidden — only platform admins can change plans or flags'], 403);
                }
            }
        }

        // ─── Branding fields (both roles) ──────────────────────────
        foreach (['brand_logo_url', 'brand_primary_color', 'brand_support_email', 'brand_bank_info'] as $bk) {
            if (array_key_exists($bk, $data)) {
                $update[$bk] = $data[$bk] !== '' ? $data[$bk] : null;
            }
        }
        if (array_key_exists('powered_by_visible', $data)) {
            $update['powered_by_visible'] = (int) $data['powered_by_visible'];
        }

        // brand_address / brand_privacy_url / brand_terms_url have no columns —
        // store them in the settings JSON (v22p88: address; v22p90: privacy/terms).
        $jsonKeys = ['brand_address', 'brand_privacy_url', 'brand_terms_url'];
        $touchesJson = false;
        foreach ($jsonKeys as $jk) { if (array_key_exists($jk, $data)) { $touchesJson = true; break; } }
        if ($touchesJson) {
            $settings = [];
            if (! empty($agency->settings)) { $x = json_decode($agency->settings, true); if (is_array($x)) $settings = $x; }
            foreach ($jsonKeys as $jk) {
                if (array_key_exists($jk, $data)) {
                    $settings[$jk] = $data[$jk] !== '' ? $data[$jk] : null;
                }
            }
            $update['settings'] = json_encode($settings);
        }

        if (empty($update)) {
            return response()->json(['message' => 'No changes'], 200);
        }

        DB::table('agencies')->where('id', $id)->update($update);

        // Audit trail (if audit_logs table exists)
        try {
            DB::table('audit_logs')->insert([
                'user_id'    => $user->id,
                'centre_id'  => null,
                'event'      => 'agency_settings_updated',
                'data'       => json_encode(['agency_id' => $id, 'changes' => array_keys($update), 'by_role' => $isPlatform ? 'platform_admin' : 'agency_admin']),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) { /* audit is best-effort */ }

        return $this->show($request, $id);
    }

    /**
     * Was the request made by someone with the right to read flags for this agency?
     */
    private function authorizeAccess(Request $request, $agency): void
    {
        $user = $request->user();
        if (! $user) abort(401);
        if ($this->isPlatformAdmin($user)) return;
        // Agency admins of THIS agency can read
        if (isset($user->agency_id) && $user->agency_id == $agency->id
            && in_array($user->primary_role ?? '', ['agency_admin', 'centre_director'])) {
            return;
        }
        abort(403, 'Not authorized to view this agency');
    }

    private function isPlatformAdmin($user): bool
    {
        // Platform admin = role 'platform_admin' OR a specific user_id from .env
        if (($user->primary_role ?? null) === 'platform_admin') return true;
        $superId = (int) env('PLATFORM_ADMIN_USER_ID', 1);
        if ($superId && (int) $user->id === $superId) return true;
        return false;
    }
}
