<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * v22p51 — Agency billing settings.
 * - Late-fee percent / cap / grace days (was hardcoded in v22p49)
 * - SMS enabled flag
 * - Default locale
 * Stripe customer creation / autopay toggle lives in
 * StripeParentPayController.
 */
final class AgencyBillingConfigController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $row = DB::table('agencies')->where('id', $agencyId)->first([
            'id', 'name', 'late_fee_percent', 'late_fee_cap', 'late_fee_grace_days',
            'sms_enabled', 'default_locale', 'settings',
        ]);
        // v22p88: email_enabled lives in the settings JSON (no column).
        $emailEnabled = true;
        if ($row && $row->settings) {
            $s = json_decode($row->settings, true);
            if (is_array($s) && array_key_exists('email_enabled', $s)) $emailEnabled = (bool) $s['email_enabled'];
        }
        $data = (array) $row;
        unset($data['settings']);
        $data['email_enabled'] = $emailEnabled;
        return response()->json(['data' => $data]);
    }

    public function update(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $this->assertAgencyAdmin($request, $agencyId);
        $data = $request->validate([
            'late_fee_percent'    => 'nullable|numeric|min:0|max:25',
            'late_fee_cap'        => 'nullable|numeric|min:0|max:500',
            'late_fee_grace_days' => 'nullable|integer|min:0|max:60',
            'sms_enabled'         => 'nullable|boolean',
            'email_enabled'       => 'nullable|boolean',
            'default_locale'      => 'nullable|string|in:en,fr,es,hi',
        ]);
        // v22p88: email_enabled -> settings JSON; the rest are agency columns.
        $emailEnabled = $data['email_enabled'] ?? null;
        unset($data['email_enabled']);
        if ($emailEnabled !== null) {
            $row = DB::table('agencies')->where('id', $agencyId)->first(['settings']);
            $settings = [];
            if ($row && $row->settings) { $x = json_decode($row->settings, true); if (is_array($x)) $settings = $x; }
            $settings['email_enabled'] = (bool) $emailEnabled;
            $data['settings'] = json_encode($settings);
        }
        DB::table('agencies')->where('id', $agencyId)->update($data + ['updated_at' => now()]);
        return response()->json(['status' => 'updated']);
    }

    /**
     * v22p87 — combined guided billing setup: late fees (columns) + the new
     * pre-selections (default frequency, deposit, registration fee, accepted
     * payment methods, autopay) stored in the agency settings JSON.
     */
    public function showSetup(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $row = DB::table('agencies')->where('id', $agencyId)->first([
            'late_fee_percent', 'late_fee_cap', 'late_fee_grace_days', 'settings', 'currency',
        ]);
        $settings = [];
        if ($row && $row->settings) { $d = json_decode($row->settings, true); if (is_array($d)) $settings = $d; }
        $bs = $settings['billing_setup'] ?? [];

        return response()->json(['data' => [
            'currency'                  => $row->currency ?? 'CAD',
            'late_fee_percent'          => $row->late_fee_percent ?? 1.5,
            'late_fee_cap'              => $row->late_fee_cap ?? 25,
            'late_fee_grace_days'       => $row->late_fee_grace_days ?? 0,
            'default_billing_frequency' => $bs['default_billing_frequency'] ?? 'monthly',
            'deposit_amount'            => $bs['deposit_amount'] ?? 0,
            'registration_fee'          => $bs['registration_fee'] ?? 0,
            /* HOW LONG BEFORE ITS DUE DATE AN INVOICE GOES OUT (2026-09-17).

               A payment schedule used to raise every instalment's invoice dated the
               FIRST OF ITS MONTH, which gave wildly uneven notice: on a biweekly plan
               the 9th and the 23rd both issued on the 1st - 8 days and 22 days - while a
               4th-of-the-month instalment got 3. Five days before the due date, every
               time, is what Anthony asked for, and it is per-agency because how much
               warning a family gets is an agency's own policy. */
            'invoice_issue_lead_days'   => $bs['invoice_issue_lead_days'] ?? 5,
            'autopay_default'           => $bs['autopay_default'] ?? false,
            'autopay_required'          => $bs['autopay_required'] ?? false,
            'card_surcharge_percent'    => $bs['card_surcharge_percent'] ?? 0,
            /* The bank-rail equivalent (2026-09-17). Card and e-Transfer cost an agency
               very different amounts, so one rate could not serve both: card is typically
               2-3%, an Interac e-Transfer is usually a flat cost well under 1%. */
            'eft_surcharge_percent'     => $bs['eft_surcharge_percent'] ?? 0,
            'accepted_payment_methods'  => $bs['accepted_payment_methods'] ?? ['stripe_card', 'interac', 'cash', 'cheque'],
        ]]);
    }

    public function updateSetup(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $this->assertAgencyAdmin($request, $agencyId);
        $d = $request->validate([
            'late_fee_percent'           => 'nullable|numeric|min:0|max:25',
            'late_fee_cap'               => 'nullable|numeric|min:0|max:500',
            'late_fee_grace_days'        => 'nullable|integer|min:0|max:60',
            'default_billing_frequency'  => 'nullable|in:weekly,biweekly,monthly',
            'deposit_amount'             => 'nullable|numeric|min:0|max:100000',
            'registration_fee'           => 'nullable|numeric|min:0|max:100000',
            'invoice_issue_lead_days'    => 'nullable|integer|min:0|max:60',
            'autopay_default'            => 'nullable|boolean',
            'autopay_required'           => 'nullable|boolean',
            'card_surcharge_percent'     => 'nullable|numeric|min:0|max:10',
            'eft_surcharge_percent'      => 'nullable|numeric|min:0|max:10',
            'accepted_payment_methods'   => 'nullable|array',
            'accepted_payment_methods.*' => 'in:stripe_card,stripe_ach,interac,cash,cheque,manual',
        ]);

        $cols = [];
        foreach (['late_fee_percent', 'late_fee_cap', 'late_fee_grace_days'] as $k) {
            if (array_key_exists($k, $d)) $cols[$k] = $d[$k];
        }
        $row = DB::table('agencies')->where('id', $agencyId)->first(['settings']);
        $settings = [];
        if ($row && $row->settings) { $x = json_decode($row->settings, true); if (is_array($x)) $settings = $x; }
        $bs = $settings['billing_setup'] ?? [];
        foreach (['default_billing_frequency', 'deposit_amount', 'registration_fee', 'invoice_issue_lead_days', 'autopay_default', 'autopay_required', 'card_surcharge_percent', 'eft_surcharge_percent', 'accepted_payment_methods'] as $k) {
            if (array_key_exists($k, $d)) $bs[$k] = $d[$k];
        }
        $settings['billing_setup'] = $bs;
        $cols['settings'] = json_encode($settings);
        $cols['updated_at'] = now();
        DB::table('agencies')->where('id', $agencyId)->update($cols);

        return response()->json(['status' => 'updated']);
    }

    private function resolveAgencyId(Request $request): int
    {
        $activeId = (int) $request->header('X-Active-Agency-Id');
        // SECURITY (v22p94): only honour the header if the user is platform_admin
        // or holds an active role for that exact agency (else fall back below).
        if ($activeId && DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)->where(function ($w) use ($activeId) { $w->where('agency_id', $activeId)->orWhere('role', 'platform_admin'); })->exists()) return $activeId;
        // SECURITY (v22p98): a platform_admin with no valid SELECTED agency must NOT
        // fall through to their first role's agency (iLearn) — require an explicit
        // choice, else agency-scoped data leaked to a super-admin on a header-less call.
        if (DB::table('role_assignments')->where('user_id', $request->user()->id)->where('role', 'platform_admin')->where('active', true)->exists()) abort(400, 'Select an agency first.');
        $first = DB::table('role_assignments')
            ->where('user_id', $request->user()->id)
            ->where('active', true)
            ->value('agency_id');
        abort_unless($first, 400);
        return (int) $first;
    }

    private function assertAgencyAdmin(Request $request, int $agencyId): void
    {
        $u = $request->user();
        $isPlatform = DB::table('role_assignments')->where('user_id', $u->id)
            ->where('role', 'platform_admin')->where('active', true)->exists();
        if ($isPlatform) return;
        $hasRole = DB::table('role_assignments')->where('user_id', $u->id)
            ->where('agency_id', $agencyId)->where('role', 'agency_admin')
            ->where('active', true)->exists();
        abort_unless($hasRole, 403);
    }
}
