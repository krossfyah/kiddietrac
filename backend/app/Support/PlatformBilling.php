<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

    /* weekly/biweekly added 2026-09-29 (Anthony: "could be weekly, biweekly, monthly, yearly"). */
    public const INTERVALS = ['weekly', 'biweekly', 'monthly', 'quarterly', 'semiannual', 'annual'];

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
        // Sub-monthly plans: 52 or 26 invoices a year, spread over 12 months.
        $perYear = match (self::normaliseInterval($interval)) { 'weekly' => 52, 'biweekly' => 26, default => 0 };
        if ($perYear) {
            return intdiv($amountCents * $perYear + 6, 12);
        }

        $months = match (self::normaliseInterval($interval)) {
            'quarterly' => 3,
            'semiannual' => 6,
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
            'weekly' => $d->addDays(7)->toDateString(),
            'biweekly' => $d->addDays(14)->toDateString(),
            'quarterly' => $d->addMonthsNoOverflow(3)->toDateString(),
            'semiannual' => $d->addMonthsNoOverflow(6)->toDateString(),
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
        $i = self::normaliseInterval($i);

        return $i === 'biweekly' ? 'Every 2 weeks' : ($i === 'semiannual' ? 'Semi-annual' : ucfirst($i));
    }

    /* ── RECURRING INVOICES (2026-10-25) ───────────────────────────────────────────────
       platform_settings 'billing.recurring' (JSON): enabled (mirrors billing.auto_raise), scope all|selected, and
       the due-date rule. Per agency: recurring_enabled (when scope is selected), billing_starts_at / billing_ends_at
       (the issuing window). */
    // run_time: when the recurring run raises invoices each day, HH:MM in Toronto time (2026-10-25).
    public const RECURRING_DEFAULTS = ['enabled' => false, 'scope' => 'all', 'due_rule' => 'end_of_month', 'due_days' => 14, 'due_day' => 1, 'run_time' => '06:00'];

    public static function recurring(): array
    {
        $raw = DB::table('platform_settings')->where('key', 'billing.recurring')->value('value');
        $s = array_merge(self::RECURRING_DEFAULTS, json_decode((string) $raw, true) ?: []);
        $auto = strtolower((string) DB::table('platform_settings')->where('key', 'billing.auto_raise')->value('value'));
        $s['enabled'] = in_array($auto, ['1', 'true', 'yes', 'on'], true);
        return $s;
    }

    /** Why this agency is NOT raised today (null = it may be). */
    public static function recurringSkip(object $a, string $today): ?string
    {
        $s = self::recurring();
        if ($s['scope'] === 'selected' && empty($a->recurring_enabled)) return 'recurring invoices are off for this agency';
        if (! empty($a->billing_starts_at) && (string) $a->billing_starts_at > $today) return 'issuing starts ' . $a->billing_starts_at;
        if (! empty($a->billing_ends_at) && (string) $a->billing_ends_at < $today) return 'issuing ended ' . $a->billing_ends_at;
        return null;
    }

    /** The due date for an invoice issued on $issue, by the platform rule (default: the end of that month). */
    public static function dueDate(string $issue, ?array $s = null): string
    {
        $s = $s ?: self::recurring();
        $d = \Carbon\Carbon::parse($issue);
        switch ($s['due_rule']) {
            case 'days_after':
                return $d->addDays(max(0, (int) $s['due_days']))->toDateString();
            case 'day_of_month':
                $day = max(1, min(28, (int) $s['due_day']));
                $t = $d->copy()->day($day);
                if ($t->lte($d)) $t = $d->copy()->addMonthNoOverflow()->day($day);
                return $t->toDateString();
            default:
                // End of the month; an invoice raised in the last 3 days is due at the end of the next month.
                $e = $d->copy()->endOfMonth();
                if ($d->diffInDays($e) < 3) $e = $d->copy()->addMonthNoOverflow()->endOfMonth();
                return $e->toDateString();
        }
    }

    /** BCC for a KiddieTrac email to an agency: superadmins, its salesperson, both or none (per agency, else the default). */
    public static function invoiceBcc(int $agencyId): array
    {
        $a = DB::table('agencies')->where('id', $agencyId)->first(['invoice_bcc', 'account_manager_user_id']);
        $mode = (string) ($a->invoice_bcc ?? 'default');
        if ($mode === 'default' || $mode === '') $mode = (string) (\App\Support\PlatformAccounts::settings()['bcc_default'] ?? 'none');
        $out = [];
        if (in_array($mode, ['superadmins', 'both'], true)) {
            foreach (DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')->where('ra.role', 'platform_admin')->where('ra.active', 1)->whereNull('u.deleted_at')->pluck('u.email') as $e)
                if (filter_var($e, FILTER_VALIDATE_EMAIL)) $out[] = strtolower($e);
        }
        if (in_array($mode, ['sales', 'both'], true) && ! empty($a->account_manager_user_id)) {
            $e = DB::table('users')->where('id', $a->account_manager_user_id)->value('email');
            if (filter_var($e, FILTER_VALIDATE_EMAIL)) $out[] = strtolower($e);
        }
        return array_values(array_unique($out));
    }
}
