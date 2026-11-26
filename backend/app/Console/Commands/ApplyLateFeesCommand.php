<?php
declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v22p51 — Per-agency late-fee config.
 * Joins invoices → centres to find agency, reads percent/cap/grace from
 * agencies row. Mirrors v22p49 schema (invoice_lines no created_at,
 * audit_logs uses user_id, notifications uses data column).
 */
final class ApplyLateFeesCommand extends Command
{
    protected $signature = 'invoices:apply-late-fees {--dry-run}';
    protected $description = 'Apply per-agency late fees to overdue invoices (idempotent per month).';

    public function handle(): int
    {
        $today = \App\Support\AgencyTime::todayDate();
        $month = $today->format('Y-m');

        // join centres so we can read agency settings per-invoice
        $candidates = DB::table('invoices as i')
            ->join('centres as c', 'c.id', '=', 'i.centre_id')
            ->join('agencies as a', 'a.id', '=', 'c.agency_id')
            ->whereIn('i.status', ['sent', 'partial', 'overdue'])
            ->where('i.balance_due', '>', 0)
            ->select(
                'i.id', 'i.invoice_number', 'i.balance_due', 'i.total',
                'i.status', 'i.due_at', 'i.family_id',
                'c.agency_id',
                'a.late_fee_percent', 'a.late_fee_cap', 'a.late_fee_grace_days', 'a.name as agency_name'
            )
            ->get();

        $applied = 0; $skipped = 0; $promoted = 0;
        foreach ($candidates as $inv) {
            if (\App\Support\Features::skips((int) $inv->agency_id, 'billing', 'late fees')) { $skipped++; continue; }   // not in the agency's plan (2026-11-26)
            // The family says they paid it by e-Transfer and a person has not confirmed it yet (2026-11-11): no late fee meanwhile.
            if (\App\Support\EtransferPending::has((int) $inv->agency_id, 'inv', (int) $inv->id)) { $skipped++; continue; }
            $pct = (float) ($inv->late_fee_percent ?? 1.50);
            $cap = (float) ($inv->late_fee_cap ?? 25.00);
            $grace = (int) ($inv->late_fee_grace_days ?? 0);
            if ($pct <= 0) { $skipped++; continue; }

            $cutoff = $today->copy()->subDays($grace);
            if (Carbon::parse($inv->due_at)->gte($cutoff)) { $skipped++; continue; }

            $exists = DB::table('invoice_lines')
                ->where('invoice_id', $inv->id)
                ->where('line_type', 'late_fee')
                ->where('description', 'like', "%{$month}%")
                ->exists();
            if ($exists) { $skipped++; continue; }

            $fee = round(min($cap, ((float) $inv->balance_due) * ($pct / 100)), 2);
            /* SPLIT BILLING (2026-10-30): the fee is charged on each guardian's own unpaid share and is theirs alone
               (invoice_lines.payer_guardian_id) - a parent who paid on time is never charged for the other's lateness. */
            $perPayer = [];
            $full = DB::table('invoices')->where('id', $inv->id)->first();
            if ($full && ($sh = \App\Support\BillingSplit::shares($full))) {
                foreach ($sh as $s) {
                    if ($s['balance'] <= 0.004) continue;
                    $f = round(min($cap, $s['balance'] * ($pct / 100)), 2);
                    if ($f >= 0.01) $perPayer[] = ['guardian_id' => $s['guardian_id'], 'name' => $s['name'], 'fee' => $f];
                }
                $fee = round(array_sum(array_column($perPayer, 'fee')), 2);
            }
            if ($fee < 0.01) { $skipped++; continue; }

            if ($this->option('dry-run')) {
                $this->line(sprintf('  inv #%s (%s) bal=$%s -> +$%.2f', $inv->invoice_number, $inv->agency_name, $inv->balance_due, $fee));
                $applied++;
                continue;
            }

            DB::transaction(function () use ($inv, $fee, $month, $perPayer, &$applied, &$promoted) {
                foreach (($perPayer ?: [null]) as $pp) {
                    DB::table('invoice_lines')->insert([
                        'invoice_id' => $inv->id,
                        'description' => "Late fee · {$month}" . ($pp ? ' · ' . explode(' ', (string) $pp['name'])[0] : ''),
                        'line_type' => 'late_fee',
                        'quantity' => 1,
                        'unit_amount' => $pp ? $pp['fee'] : $fee,
                        'amount' => $pp ? $pp['fee'] : $fee,
                        'payer_guardian_id' => $pp ? $pp['guardian_id'] : null,
                    ]);
                }
                $update = [
                    'total' => (float) $inv->total + $fee,
                    'balance_due' => (float) $inv->balance_due + $fee,
                    'updated_at' => now(),
                ];
                if ($inv->status === 'sent') { $update['status'] = 'overdue'; $promoted++; }
                DB::table('invoices')->where('id', $inv->id)->update($update);

                // The invoice knows its family, the family its centre, the centre its
                // agency — resolved here because a console row is stamped by nobody else,
                // and a late fee is exactly the kind of money event an agency needs to be
                // able to find afterwards.
                $feeAgencyId = DB::table('families as f')
                    ->join('centres as c', 'c.id', '=', 'f.centre_id')
                    ->where('f.id', $inv->family_id)
                    ->value('c.agency_id');

                \App\Support\Audit::write([
                    'user_id' => null,
                    'agency_id' => $feeAgencyId ? (int) $feeAgencyId : null,
                    'action' => 'invoice.late_fee_applied',
                    'entity_type' => 'invoice',
                    'entity_id' => $inv->id,
                    'payload' => json_encode(['fee' => $fee, 'month' => $month]),
                    'created_at' => now(),
                ]);

                $guardianIds = DB::table('guardians')->where('family_id', $inv->family_id)->pluck('user_id');
                foreach ($guardianIds as $gid) {
                    \App\Support\Notify::write([
                        'user_id' => $gid,
                        'type' => 'late_fee',
                        'title' => "Late fee applied to invoice {$inv->invoice_number}",
                        'body' => '$' . number_format($fee, 2) . ' was added to your overdue invoice.',
                        'data' => json_encode(['invoice_id' => $inv->id]),
                        'created_at' => now(),
                    ]);
                }
                $applied++;
            });
        }
        $this->info("Applied {$applied} late fees (skipped {$skipped}, status-promoted {$promoted})");
        return 0;
    }
}
