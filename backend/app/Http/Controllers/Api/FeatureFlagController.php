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
    /* THE CATALOG IS THE CONTRACT (rewritten 2026-09-17).
     *
     * The v15 list below had fourteen entries and the portal has since grown to 134
     * screens, so most of what an agency can be sold could not be switched off, and
     * several of the entries named things that no longer exist under those words.
     * Anthony: "feature flags section make sure this is updated with the current
     * options and the wiring works".
     *
     * `hashes` is the new part and the load-bearing one: the portal screens a feature
     * owns. The shell hides those nav items when the flag is off, and it is what makes a
     * flag testable — switch it off, and you can SEE which screens went. A flag with no
     * hashes gates an API or a behaviour rather than a screen, and says so.
     *
     * Every original key is kept with its original meaning. A stored flag is a decision
     * somebody made about a paying agency; renaming a key silently re-enables whatever
     * it used to switch off.
     *
     * DEFAULT IS ON. An absent flag means allowed — see CheckFeatureFlag. Adding a
     * feature here therefore changes nothing for anybody until it is explicitly
     * switched off for an agency.
     */
    public const FEATURES = [
        // ── Daily care ────────────────────────────────────────────────────────────
        'lesson_plans'      => ['label' => 'Lesson plans & curriculum',    'plan_min' => 'starter',    'group' => 'Daily care',
            'hashes' => ['lesson-plans', 'curriculum', 'hdlh-gaps']],
        'observations'      => ['label' => 'Observations & portfolios',    'plan_min' => 'starter',    'group' => 'Daily care',
            'hashes' => ['observations', 'photos', 'videos', 'photo-tagging']],
        'daily_log'         => ['label' => 'Daily log & care records',     'plan_min' => 'free',       'group' => 'Daily care',
            'hashes' => ['care-log', 'menu']],
        'attendance'        => ['label' => 'Check-in, attendance & ratios', 'plan_min' => 'free',      'group' => 'Daily care',
            'hashes' => ['checkin', 'attendance-pattern', 'room-ratios', 'late-pickups', 'late-events']],
        'field_trips'       => ['label' => 'Walks, field trips & GPS',     'plan_min' => 'starter',    'group' => 'Daily care',
            'hashes' => ['field-trips', 'trip-gps', 'bus-routes', 'zones']],
        'conferences'       => ['label' => 'Parent conferences',           'plan_min' => 'starter',    'group' => 'Daily care',
            'hashes' => ['conferences']],

        // ── Health & safety ───────────────────────────────────────────────────────
        'immunizations'     => ['label' => 'Immunization tracking',        'plan_min' => 'starter',    'group' => 'Health & safety',
            'hashes' => ['immunizations']],
        'medications'       => ['label' => 'Medication administration',    'plan_min' => 'starter',    'group' => 'Health & safety',
            'hashes' => ['medications']],
        'incidents'         => ['label' => 'Incident reporting',           'plan_min' => 'free',       'group' => 'Health & safety',
            'hashes' => ['incidents']],
        'allergy_alerts'    => ['label' => 'Allergy alerts',               'plan_min' => 'free',       'group' => 'Health & safety',
            'hashes' => ['allergy-alerts']],
        'wellness'          => ['label' => 'Wellness screening & digest',  'plan_min' => 'starter',    'group' => 'Health & safety',
            'hashes' => ['wellness-digest']],

        // ── Family engagement ─────────────────────────────────────────────────────
        'chat'              => ['label' => 'Parent ↔ provider messaging',  'plan_min' => 'free',       'group' => 'Family engagement',
            'hashes' => ['chat', 'messages']],
        'announcements'     => ['label' => 'Announcements & news',         'plan_min' => 'free',       'group' => 'Family engagement',
            'hashes' => ['announcements']],
        'push_notifications' => ['label' => 'Push notifications',          'plan_min' => 'free',       'group' => 'Family engagement',
            'hashes' => []],   // no screen — gates the push transport itself
        'awards'            => ['label' => 'Child awards & certificates',  'plan_min' => 'starter',    'group' => 'Family engagement',
            'hashes' => ['awards']],
        'parent_feedback'   => ['label' => 'Parent feedback & NPS',        'plan_min' => 'starter',    'group' => 'Family engagement',
            'hashes' => ['parent-feedback', 'nps']],
        'sms'               => ['label' => 'SMS & voice announcements',    'plan_min' => 'growth',     'group' => 'Family engagement',
            'hashes' => ['sms', 'sms-settings']],

        // ── Staff ─────────────────────────────────────────────────────────────────
        'staff_scheduling'  => ['label' => 'Staff scheduling',             'plan_min' => 'starter',    'group' => 'Staff',
            'hashes' => ['schedule', 'staff-calendar', 'substitutes', 'room-rotations', 'educator-rooms']],
        'timesheets'        => ['label' => 'Time clock & timesheets',      'plan_min' => 'starter',    'group' => 'Staff',
            'hashes' => ['timesheets', 'time-clock', 'time-off']],
        'payroll'           => ['label' => 'Payroll documents & payslips', 'plan_min' => 'growth',     'group' => 'Staff',
            'hashes' => ['payroll']],
        'certifications'    => ['label' => 'Certifications & background checks', 'plan_min' => 'starter', 'group' => 'Staff',
            'hashes' => ['certifications', 'background-checks']],
        'tasks'             => ['label' => 'Task assignment',              'plan_min' => 'free',       'group' => 'Staff',
            'hashes' => ['tasks', 'my-tasks']],
        'home_visits'       => ['label' => 'Home visits & inspection forms', 'plan_min' => 'starter',  'group' => 'Staff',
            'hashes' => ['home-visits', 'home-visit-reports', 'new-home-visit', 'hcc-forms', 'inspection']],

        // ── Billing & payments ────────────────────────────────────────────────────
        'billing'           => ['label' => 'Invoicing & billing',          'plan_min' => 'starter',    'group' => 'Billing & payments',
            'hashes' => ['billing', 'bulk-invoices', 'billing-schedule', 'billing-settings',
                'payment-plans', 'tuition-increases', 'refunds', 'account-ledgers']],
        'autopay'           => ['label' => 'Card & bank autopay',          'plan_min' => 'growth',     'group' => 'Billing & payments',
            'hashes' => ['payment-providers']],
        'expenses'          => ['label' => 'Expenses, suppliers & POs',    'plan_min' => 'growth',     'group' => 'Billing & payments',
            'hashes' => ['expenses']],
        'accounting_sync'   => ['label' => 'Accounting export & QuickBooks', 'plan_min' => 'growth',   'group' => 'Billing & payments',
            'hashes' => ['external-billing', 'quickbooks']],
        'white_label'       => ['label' => 'White-label branding & site',  'plan_min' => 'growth',     'group' => 'Billing & payments',
            'hashes' => ['admin-branding', 'marketing-site']],

        // ── Compliance & reporting ────────────────────────────────────────────────
        'forms'             => ['label' => 'Forms, e-signatures & documents', 'plan_min' => 'starter', 'group' => 'Compliance & reporting',
            'hashes' => ['forms', 'forms-manager', 'my-forms', 'edocuments', 'signed-docs',
                'doc-workflows', 'document-templates']],
        'compliance'        => ['label' => 'Compliance dashboard',         'plan_min' => 'starter',    'group' => 'Compliance & reporting',
            'hashes' => ['compliance']],
        'cwelcc_reporting'  => ['label' => 'CWELCC & CACFP reporting',     'plan_min' => 'starter',    'group' => 'Compliance & reporting',
            'hashes' => ['cwelcc', 'cacfp']],
        'reports'           => ['label' => 'Reports & scheduled exports',  'plan_min' => 'starter',    'group' => 'Compliance & reporting',
            'hashes' => ['reports']],
        'audit_log'         => ['label' => 'Audit log',                    'plan_min' => 'starter',    'group' => 'Compliance & reporting',
            'hashes' => ['audit-logs']],
        'data_retention'    => ['label' => 'Data retention & archiving',   'plan_min' => 'growth',     'group' => 'Compliance & reporting',
            'hashes' => ['data-retention']],

        // ── Growth ────────────────────────────────────────────────────────────────
        'waitlist'          => ['label' => 'Waitlist & enrolment pipeline', 'plan_min' => 'starter',   'group' => 'Growth',
            'hashes' => ['synced-waitlist', 'reenrollment', 'renewals']],
        'tours'             => ['label' => 'Tours & enquiries',            'plan_min' => 'starter',    'group' => 'Growth',
            'hashes' => ['tours']],
        'marketing'         => ['label' => 'Marketing & drip campaigns',   'plan_min' => 'growth',     'group' => 'Growth',
            'hashes' => ['marketing-campaigns', 'drip-campaigns']],
        /* The hashes here were checked against the nav the portal actually builds, not
           guessed from the names: `sales-plans`, `sales-lead` and `sales-chat` are real
           screens and `sales-invoices` belongs to the platform's own billing. Testing on
           Test Agency is what caught it — the flag was off and the menu still had six
           sales items in it. */
        'sales_crm'         => ['label' => 'Sales CRM',                    'plan_min' => 'growth',     'group' => 'Growth',
            'hashes' => ['sales', 'sales-leads', 'sales-lead', 'sales-new', 'sales-followups',
                'sales-plans', 'sales-demo', 'sales-chat', 'sales-invoices']],
        'analytics'         => ['label' => 'Analytics & forecasting',      'plan_min' => 'growth',     'group' => 'Growth',
            'hashes' => ['forecast', 'engagement', 'anomalies', 'retention']],
        'ai_tools'          => ['label' => 'AI tools & report cards',      'plan_min' => 'growth',     'group' => 'Growth',
            'hashes' => ['ai-churn', 'ai-docs', 'report-cards', 'digest-status']],

        // ── Platform ──────────────────────────────────────────────────────────────
        'multi_centre'      => ['label' => 'Multiple centres per agency',  'plan_min' => 'starter',    'group' => 'Platform',
            'hashes' => ['admin-centres']],
        'mrr_dashboard'     => ['label' => 'MRR / billing dashboard',      'plan_min' => 'enterprise', 'group' => 'Platform',
            'hashes' => ['admin-mrr']],
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
     * EVERY feature, resolved to a yes/no for one agency. ONE definition, used by the
     * admin screen, the API gate (CheckFeatureFlag) and the portal shell — three places
     * that were each free to disagree about what "off" meant, and a flag that hides a
     * menu but not its endpoint is worse than no flag.
     *
     * Absent means ALLOWED, deliberately: agencies have been using these features for
     * months without a flags row, and a default of "deny" would switch the product off
     * for all of them the moment this shipped.
     *
     * @return array<string,bool>
     */
    public static function effectiveFor(?int $agencyId): array
    {
        $stored = [];
        if ($agencyId) {
            $raw = DB::table('agencies')->where('id', $agencyId)->value('feature_flags');
            if ($raw) {
                try { $stored = json_decode((string) $raw, true) ?: []; } catch (\Throwable $e) { $stored = []; }
            }
        }

        $out = [];
        foreach (self::FEATURES as $key => $meta) {
            $out[$key] = array_key_exists($key, $stored) ? (bool) $stored[$key] : true;
        }

        return $out;
    }

    /**
     * The nav hashes an agency may NOT see, from the same source of truth.
     *
     * The shell asks for this rather than reimplementing the catalog in JavaScript —
     * a second copy of the mapping is a second thing to forget when a screen is added.
     *
     * @return list<string>
     */
    public static function hiddenHashesFor(?int $agencyId): array
    {
        $hidden = [];
        foreach (self::effectiveFor($agencyId) as $key => $on) {
            if ($on) {
                continue;
            }
            foreach (self::FEATURES[$key]['hashes'] ?? [] as $h) {
                $hidden[] = $h;
            }
        }

        return array_values(array_unique($hidden));
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
                /* THE INVOICE STYLE. It belongs with the logo, the colour and the
                   business address — an invoice's look IS the agency's brand, and
                   Anthony asked for it here rather than buried in a reminders tab:
                   "there should be a drop down to choose invoice style types for brand
                   settings". Served with the catalogue so the screen never keeps its own
                   copy of the options. (2026-09-17) */
                'invoice_template'    => \App\Services\InvoiceDocument::templateFor((int) $agency->id),
                'invoice_templates'   => \App\Services\InvoiceDocument::templates(),
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
            'invoice_template'    => ['nullable', 'string', 'in:' . implode(',', array_keys(\App\Services\InvoiceDocument::templates()))],
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
        $jsonKeys = ['brand_address', 'brand_privacy_url', 'brand_terms_url', 'invoice_template'];
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
            \App\Support\Audit::write([
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
