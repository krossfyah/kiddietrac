<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\ProposalFollowUp;
use Illuminate\Console\Command;

/** kt:proposal-followups — send automatic proposal follow-ups that are due (App\Support\ProposalFollowUp). */
final class ProposalFollowupsCommand extends Command
{
    protected $signature = 'kt:proposal-followups {--dry}';
    protected $description = 'Email due proposal follow-ups (weekdays 9-5 ET) and stop those for signed/declined proposals';

    public function handle(): int
    {
        $r = ProposalFollowUp::run((bool) $this->option('dry'));
        $this->line(($this->option('dry') ? '[dry] ' : '') . 'sent ' . $r['sent'] . ', stopped ' . $r['stopped'] . ', waiting ' . $r['waiting']);
        return self::SUCCESS;
    }
}
