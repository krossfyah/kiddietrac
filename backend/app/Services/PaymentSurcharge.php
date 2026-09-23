<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The service fee a payment method costs, as a percentage of what is being paid.
 *
 * Anthony, 2026-09-17: "with zum payments we need to build in for invoices being paid for
 * credit/debt a percentage configurator to allow the total invoice to be calculated by x
 * percent and comes up as a service fee for debit/credit. Same goes for EFT/Interac."
 *
 * `card_surcharge_percent` already existed in the settings JSON, was editable in Billing
 * setup, and was READ BY NOTHING - the same shape as the feature flags that turned out to
 * be decorative. It is wired up here, and EFT/Interac gets its own rate because the two
 * cost an agency very different amounts: card is typically 2-3%, an Interac e-Transfer is
 * usually a flat cost that works out well under 1%.
 *
 * WHY A PERCENTAGE OF THE PAYMENT, not of the invoice. A family paying half now and half
 * next month is charged the fee on each half, which is what the processor actually bills.
 * Taking the percentage off the invoice total up front would overcharge the first payment
 * and undercharge the second.
 *
 * CASH AND CHEQUE ARE NEVER SURCHARGED. There is no processor to pay, and a "service fee"
 * on cash is a fee for nothing.
 */
final class PaymentSurcharge
{
    /** Methods that cost card rates. Keyed on what the API accepts, not the ENUM. */
    private const CARD_METHODS = ['credit_card_offline', 'card', 'stripe_card'];

    /** Bank-rail methods: Interac e-Transfer, EFT, ACH. */
    private const EFT_METHODS = ['e_transfer', 'bank_transfer', 'interac', 'eft', 'stripe_ach'];

    /** The two configurable rates for an agency, defaulted to 0 (charge nothing). */
    public static function rates(?int $agencyId): array
    {
        $out = ['card' => 0.0, 'eft' => 0.0];
        if (! $agencyId) { return $out; }

        try {
            $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
            $s = $raw ? json_decode((string) $raw, true) : null;
            $bs = (is_array($s) && isset($s['billing_setup']) && is_array($s['billing_setup']))
                ? $s['billing_setup'] : [];

            $out['card'] = round((float) ($bs['card_surcharge_percent'] ?? 0), 2);
            $out['eft'] = round((float) ($bs['eft_surcharge_percent'] ?? 0), 2);
        } catch (Throwable $e) {
            report($e);
        }

        // Bounded here as well as in validation: a stored 900% would be a catastrophe
        // that nobody typed today.
        $out['card'] = max(0.0, min(10.0, $out['card']));
        $out['eft'] = max(0.0, min(10.0, $out['eft']));

        return $out;
    }

    /** The percentage that applies to one payment method, or 0. */
    public static function percentFor(?int $agencyId, string $method): float
    {
        $rates = self::rates($agencyId);

        if (in_array($method, self::CARD_METHODS, true)) { return $rates['card']; }
        if (in_array($method, self::EFT_METHODS, true)) { return $rates['eft']; }

        // cash, cheque, manual, other: nothing to pass on.
        return 0.0;
    }

    /** The fee on an amount, rounded to the cent. */
    public static function feeOn(float $amount, float $percent): float
    {
        if ($percent <= 0 || $amount <= 0) { return 0.0; }

        return round($amount * ($percent / 100), 2);
    }

    /** What the line says on the invoice. A parent has to be able to tell what it is. */
    public static function lineLabel(string $method, float $percent): string
    {
        $what = in_array($method, self::CARD_METHODS, true)
            ? 'card'
            : (in_array($method, self::EFT_METHODS, true) ? 'e-Transfer / EFT' : 'payment');

        return 'Service fee (' . rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.')
            . '% ' . $what . ')';
    }
}
