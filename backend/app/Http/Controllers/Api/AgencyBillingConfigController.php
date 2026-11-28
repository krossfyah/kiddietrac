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
            'late_pickup_per_minute', 'late_pickup_grace_minutes',
        ]);
        $settings = [];
        if ($row && $row->settings) { $d = json_decode($row->settings, true); if (is_array($d)) $settings = $d; }
        $bs = $settings['billing_setup'] ?? [];

        return response()->json(['data' => [
            'currency'                  => $row->currency ?? 'CAD',
            'late_fee_percent'          => $row->late_fee_percent ?? 1.5,
            'late_fee_cap'              => $row->late_fee_cap ?? 25,
            'late_fee_grace_days'       => $row->late_fee_grace_days ?? 0,
            /* LATE PICKUP FEE (2026-11-27). App\Support\LatePickups::policy() suggests a late-pickup fee from these two
               agency columns, but nothing let anyone set them, so every agency ran on the built-in $1.00/min after
               5 minutes. Same defaults as policy(). */
            'late_pickup_per_minute'    => (float) ($row->late_pickup_per_minute ?? 1.00),
            'late_pickup_grace_minutes' => (int) ($row->late_pickup_grace_minutes ?? 5),
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
            /* Parent billing (2026-10-28) - see App\Support\ParentBilling. The address families send Interac
               e-Transfers to (no address = no Interac button), and how many days before its due date an unpaid
               invoice appears to the family. */
            'interac_email'             => $bs['interac_email'] ?? '',
            // Families may ask for an e-Transfer REQUEST instead of sending one (2026-10-30). On unless switched off.
            'interac_requests'          => ($bs['interac_requests'] ?? true) !== false,
            /* INTERAC E-TRANSFER TAB (2026-11-11, Anthony: "in payment providers add a new subtab to enable interac
               etransfer and ability to prompt parents ... instructions on how to do so for the agency"). Edited in
               Payment providers -> Interac e-Transfer; read by ParentBilling::payInfo for every parent screen and invoice. */
            'interac_recipient_name'    => $bs['interac_recipient_name'] ?? '',
            'interac_autodeposit'       => array_key_exists('interac_autodeposit', $bs) ? $bs['interac_autodeposit'] : null,
            'interac_question'          => $bs['interac_question'] ?? '',
            'interac_answer'            => $bs['interac_answer'] ?? '',
            'interac_instructions'      => $bs['interac_instructions'] ?? '',
            'agency_display_name'       => (string) (DB::table('agencies')->where('id', $agencyId)->value('legal_name') ?: DB::table('agencies')->where('id', $agencyId)->value('name')),
            // Card / debit payment shown on invoices and invoice emails (2026-10-31). Off unless switched on.
            'invoice_show_card'         => ($bs['invoice_show_card'] ?? false) === true,
            // Each new invoice is also an announcement in the paying guardian's News (2026-10-31). On unless switched off.
            'announce_invoices'         => ($bs['announce_invoices'] ?? true) !== false,
            'due_window_days'           => $bs['due_window_days'] ?? \App\Support\ParentBilling::DEFAULT_WINDOW_DAYS,
            /* The automated billing features' own rules (2026-11-28, App\Support\BillingRules): tuition increase notice,
               vacation hold credit, re-enrolment reminder. Edited from each screen's Rules button. */
            'tuition_notice_days'       => \App\Support\BillingRules::tuitionNoticeDays($agencyId),
            'vacation_credit_mode'      => \App\Support\BillingRules::vacationCredit($agencyId)['mode'],
            'vacation_credit_value'     => \App\Support\BillingRules::vacationCredit($agencyId)['value'],
            'reenrol_reminder_days'     => \App\Support\BillingRules::reenrolReminderDays($agencyId),
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
            'late_pickup_per_minute'     => 'nullable|numeric|min:0|max:50',
            'late_pickup_grace_minutes'  => 'nullable|integer|min:0|max:120',
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
            'interac_email'              => 'nullable|email|max:190',
            'interac_requests'           => 'nullable|boolean',
            'interac_recipient_name'     => 'nullable|string|max:120',
            'interac_autodeposit'        => 'nullable|boolean',
            'interac_question'           => 'nullable|string|max:120',
            'interac_answer'             => 'nullable|string|max:60',
            'interac_instructions'       => 'nullable|string|max:1000',
            'invoice_show_card'          => 'nullable|boolean',
            'announce_invoices'          => 'nullable|boolean',
            'due_window_days'            => 'nullable|integer|min:0|max:120',
            'tuition_notice_days'        => 'nullable|integer|min:0|max:365',
            'vacation_credit_mode'       => 'nullable|in:none,percent,flat',
            'vacation_credit_value'      => 'nullable|numeric|min:0|max:1000',
            'reenrol_reminder_days'      => 'nullable|integer|min:0|max:90',
        ]);

        $cols = [];
        foreach (['late_fee_percent', 'late_fee_cap', 'late_fee_grace_days', 'late_pickup_per_minute', 'late_pickup_grace_minutes'] as $k) {
            if (array_key_exists($k, $d) && $d[$k] !== null) $cols[$k] = $d[$k];
        }
        $row = DB::table('agencies')->where('id', $agencyId)->first(['settings']);
        $settings = [];
        if ($row && $row->settings) { $x = json_decode($row->settings, true); if (is_array($x)) $settings = $x; }
        $bs = $settings['billing_setup'] ?? [];
        foreach (['default_billing_frequency', 'deposit_amount', 'registration_fee', 'invoice_issue_lead_days', 'autopay_default', 'autopay_required', 'card_surcharge_percent', 'eft_surcharge_percent', 'accepted_payment_methods', 'interac_email', 'interac_requests', 'invoice_show_card', 'announce_invoices', 'due_window_days', 'tuition_notice_days', 'vacation_credit_mode', 'vacation_credit_value', 'reenrol_reminder_days'] as $k) {
            if (array_key_exists($k, $d)) $bs[$k] = in_array($k, ['invoice_show_card', 'announce_invoices'], true) ? (bool) $d[$k] : $d[$k];
        }
        // The Interac e-Transfer tab (2026-11-11). Plain text only: it is shown to families as written.
        foreach (['interac_recipient_name', 'interac_question', 'interac_answer', 'interac_instructions'] as $k) {
            if (array_key_exists($k, $d)) $bs[$k] = trim(strip_tags((string) ($d[$k] ?? '')));
        }
        if (array_key_exists('interac_autodeposit', $d)) $bs['interac_autodeposit'] = $d['interac_autodeposit'] === null ? null : (bool) $d['interac_autodeposit'];
        if (array_key_exists('interac_email', $d) && in_array('interac', (array) ($bs['accepted_payment_methods'] ?? []), true)) {
            // Switched on from the tab: never an e-Transfer button with no address, or a security question with no answer.
            if (trim((string) ($bs['interac_email'] ?? '')) === '') {
                return response()->json(['message' => 'Add the email address families send e-Transfers to.', 'errors' => ['interac_email' => ['Required to accept Interac e-Transfer.']]], 422);
            }
            if (($bs['interac_autodeposit'] ?? null) === false && (($bs['interac_question'] ?? '') === '' || ($bs['interac_answer'] ?? '') === '')) {
                return response()->json(['message' => 'Without Autodeposit, families need the security question and its answer.', 'errors' => ['interac_question' => ['Add the question and the answer, or choose Autodeposit.']]], 422);
            }
        }
        if (($bs['vacation_credit_mode'] ?? null) === 'percent' && (float) ($bs['vacation_credit_value'] ?? 0) > 100) {
            return response()->json(['message' => 'A vacation credit can be at most 100% of the daily fee.', 'errors' => ['vacation_credit_value' => ['At most 100.']]], 422);
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
