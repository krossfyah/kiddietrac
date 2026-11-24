<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\PlatformExpenseController;
use Illuminate\Console\Command;

/** Adds KiddieTrac's recurring expenses on their dates (Sales → Expenses, 2026-10-24). */
class ExpensesRecurringCommand extends Command
{
    protected $signature = 'kt:expenses-recurring {--dry : List what would be added without adding it}';
    protected $description = 'Add recurring platform expenses that are due';

    public function handle(): int
    {
        $made = PlatformExpenseController::runRecurring((bool) $this->option('dry'));
        foreach ($made as $m) $this->line(($this->option('dry') ? '[dry] ' : '') . $m['date'] . ' ' . ($m['vendor'] ?: 'recurring #' . $m['recurring_id']) . ' ' . number_format($m['cents'] / 100, 2));
        $this->info(count($made) . ' expense(s) ' . ($this->option('dry') ? 'would be added' : 'added'));
        return self::SUCCESS;
    }
}
