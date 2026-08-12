<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ApplyWithdrawals extends Command
{
    protected $signature = 'kiddietrac:apply-withdrawals';

    protected $description = 'Apply approved child withdrawals whose effective (last) day has arrived.';

    public function handle(): int
    {
        $due = DB::table('withdrawal_requests')
            ->where('status', 'approved')
            ->whereNull('applied_at')
            ->whereNotNull('effective_date')
            ->whereDate('effective_date', '<=', now()->toDateString())
            ->pluck('id');

        $ctl = app(\App\Http\Controllers\Api\WithdrawalController::class);
        $n = 0;
        foreach ($due as $id) {
            try { $ctl->applyWithdrawal((int) $id); $n++; }
            catch (\Throwable $e) { $this->error('Withdrawal ' . $id . ': ' . $e->getMessage()); }
        }
        $this->info("Applied {$n} due withdrawal(s).");
        return self::SUCCESS;
    }
}
