<?php

namespace App\Console\Commands;

use App\Support\ExternalCalendar;
use Illuminate\Console\Command;

/**
 * Attendees' answers to meetings whose invitations went out from the organiser's own Outlook / Google calendar
 * (2026-11-25, App\Support\ExternalCalendar). Read back into meeting_attendees so KiddieTrac's meeting shows who is
 * coming. Runs in kt:minute ten; reads at most 40 meetings a run, the longest-unread first. Reads only - nothing is
 * sent to anyone.
 */
class CalendarResponsesCommand extends Command
{
    protected $signature = 'kt:calendar-responses {--limit=40}';

    protected $description = 'Read attendees\' answers from connected Outlook / Google calendars';

    public function handle(): int
    {
        $n = ExternalCalendar::readResponses(max(1, min(200, (int) $this->option('limit'))));
        $this->info($n . ' answer' . ($n === 1 ? '' : 's') . ' updated.');
        return self::SUCCESS;
    }
}
