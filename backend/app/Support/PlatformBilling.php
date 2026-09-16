<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Tax, currency and recurrence maths for platform invoices (2026-08-25).
 *
 * One implementation, shared by the raise command, the controller and the PDF. Tax
 * computed three slightly different ways in three places is how an invoice, its PDF and
 * the dashboard end up disagreeing by a cent.
 */
final class PlatformBilling
{
    /** Currencies we can actually invoice in. */
    public const CURRENCIES = ['CAD', 'USD'];

    public const INTERVALS = ['monthly', 'quarterly', 'annual'];

    /**
     * Tax on a subtotal, in cents.
     *
     * Rate is BASIS POINTS (1300 = 13%). intdiv with explicit half-up rounding rather
     * than float multiplication: (int) round() on a float subtotal is where the
     * off-by-one-cent tax bugs live.
     */
    public static function taxCents(int $subtotalCents, int $rateBps): int
    {
        if ($rateBps <= 0 || $subtotalCents <= 0) {
            return 0;
        }

        return intdiv($subtotalCents * $rateBps + 5000, 10000);
    }

    /** Subtotal + tax. The invoice's amount_cents is always this. */
    public static function totalCents(int $subtotalCents, int $rateBps): int
    {
        return $subtotalCents + self::taxCents($subtotalCents, $rateBps);
    }

    /** "13%" / "13.5%" — trailing zeros trimmed, for display only. */
    public static function ratePercent(int $rateBps): string
    {
        return rtrim(rtrim(number_format($rateBps / 100, 2, '.', ''), '0'), '.') . '%';
    }

    public static function normaliseCurrency(?string $c): string
    {
        $c = strtoupper(trim((string) $c));

        return in_array($c, self::CURRENCIES, true) ? $c : 'CAD';
    }

    /**
     * Money for display.
     *
     * CAD and USD both use "$", so the code is ALWAYS shown. On an invoice that can be
     * raised in either, a bare "$249.00" is ambiguous by roughly the exchange rate —
     * and the customer is the one who finds out.
     */
    public static function money(int $cents, ?string $currency): string
    {
        $cur = self::normaliseCurrency($currency);
        $sign = $cents < 0 ? '-' : '';

        return $sign . $cur . ' $' . number_format(abs($cents) / 100, 2);
    }

    /**
     * A plan's contribution to MONTHLY recurring revenue.
     *
     * MRR is a monthly figure, so a quarterly or annual plan must be divided down before
     * it is summed. Counting an annual plan at face value overstates MRR twelvefold, and
     * ARR/ARPA/ARPU are all derived from it.
     *
     * intdiv with half-up rounding, consistent with taxCents() — these are cents.
     */
    public static function monthlyCents(int $amountCents, ?string $interval): int
    {
        $months = match (self::normaliseInterval($interval)) {
            'quarterly' => 3,
            'annual' => 12,
            default => 1,
        };

        return $months === 1 ? $amountCents : intdiv($amountCents + intdiv($months, 2), $months);
    }

    /** The next date an agency on this interval should be invoiced. */
    public static function advance(string $from, string $interval): string
    {
        $d = Carbon::parse($from)->startOfDay();

        /* addMonthsNoOverflow, not addMonths: an agency anchored on the 31st billed with
           plain addMonths lands on 3 March from 31 January. NoOverflow clamps to the last
           day of the shorter month, which is what a billing anchor means. */
        return match ($interval) {
            'quarterly' => $d->addMonthsNoOverflow(3)->toDateString(),
            'annual' => $d->addYearsNoOverflow(1)->toDateString(),
            default => $d->addMonthNoOverflow()->toDateString(),
        };
    }

    /** The period an invoice raised on this date covers, for the given interval. */
    public static function period(string $start, string $interval): array
    {
        $s = Carbon::parse($start)->startOfDay();
        $end = Carbon::parse(self::advance($start, $interval))->subDay();

        return [$s->toDateString(), $end->toDateString()];
    }

    public static function normaliseInterval(?string $i): string
    {
        $i = strtolower(trim((string) $i));

        return in_array($i, self::INTERVALS, true) ? $i : 'monthly';
    }

    /** "Monthly" / "Quarterly" / "Annual" — for invoice lines and the UI. */
    public static function intervalLabel(?string $i): string
    {
        return ucfirst(self::normaliseInterval($i));
    }
}
