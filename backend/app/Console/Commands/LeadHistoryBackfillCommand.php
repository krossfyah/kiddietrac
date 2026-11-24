<?php

namespace App\Console\Commands;

use App\Support\LeadHistory;
use Illuminate\Console\Command;

/**
 * Put on each live lead's history what never reached it (2026-11-05, Anthony: "backfill anything with leads that
 * wasn't updated"): texts on the 1-855 line, and the last --days of email from leads in the Sales mailboxes.
 * Safe to run again: sales_lead_filed remembers what was filed. --dry-run lists, writes nothing.
 */
class LeadHistoryBackfillCommand extends Command
{
    protected $signature = 'kt:lead-history-backfill {--dry-run} {--days=90}';
    protected $description = 'File texts and emails from live leads that never reached their history';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $texts = LeadHistory::backfillTexts($dry);
        foreach ($texts as [$sms, $lead, $dir]) $this->line('text  sms#' . $sms . ' (' . $dir . ') -> lead ' . $lead);
        [$mail, $errors] = LeadHistory::backfillEmail($dry, max(1, (int) $this->option('days')));
        foreach ($mail as [$acct, $lead, $at]) $this->line('email ' . $at . ' (mailbox ' . $acct . ') -> lead ' . $lead);
        foreach ($errors as $e) $this->warn('mailbox ' . $e);
        $this->info(count($texts) . ' text(s), ' . count($mail) . ' email(s) ' . ($dry ? 'to file (dry run, nothing written)' : 'filed'));

        return self::SUCCESS;
    }
}
