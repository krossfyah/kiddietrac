<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * What a parent's Billing screen shows (2026-10-28, Anthony: "only show whats due now and the future dates/invoices
 * can be hidden until they come up to due 2 weeks in advance", and "a interac etransfer button ... shows the ilearn
 * email address for the interac payment and references the invoice number and instructions").
 *
 * WINDOW. A payment schedule issues a family's whole term at once - Patricia Burgess saw four $83.16 invoices running
 * to 18 December, and a "total due" of all four, the day her schedule was set up. An unpaid invoice now stays out of
 * the parent's view, and out of the total, until it is within `due_window_days` (billing_setup, default 14) of its due
 * date. Overdue and undated invoices always show; paid ones are history and show as before.
 *
 * INTERAC. The address families send e-Transfers to is the agency's own (billing_setup.interac_email - iLearn's is
 * info@ilearnhcc.com, the address on its parent agreement). No address, or Interac not among the accepted methods,
 * means no Interac button: a parent is never shown a guessed address to send money to.
 */
class ParentBilling
{
    public const DEFAULT_WINDOW_DAYS = 14;

    public static function setup(?int $agencyId): array
    {
        if (! $agencyId) {
            return [];
        }
        $s = json_decode((string) DB::table('agencies')->where('id', $agencyId)->value('settings'), true);

        return is_array($s) && is_array($s['billing_setup'] ?? null) ? $s['billing_setup'] : [];
    }

    public static function windowDays(?int $agencyId): int
    {
        $d = self::setup($agencyId)['due_window_days'] ?? null;

        return $d === null || $d === '' ? self::DEFAULT_WINDOW_DAYS : max(0, min(120, (int) $d));
    }

    /** The last due date a parent sees an unpaid invoice for, in the agency's own day. */
    public static function horizon(?int $agencyId): string
    {
        return AgencyTime::todayDate($agencyId)->addDays(self::windowDays($agencyId))->toDateString();
    }

    /** Whether an unpaid invoice with this due date is still in the future, past the window. */
    public static function isUpcoming($dueAt, string $horizon): bool
    {
        return $dueAt !== null && $dueAt !== '' && substr((string) $dueAt, 0, 10) > $horizon;
    }

    /** How a parent of this agency pays by Interac e-Transfer, or null when the agency has not said. */
    public static function payInfo(?int $agencyId): ?array
    {
        if (! $agencyId) {
            return null;
        }
        $bs = self::setup($agencyId);
        $email = trim((string) ($bs['interac_email'] ?? ''));
        $accepted = $bs['accepted_payment_methods'] ?? ['stripe_card', 'interac', 'cash', 'cheque'];
        $agency = DB::table('agencies')->where('id', $agencyId)->first(['name', 'legal_name']);

        return [
            'agency_name' => $agency->name ?? null,
            'window_days' => self::windowDays($agencyId),
            'interac' => ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && in_array('interac', (array) $accepted, true))
                ? ['email' => $email,
                   // The name the family's bank shows for the recipient (Payment providers -> Interac e-Transfer, 2026-11-11).
                   'recipient' => trim((string) ($bs['interac_recipient_name'] ?? '')) ?: (($agency->legal_name ?? '') ?: ($agency->name ?? null)),
                   // Families may ask the centre to SEND them an e-Transfer request (EtransferRequestController); on unless switched off.
                   'requests' => ($bs['interac_requests'] ?? true) !== false,
                   // true = Autodeposit (no question); false = the family needs the security question and answer; null = not said.
                   'autodeposit' => array_key_exists('interac_autodeposit', $bs) && $bs['interac_autodeposit'] !== null ? (bool) $bs['interac_autodeposit'] : null,
                   'question' => (($bs['interac_autodeposit'] ?? null) === false && trim((string) ($bs['interac_question'] ?? '')) !== '') ? trim((string) $bs['interac_question']) : null,
                   'answer' => (($bs['interac_autodeposit'] ?? null) === false && trim((string) ($bs['interac_answer'] ?? '')) !== '') ? trim((string) $bs['interac_answer']) : null,
                   // The agency's own words for its families (shown in the sheet and under "How to pay" on invoices).
                   'instructions' => trim((string) ($bs['interac_instructions'] ?? '')) ?: null]
                : null,
            // The website demo's simulated payment (App\Support\WebDemoPayments): null for every real agency.
            'demo_pay' => \App\Support\WebDemoPayments::info($agencyId),
        ];
    }

    /** The agency a family bills through (its centre's agency). */
    public static function agencyOfFamily(?int $familyId): ?int
    {
        if (! $familyId) {
            return null;
        }
        $id = DB::table('families as f')->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('f.id', $familyId)->value('c.agency_id');

        return $id ? (int) $id : null;
    }
}
