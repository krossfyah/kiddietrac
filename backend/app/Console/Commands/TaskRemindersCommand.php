<?php

namespace App\Console\Commands;

use App\Support\TaskReminders;
use Illuminate\Console\Command;

/** Sends task reminders whose moment has come (App\Support\TaskReminders). Every 5 minutes. */
class TaskRemindersCommand extends Command
{
    protected $signature = 'kiddietrac:task-reminders {--dry : List what would be sent without sending}';
    protected $description = 'Send due task reminders (app, email, text, voice)';

    public function handle(): int
    {
        $due = TaskReminders::due();
        foreach ($due as $t) {
            $r = TaskReminders::send($t, (bool) $this->option('dry'));
            $this->line('task ' . $t->id . ' (agency ' . $t->agency_id . '): ' . json_encode($r));
        }
        $this->info(count($due) . ' due' . ($this->option('dry') ? ' (dry run, nothing sent)' : ''));
        return self::SUCCESS;
    }
}
