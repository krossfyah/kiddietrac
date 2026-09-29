<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\AnnouncementController;
use Illuminate\Console\Command;

/* Scheduled announcements (2026-09-29). The composer has offered "Schedule for later"
   for months, and the rows were stored, but nothing ever sent them. Runs every minute;
   the delivery itself is AnnouncementController::deliverDue, the same deliver() a
   "send now" uses, so a scheduled announcement cannot drift from an immediate one. */
class SendScheduledAnnouncements extends Command
{
    protected $signature = 'announcements:send-scheduled';

    protected $description = 'Send scheduled announcements whose time has come';

    public function handle(): int
    {
        foreach (app(AnnouncementController::class)->deliverDue() as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }
}
