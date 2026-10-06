<?php

namespace App\Console\Commands;

use App\Support\RsvpReminders;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Sends event reminders that have come due (RsvpReminders::runDue). Scheduled every five
 * minutes, so a "2 hours before" reminder goes out within five minutes of its time; the
 * mail itself is queued and leaves on the next queue tick.
 */
class RsvpSendRemindersCommand extends Command
{
    protected $signature = 'rsvp:send-reminders';

    protected $description = 'Send RSVP event reminders that are due';

    public function handle(): int
    {
        $lines = RsvpReminders::runDue();
        foreach ($lines as $l) {
            $this->line($l);
        }
        if ($lines) {
            Log::info('rsvp:send-reminders', ['handled' => $lines]);
        }

        return self::SUCCESS;
    }
}
