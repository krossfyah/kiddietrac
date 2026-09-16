<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\PlatformBilling;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Deciding what to invoice, and writing it (2026-08-25).
 *
 * ONE implementation, because there were briefly two. The screen's endpoint was rewritten
 * to bill on each agency's own schedule with tax and currency, while the artisan command
 * kept billing a calendar month for everyone with no tax at all — so the same button and
 * the same command produced different invoices from the same data. Whichever you happened
 * to use decided what the customer was charged.
 *
 * Both now call this.
 */
final class PlatformInvoiceRaiser
{
    /**
     * What WOULD be raised today, with a reason for everything that would not be.
     *
     * Never writes. The caller decides whether to commit, which is what lets both the
     * command and the screen preview first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function plan(?string $asOf = null, ?int $onlyAgencyId = null): array
    {
        $today = $asOf ? Carbon::parse($asOf)->startOfDay() : Carbon::now()->startOfDay();
        $todayStr = $today->toDateString();

        $q = DB::table('agencies')->orderBy('id');
        if ($onlyAgencyId !== null) {
            $q->where('id', $onlyAgencyId);
        }

        $plan = [];
        foreach ($q->get() as $a) {
            $subtotal = (int) ($a->plan_amount_cents ?? 0);
            $bps = (int) ($a->tax_rate_bps ?? 0);
            $interval = PlatformBilling::normaliseInterval($a->billing_interval ?? null);
            $currency = PlatformBilling::normaliseCurrency($a->plan_currency ?? null);
            $due = $a->next_invoice_at ? (string) $a->next_invoice_at : null;

            $skip = null;
            if ((string) $a->billing_status !== 'active') {
                $skip = 'not active (' . ($a->billing_status ?: 'unset') . ')';
            } elseif ($subtotal <= 0) {
                $skip = 'no plan amount set';
            } elseif ($due === null) {
                /* Not an oversight — a null next date is how an agency is parked on
                   recurring billing without losing its pricing. */
                $skip = 'not on recurring billing (no next invoice date)';
            } elseif ($due > $todayStr) {
                $skip = 'not due until ' . $due;
            }

            [$periodStart, $periodEnd] = $due
                ? PlatformBilling::period($due, $interval)
                : [$todayStr, $todayStr];

            if ($skip === null && DB::table('platform_invoices')
                ->where('agency_id', $a->id)->where('period_start', $periodStart)->exists()) {
                $skip = 'already billed for the period starting ' . $periodStart;
            }

            $plan[] = [
                'agency_id' => $a->id,
                'agency_name' => $a->name,
                'subtotal_cents' => $subtotal,
                'tax_cents' => PlatformBilling::taxCents($subtotal, $bps),
                'tax_rate_bps' => $bps,
                'tax_label' => $a->tax_label,
                'amount_cents' => PlatformBilling::totalCents($subtotal, $bps),
                'currency' => $currency,
                'interval' => $interval,
                'plan_code' => $a->plan_code,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'next_invoice_at' => $due,
                'skip_reason' => $skip,
            ];
        }

        return $plan;
    }

    /** Just the entries with no skip reason. */
    public function billable(array $plan): array
    {
        return array_values(array_filter($plan, fn ($p) => $p['skip_reason'] === null));
    }

    /**
     * Write the invoices as DRAFTS and advance each agency's schedule.
     *
     * Drafts, always: issuing and sending stay separate deliberate acts, so generating
     * the month's billing can never itself email a customer.
     *
     * @return array{raised: array<int, string>, failed: array<int, string>}
     */
    public function commit(array $billable, ?int $userId = null, int $dueDays = 14): array
    {
        $raised = [];
        $failed = [];

        foreach ($billable as $p) {
            $number = sprintf('KT-%s-%04d',
                Carbon::parse($p['period_start'])->format('Ym'), $p['agency_id']);

            try {
                DB::transaction(function () use ($p, $number, $userId, $dueDays) {
                    DB::table('platform_invoices')->insert([
                        'agency_id' => $p['agency_id'],
                        'number' => $number,
                        'period_start' => $p['period_start'],
                        'period_end' => $p['period_end'],
                        'plan_code' => $p['plan_code'],
                        'subtotal_cents' => $p['subtotal_cents'],
                        'tax_cents' => $p['tax_cents'],
                        'tax_rate_bps' => $p['tax_rate_bps'],
                        'tax_label' => $p['tax_label'],
                        /* amount_cents is the TOTAL. Every existing reader — MRR, the
                           tiles, the reminders — treats it as the amount owed. */
                        'amount_cents' => $p['amount_cents'],
                        'amount_paid_cents' => 0,
                        'currency' => $p['currency'],
                        'status' => 'draft',
                        'due_at' => Carbon::now()->addDays($dueDays)->toDateString(),
                        'created_by_id' => $userId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    /* Advanced in the SAME transaction as the insert. If these could
                       diverge, a failure between them would either bill the agency twice
                       on the next run or skip them entirely. */
                    DB::table('agencies')->where('id', $p['agency_id'])->update([
                        'next_invoice_at' => PlatformBilling::advance($p['next_invoice_at'], $p['interval']),
                        'updated_at' => now(),
                    ]);
                });

                $raised[] = $number;
            } catch (Throwable $e) {
                /* The unique key on (agency_id, period_start) is the real guard against
                   double billing — a double-click or a re-run lands here, not in a
                   second invoice. */
                $failed[] = $number;
            }
        }

        return ['raised' => $raised, 'failed' => $failed];
    }
}
