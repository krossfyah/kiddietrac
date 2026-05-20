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
        $today = Carbon::now()->startOfDay();
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
            if ($fee < 0.01) { $skipped++; continue; }

            if ($this->option('dry-run')) {
                $this->line(sprintf('  inv #%s (%s) bal=$%s -> +$%.2f', $inv->invoice_number, $inv->agency_name, $inv->balance_due, $fee));
                $applied++;
                continue;
            }

            DB::transaction(function () use ($inv, $fee, $month, &$applied, &$promoted) {
                DB::table('invoice_lines')->insert([
                    'invoice_id' => $inv->id,
                    'description' => "Late fee · {$month}",
                    'line_type' => 'late_fee',
                    'quantity' => 1,
                    'unit_amount' => $fee,
                    'amount' => $fee,
                ]);
                $update = [
                    'total' => (float) $inv->total + $fee,
                    'balance_due' => (float) $inv->balance_due + $fee,
                    'updated_at' => now(),
                ];
                if ($inv->status === 'sent') { $update['status'] = 'overdue'; $promoted++; }
                DB::table('invoices')->where('id', $inv->id)->update($update);

                DB::table('audit_logs')->insert([
                    'user_id' => null,
                    'action' => 'invoice.late_fee_applied',
                    'entity_type' => 'invoice',
                    'entity_id' => $inv->id,
                    'payload' => json_encode(['fee' => $fee, 'month' => $month]),
                    'created_at' => now(),
                ]);

                $guardianIds = DB::table('guardians')->where('family_id', $inv->family_id)->pluck('user_id');
                foreach ($guardianIds as $gid) {
                    DB::table('notifications')->insert([
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
