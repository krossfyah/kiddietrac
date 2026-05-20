<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v22p49 — Apply late fees to invoices past due.
 *
 * Rules: any invoice with status IN (sent, partial, overdue) AND due_at
 * before today gets:
 *   - status flipped to 'overdue' (if still 'sent')
 *   - a one-time late-fee invoice_lines row added if not already present
 *   - balance_due recomputed
 *
 * Fee is 1.5% of the current balance_due (rounded to nearest cent),
 * capped at $25.00. Idempotent — re-running the same day is a no-op
 * because we look for an existing 'late_fee' line per invoice + period.
 *
 * Schedule: every day at 02:00. Routes/console.php gets the cron entry.
 */
final class ApplyLateFeesCommand extends Command
{
    protected $signature = 'kiddietrac:late-fees {--dry-run : Print actions, do not write}';
    protected $description = 'Mark overdue invoices and append a late-fee line';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $today = Carbon::now()->startOfDay();
        $period = $today->format('Y-m');

        $candidates = DB::table('invoices')
            ->whereIn('status', ['sent', 'partial', 'overdue'])
            ->where('due_at', '<', $today->toDateString())
            ->where('balance_due', '>', 0)
            ->get();

        $this->info("Found {$candidates->count()} candidate invoice(s) past due (dry=" . ($dry ? 'yes' : 'no') . ')');

        $applied = 0; $promoted = 0; $skipped = 0;
        foreach ($candidates as $inv) {
            $existingFee = DB::table('invoice_lines')
                ->where('invoice_id', $inv->id)
                ->where('line_type', 'late_fee')
                ->where('description', 'like', '%' . $period . '%')
                ->exists();
            if ($existingFee) { $skipped++; continue; }

            $fee = round(min(25.0, ((float) $inv->balance_due) * 0.015), 2);
            if ($fee <= 0) { $skipped++; continue; }

            if ($dry) {
                $this->line(sprintf('  inv #%d (%s) bal=$%s -> +$%.2f late fee',
                    $inv->id, $inv->invoice_number, $inv->balance_due, $fee));
                $applied++;
                continue;
            }

            DB::transaction(function () use ($inv, $fee, $period, &$applied, &$promoted) {
                DB::table('invoice_lines')->insert([
                    'invoice_id' => $inv->id,
                    'description' => 'Late fee · ' . $period,
                    'line_type' => 'late_fee',
                    'quantity' => 1,
                    'unit_amount' => $fee,
                    'amount' => $fee,
                ]);
                $newTotal = (float) $inv->total + $fee;
                $newBalance = (float) $inv->balance_due + $fee;
                $update = [
                    'total' => $newTotal,
                    'balance_due' => $newBalance,
                    'updated_at' => now(),
                ];
                if ($inv->status === 'sent') {
                    $update['status'] = 'overdue';
                    $promoted++;
                }
                DB::table('invoices')->where('id', $inv->id)->update($update);
                $applied++;
            });

            // Notify the family's guardians (in-portal inbox)
            $guardianIds = DB::table('guardians')->where('family_id', $inv->family_id)->pluck('user_id')->all();
            if (!empty($guardianIds)) {
                $rows = [];
                foreach ($guardianIds as $gid) {
                    $rows[] = [
                        'user_id' => (int) $gid,
                        'type' => 'invoice',
                        'title' => 'Late fee added — ' . $inv->invoice_number,
                        'body' => '$' . number_format($fee, 2) . ' late fee applied. View invoice for details.',
                        'data' => json_encode(['url' => '/dashboard.html#billing', 'invoice_id' => (int) $inv->id]),
                        'created_at' => now(),
                    ];
                }
                DB::table('notifications')->insert($rows);
            }
            DB::table('audit_logs')->insert([
                'user_id' => null,
                'action' => 'invoice.late_fee_applied',
                'entity_type' => 'invoice',
                'entity_id' => (int) $inv->id,
                'payload' => json_encode(['fee' => $fee, 'period' => $period]),
                'created_at' => now(),
            ]);
        }

        $this->info("Done. applied=$applied promoted_to_overdue=$promoted skipped=$skipped");
        return self::SUCCESS;
    }
}
