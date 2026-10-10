<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * v22p74 — per-agency billing currency.
 * Lets each agency admin choose the currency used for invoices/billing display.
 */
final class CurrencyController extends Controller
{
    private const SUPPORTED = [
        'CAD' => ['symbol' => '$',  'label' => 'Canadian Dollar'],
        'USD' => ['symbol' => '$',  'label' => 'US Dollar'],
        'GBP' => ['symbol' => '£',  'label' => 'British Pound'],
        'EUR' => ['symbol' => '€',  'label' => 'Euro'],
        'AUD' => ['symbol' => '$',  'label' => 'Australian Dollar'],
        'NZD' => ['symbol' => '$',  'label' => 'New Zealand Dollar'],
    ];

    /**
     * v22p83 — country compliance packs. Choosing a country sets the agency's
     * billing currency + locale and surfaces the regulatory frameworks that
     * apply (childcare licensing, privacy law, payment-card security, tax).
     * Stored as `country` inside the agency `settings` JSON (no schema change).
     * Frameworks are indicative defaults; agencies remain responsible for their
     * own legal compliance.
     */
    private const COUNTRIES = [
        'CA' => [
            'name' => 'Canada', 'flag' => '🇨🇦', 'currency' => 'CAD', 'locale' => 'en-CA',
            'compliance' => [
                ['cat' => 'Childcare', 'code' => 'Provincial licensing', 'label' => 'Provincial childcare licensing (e.g. Ontario CCEYA) & CWELCC', 'desc' => 'Ratios, supervision, RECE staffing and the $10/day CWELCC subsidy program.'],
                ['cat' => 'Privacy',   'code' => 'PIPEDA',  'label' => 'PIPEDA — Personal Information Protection', 'desc' => 'Consent, safeguarding and retention rules for family & child data.'],
                ['cat' => 'Payments',  'code' => 'PCI-DSS', 'label' => 'PCI-DSS — card payment security', 'desc' => 'Card data is tokenised by the payment processor; the portal never stores PANs.'],
                ['cat' => 'Tax',       'code' => 'CRA',     'label' => 'CRA — child-care expense receipts (GST/HST)', 'desc' => 'Year-end tax receipts and HST/GST handling for billing.'],
            ],
        ],
        'US' => [
            'name' => 'United States', 'flag' => '🇺🇸', 'currency' => 'USD', 'locale' => 'en-US',
            'compliance' => [
                ['cat' => 'Childcare', 'code' => 'State CCDF', 'label' => 'State licensing & CCDF ratios', 'desc' => 'State-by-state licensing, ratios and the federal Child Care & Development Fund.'],
                ['cat' => 'Privacy',   'code' => 'COPPA',   'label' => "COPPA — children's online privacy", 'desc' => 'Parental consent for collecting data about children under 13.'],
                ['cat' => 'Payments',  'code' => 'PCI-DSS', 'label' => 'PCI-DSS — card payment security', 'desc' => 'Card data is tokenised by the payment processor; the portal never stores PANs.'],
                ['cat' => 'Tax',       'code' => 'IRS',     'label' => 'IRS — Form W-10 / dependent-care receipts', 'desc' => 'Provider tax ID disclosure and year-end dependent-care statements.'],
            ],
        ],
        'GB' => [
            'name' => 'United Kingdom', 'flag' => '🇬🇧', 'currency' => 'GBP', 'locale' => 'en-GB',
            'compliance' => [
                ['cat' => 'Childcare', 'code' => 'Ofsted EYFS', 'label' => 'Ofsted — Early Years Foundation Stage', 'desc' => 'EYFS welfare requirements, ratios and safeguarding.'],
                ['cat' => 'Privacy',   'code' => 'UK GDPR', 'label' => 'UK GDPR / Data Protection Act 2018', 'desc' => 'Lawful basis, consent and data-subject rights for family records.'],
                ['cat' => 'Payments',  'code' => 'PCI-DSS', 'label' => 'PCI-DSS — card payment security', 'desc' => 'Card data is tokenised by the payment processor; the portal never stores PANs.'],
                ['cat' => 'Tax',       'code' => 'HMRC',    'label' => 'HMRC — Tax-Free Childcare', 'desc' => 'Government top-up scheme and childcare account reconciliation.'],
            ],
        ],
        'AU' => [
            'name' => 'Australia', 'flag' => '🇦🇺', 'currency' => 'AUD', 'locale' => 'en-AU',
            'compliance' => [
                ['cat' => 'Childcare', 'code' => 'ACECQA NQF', 'label' => 'ACECQA — National Quality Framework', 'desc' => 'National Quality Standard, educator ratios and qualifications.'],
                ['cat' => 'Privacy',   'code' => 'Privacy Act', 'label' => 'Privacy Act 1988 (APPs)', 'desc' => 'Australian Privacy Principles for handling personal information.'],
                ['cat' => 'Payments',  'code' => 'PCI-DSS', 'label' => 'PCI-DSS — card payment security', 'desc' => 'Card data is tokenised by the payment processor; the portal never stores PANs.'],
                ['cat' => 'Tax',       'code' => 'CCS',     'label' => 'CCS — Child Care Subsidy', 'desc' => 'Subsidy session reporting and gap-fee handling.'],
            ],
        ],
        'NZ' => [
            'name' => 'New Zealand', 'flag' => '🇳🇿', 'currency' => 'NZD', 'locale' => 'en-NZ',
            'compliance' => [
                ['cat' => 'Childcare', 'code' => 'MoE ECE', 'label' => 'Ministry of Education — ECE licensing', 'desc' => 'Licensing criteria, ratios and 20-Hours ECE funding.'],
                ['cat' => 'Privacy',   'code' => 'Privacy Act 2020', 'label' => 'Privacy Act 2020', 'desc' => 'Information privacy principles and breach notification.'],
                ['cat' => 'Payments',  'code' => 'PCI-DSS', 'label' => 'PCI-DSS — card payment security', 'desc' => 'Card data is tokenised by the payment processor; the portal never stores PANs.'],
                ['cat' => 'Tax',       'code' => 'IRD',     'label' => 'IRD — receipts', 'desc' => 'GST handling and year-end receipts.'],
            ],
        ],
        'IE' => [
            'name' => 'Ireland', 'flag' => '🇮🇪', 'currency' => 'EUR', 'locale' => 'en-IE',
            'compliance' => [
                ['cat' => 'Childcare', 'code' => 'Tusla', 'label' => 'Tusla — Early Years registration', 'desc' => 'Registration, ratios and the Síolta/Aistear frameworks.'],
                ['cat' => 'Privacy',   'code' => 'GDPR', 'label' => 'EU GDPR', 'desc' => 'Lawful basis, consent and data-subject rights.'],
                ['cat' => 'Payments',  'code' => 'PCI-DSS', 'label' => 'PCI-DSS — card payment security', 'desc' => 'Card data is tokenised by the payment processor; the portal never stores PANs.'],
                ['cat' => 'Tax',       'code' => 'NCS',     'label' => 'NCS — National Childcare Scheme', 'desc' => 'Subsidy reconciliation.'],
            ],
        ],
    ];

