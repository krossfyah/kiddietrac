<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\PlatformInvoiceRaiser;
use App\Support\PlatformBilling;
use Illuminate\Console\Command;

/**
 * Raise every platform invoice that is due (rewritten 2026-08-25).
 *
 * Now a thin wrapper over PlatformInvoiceRaiser, which the screen's "Raise invoices due
 * now" button also uses. It previously carried its own copy of the logic and drifted:
 * it billed a calendar month for every active agency, ignored each agency's schedule, and
 * wrote no tax — so running the command and clicking the button produced different
 * invoices from identical data.
 *
 * DRY RUN BY DEFAULT, and deliberately NOT scheduled. A generator that starts raising
 * real invoices the moment it deploys is not something to switch on as a side effect.
 * Scheduling it is a decision to take once the plan amounts are actually set.
 *
 * --month is gone: agencies are billed on their own next_invoice_at, so "which month"
 * is no longer a thing the caller decides. Use --as-of to preview a future run.
 */
class RaisePlatformInvoices extends Command
{
    protected $signature = 'platform:raise-invoices
        {--commit : actually write the invoices (default is a dry run)}
        {--agency= : restrict to one agency id}
        {--as-of= : pretend today is this date (YYYY-MM-DD), for previewing}
        {--due-days=14 : days from today until the invoice falls due}';

    protected $description = 'Raise draft invoices for every agency whose billing date has arrived';

    public function handle(PlatformInvoiceRaiser $raiser): int
    {
        $commit = (bool) $this->option('commit');
        $agency = $this->option('agency') !== null ? (int) $this->option('agency') : null;
        $asOf = $this->option('as-of') ?: null;

        $plan = $raiser->plan($asOf, $agency);
        $billable = $raiser->billable($plan);

        $this->line(($commit ? 'COMMIT' : 'DRY RUN')
            . ($asOf ? ' — as of ' . $asOf : '')
            . ' — ' . count($billable) . ' of ' . count($plan) . ' agencies due');
        $this->line('');

        foreach ($plan as $p) {
            $name = mb_substr((string) $p['agency_name'], 0, 26);

            if ($p['skip_reason'] !== null) {
                $this->line(sprintf('  skip  %-26s %s', $name, $p['skip_reason']));
                continue;
            }

            $this->line(sprintf(
                '  bill  %-26s %s%s  %s  %s to %s',
                $name,
                PlatformBilling::money($p['subtotal_cents'], $p['currency']),
                $p['tax_cents'] > 0
                    ? ' + ' . PlatformBilling::money($p['tax_cents'], $p['currency'])
                        . ' ' . ($p['tax_label'] ?: 'tax')
                        . ' = ' . PlatformBilling::money($p['amount_cents'], $p['currency'])
                    : '',
                PlatformBilling::intervalLabel($p['interval']),
                $p['period_start'],
                $p['period_end']
            ));
        }

        if (! $commit) {
            $this->line('');
            $this->info('[dry run] nothing was written. Re-run with --commit.');

            return self::SUCCESS;
        }

        if (! $billable) {
            $this->line('');
            $this->info('Nothing due — no invoices raised.');

            return self::SUCCESS;
        }

        $result = $raiser->commit($billable, null, (int) $this->option('due-days'));

        $this->line('');
        $this->info(count($result['raised']) . ' invoice(s) raised as DRAFT: '
            . implode(', ', $result['raised']));

        if ($result['failed']) {
            /* Almost always the unique key on (agency_id, period_start) refusing a
               duplicate, which is the guard working rather than a fault. */
            $this->warn(count($result['failed']) . ' skipped as already billed: '
                . implode(', ', $result['failed']));
        }

        $this->line('Nothing has been sent. Issue and email them from Reseller → Invoices.');

        return self::SUCCESS;
    }
}
