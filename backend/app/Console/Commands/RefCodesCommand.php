<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\RefCode;
use Illuminate\Console\Command;

/** Give every child, family, staff member and parent without a reference ID its number. See App\Support\RefCode. */
class RefCodesCommand extends Command
{
    protected $signature = 'kiddietrac:ref-codes {--agency= : one agency only}';

    protected $description = 'Assign visible reference IDs (ILH-C-0042) to records that have none yet';

    public function handle(): int
    {
        $n = RefCode::sweep($this->option('agency') ? (int) $this->option('agency') : null);
        if (array_sum($n)) {
            $this->line('numbered: ' . json_encode($n));
        }

        return self::SUCCESS;
    }
}