    private function resolveAgencyId(Request $request): int
    {
        $h = (int) $request->header('X-Active-Agency-Id');
        if ($h && DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)
                ->where(function ($q) use ($h) { $q->where('role', 'platform_admin')->orWhere('agency_id', $h); })->exists()) {
            return $h;
        }
        return (int) DB::table('role_assignments')->where('user_id', $request->user()->id)
            ->where('active', 1)->value('agency_id');
    }

    private function assertAdmin(Request $request): void
    {
        $ok = DB::table('role_assignments')->where('user_id', $request->user()->id)
            ->where('active', 1)->whereIn('role', ['agency_admin', 'platform_admin'])->exists();
        abort_unless($ok, 403, 'Admin only');
    }

    /** GET /admin/currency — public-ish (any authed user) so the UI can format money */
    public function show(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $fallback = \App\Support\Jurisdictions::forAgency($agencyId)['currency'] ?? 'CAD';
        $cur = DB::table('agencies')->where('id', $agencyId)->value('currency') ?: $fallback;
        if (!isset(self::SUPPORTED[$cur])) $cur = isset(self::SUPPORTED[$fallback]) ? $fallback : 'CAD';
        return response()->json([
            'currency'  => $cur,
            'symbol'    => self::SUPPORTED[$cur]['symbol'],
            'label'     => self::SUPPORTED[$cur]['label'],
            'supported' => collect(self::SUPPORTED)->map(fn ($v, $k) => ['code' => $k, 'symbol' => $v['symbol'], 'label' => $v['label']])->values(),
        ]);
    }

    /** PATCH /admin/currency { currency } */
    public function update(Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $data = $request->validate([
            'currency' => ['required', 'string', 'in:' . implode(',', array_keys(self::SUPPORTED))],
        ]);
        $agencyId = $this->resolveAgencyId($request);
        DB::table('agencies')->where('id', $agencyId)->update([
            'currency'   => $data['currency'],
            'updated_at' => now(),
        ]);
        return response()->json(['ok' => true, 'currency' => $data['currency']]);
    }

    private function countryList(): array
    {
        return collect(self::COUNTRIES)->map(fn ($v, $k) => [
            'code' => $k, 'name' => $v['name'], 'flag' => $v['flag'],
            'currency' => $v['currency'], 'locale' => $v['locale'],
        ])->values()->all();
    }

    /** GET /admin/country — current country + its compliance pack + the catalogue */
    public function showCountry(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $row = DB::table('agencies')->where('id', $agencyId)->first(['settings', 'currency', 'locale']);
        $settings = [];
        if ($row && $row->settings) {
            $decoded = json_decode($row->settings, true);
            if (is_array($decoded)) $settings = $decoded;
        }
        $code = $settings['country'] ?? null;
        if (!isset(self::COUNTRIES[$code])) $code = null;

        return response()->json([
            'country'    => $code,
            'currency'   => $row->currency ?? null,
            'locale'     => $row->locale ?? null,
            'pack'       => $code ? array_merge(['code' => $code], self::COUNTRIES[$code]) : null,
            'supported'  => $this->countryList(),
            // Province / state (2026-10-09): the jurisdiction profile the rest of the product reads.
            'jurisdiction'  => \App\Support\Jurisdictions::codeFor($agencyId),
            'jurisdictions' => \App\Support\Jurisdictions::options(),
            'profile'       => $this->profileSummary(\App\Support\Jurisdictions::forAgency($agencyId)),
        ]);
    }

    /** What the settings card shows about a jurisdiction: a short, human summary of its profile. */
    private function profileSummary(array $p): array
    {
        $inc = $p['incident'] ?? [];
        return [
            'code' => $p['code'], 'label' => $p['label'], 'country' => $p['country'], 'authority' => $p['authority'] ?? null,
            'currency' => $p['currency'], 'timezone' => $p['timezone'],
            'license_types' => $p['license_types'] ?? [],
            'drills' => array_column((array) ($p['drills'] ?? []), 'label'),
            'background_checks' => array_column((array) ($p['background_checks'] ?? []), 'label'),
            'background_check_note' => $p['background_check_note'] ?? null,
            'serious_incident' => trim(($inc['serious_label'] ?? 'Serious incident')
                . (! empty($inc['report_to']) ? ' — reported to ' . $inc['report_to'] : '')
                . (! empty($inc['deadline']) ? ', ' . $inc['deadline'] : '')),
            'pd_hours' => $p['pd_hours'] ?? null,
            'terms' => $p['terms'] ?? [],
            'privacy_note' => $p['privacy_note'] ?? null,
        ];
    }

    /** GET /admin/jurisdiction — the active agency's profile (any signed-in user: screens read its wording). */
    public function showJurisdiction(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $p = \App\Support\Jurisdictions::forAgency($agencyId);
        return response()->json(['jurisdiction' => $p['code'], 'profile' => $this->profileSummary($p), 'incident' => $p['incident'] ?? null,
            'drills' => $p['drills'] ?? [], 'immunization_schedule' => $p['immunization_schedule'] ?? null]);
    }

    /**
     * PATCH /admin/jurisdiction { jurisdiction } — admins. Sets the province/state; when it is in
     * another country than the agency's, the country pack (currency + locale) follows, exactly as
     * PATCH /admin/country does. Timezones are NEVER changed here: the profile's timezone is only
     * the fallback for an agency that has none set.
     */
    public function updateJurisdiction(Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $data = $request->validate(['jurisdiction' => ['required', 'string', 'in:' . implode(',', array_keys(\App\Support\Jurisdictions::all()))]]);
        $agencyId = $this->resolveAgencyId($request);
        $p = \App\Support\Jurisdictions::profile($data['jurisdiction']);
        $row = DB::table('agencies')->where('id', $agencyId)->first(['settings', 'jurisdiction']);
        $settings = ($row && $row->settings) ? (json_decode($row->settings, true) ?: []) : [];
        $update = ['jurisdiction' => $p['code'], 'updated_at' => now()];
        if (($settings['country'] ?? null) !== $p['country'] && isset(self::COUNTRIES[$p['country']])) {
            $settings['country'] = $p['country'];
            $update['settings'] = json_encode($settings);
            $update['currency'] = self::COUNTRIES[$p['country']]['currency'];
            $update['locale'] = self::COUNTRIES[$p['country']]['locale'];
        }
        DB::table('agencies')->where('id', $agencyId)->update($update);
        try {
            \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $agencyId, 'action' => 'agency.jurisdiction_changed', 'entity_type' => 'agency', 'entity_id' => $agencyId,
                'payload' => json_encode(['summary' => 'Province/state changed from ' . ($row->jurisdiction ?? '—') . ' to ' . $p['code'] . ' (' . $p['label'] . ')']),
                'ip_address' => substr((string) $request->ip(), 0, 45), 'created_at' => now()]);
        } catch (\Throwable $e) {}

        return response()->json(['ok' => true, 'jurisdiction' => $p['code'], 'profile' => $this->profileSummary($p),
            'country' => $p['country'], 'currency' => $update['currency'] ?? null]);
    }

    /** PATCH /admin/country { country } — applies currency + locale + compliance */
    public function updateCountry(Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $data = $request->validate([
            'country' => ['required', 'string', 'in:' . implode(',', array_keys(self::COUNTRIES))],
        ]);
        $code = $data['country'];
        $pack = self::COUNTRIES[$code];
        $agencyId = $this->resolveAgencyId($request);

        $row = DB::table('agencies')->where('id', $agencyId)->first(['settings']);
        $settings = [];
        if ($row && $row->settings) {
            $decoded = json_decode($row->settings, true);
            if (is_array($decoded)) $settings = $decoded;
        }
        $settings['country'] = $code;

        DB::table('agencies')->where('id', $agencyId)->update([
            'settings'   => json_encode($settings),
            'currency'   => $pack['currency'],
            'locale'     => $pack['locale'],
            'updated_at' => now(),
        ]);

        return response()->json([
            'ok'       => true,
            'country'  => $code,
            'currency' => $pack['currency'],
            'locale'   => $pack['locale'],
            'pack'     => array_merge(['code' => $code], $pack),
        ]);
    }
}
