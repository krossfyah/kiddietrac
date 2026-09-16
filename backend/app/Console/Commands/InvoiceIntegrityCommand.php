<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Invoices whose status and balance contradict each other.
 *
 * Anthony chose, 2026-09-04, to change no figures and list these instead, so they can be
 * corrected at source in iLearn rather than reinterpreted here. Nothing in this command
 * writes anything.
 *
 * Why it matters: `AccountLedgerController::stillDue()` trusts the STATUS word — an
 * invoice that is not open owes nothing, whatever its balance column says. That is a
 * defensible rule and it is the rule the whole platform uses, but where the two disagree
 * it means a real balance is being reported as settled. External invoice #97 is marked
 * `paid` while carrying $166.32 unpaid.
 *
 * Three shapes, and they are different problems:
 *
 *   SETTLED WITH A BALANCE   status says paid/void, balance_due says money is owed.
 *                            The ledger reports it as collected; somebody may owe money
 *                            nobody is asking for.
 *   OPEN WITH NO BALANCE     status says open/overdue, balance_due is zero. Chased as
 *                            outstanding when there is nothing to chase.
 *   OVERPAID                 amount_paid exceeds total. Either a credit the family can
 *                            draw on or a payment recorded twice at source — and those
 *                            need opposite actions, which is exactly why a person has to
 *                            look rather than a rule guessing.
 */
final class InvoiceIntegrityCommand extends Command
{
    protected $signature = 'invoices:integrity
                            {--agency= : Limit to one agency}
                            {--check : Exit non-zero if anything disagrees, for CI}';

    protected $description = 'List invoices whose status, balance and amount paid contradict each other';

    public function handle(): int
    {
        $agency = $this->option('agency') ? (int) $this->option('agency') : null;
        $found = 0;

        foreach ([
            ['external_invoices', 'number', ['void', 'voided', 'cancelled'], ['open', 'overdue', 'unpaid', 'sent']],
            ['invoices', 'invoice_number', ['void', 'cancelled'], ['sent', 'overdue', 'partial', 'draft']],
        ] as [$table, $numberCol, $voidWords, $openWords]) {
            $q = DB::table($table);
            if ($agency && in_array('agency_id', DB::getSchemaBuilder()->getColumnListing($table), true)) {
                $q->where('agency_id', $agency);
            }
            $rows = $q->get(['id', $numberCol . ' as number', 'family_id', 'total', 'amount_paid', 'balance_due', 'status', 'due_at']);

            $buckets = ['Settled, but a balance is owed' => [], 'Open, but nothing is owed' => [], 'More received than billed' => []];

            foreach ($rows as $r) {
                $status = strtolower(trim((string) $r->status));
                $total = (float) $r->total;
                $paid = (float) ($r->amount_paid ?? 0);
                $bal = (float) ($r->balance_due ?? 0);
                $isVoid = in_array($status, $voidWords, true);
                $isOpen = in_array($status, $openWords, true);

                if (! $isOpen && ! $isVoid && $bal > 0.005) {
                    $buckets['Settled, but a balance is owed'][] = [$r, $bal];
                } elseif ($isOpen && $bal <= 0.005 && $total > 0.005) {
                    $buckets['Open, but nothing is owed'][] = [$r, 0.0];
                }
                if (! $isVoid && $paid - $total > 0.005) {
                    $buckets['More received than billed'][] = [$r, round($paid - $total, 2)];
                }
            }

            $any = array_sum(array_map('count', $buckets));
            $this->newLine();
            $this->line('<options=bold>' . $table . '</> — ' . $rows->count() . ' invoices, ' . $any . ' disagreeing');

            foreach ($buckets as $label => $items) {
                if (! $items) {
                    continue;
                }
                $found += count($items);
                $this->newLine();
                $this->line('  <fg=yellow>' . $label . '</> (' . count($items) . ')');
                $this->table(
                    ['id', 'number', 'family', 'status', 'total', 'paid', 'balance', 'at stake'],
                    array_map(function ($x) {
                        [$r, $amount] = $x;

                        return [
                            $r->id,
                            (string) ($r->number ?: '—'),
                            $r->family_id,
                            $r->status,
                            number_format((float) $r->total, 2),
                            number_format((float) ($r->amount_paid ?? 0), 2),
                            number_format((float) ($r->balance_due ?? 0), 2),
                            $amount > 0 ? '$' . number_format($amount, 2) : '',
                        ];
                    }, array_slice($items, 0, 25))
                );
                if (count($items) > 25) {
                    $this->line('    …and ' . (count($items) - 25) . ' more.');
                }
            }
        }

        $this->newLine();
        if ($found === 0) {
            $this->info('Every invoice agrees with itself.');

            return self::SUCCESS;
        }

        $this->warn($found . ' invoice(s) contradict themselves.');
        $this->line('No figures were changed. These are corrected at source — the sync overwrites');
        $this->line('status, amount_paid and balance_due on every run, so fixing them here would not last.');

        return $this->option('check') ? self::FAILURE : self::SUCCESS;
    }
}
