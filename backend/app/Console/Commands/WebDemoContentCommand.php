<?php

namespace App\Console\Commands;

use App\Support\WebDemoContent;
use Illuminate\Console\Command;

/**
 * kt:web-demo-content - (re)write the lived-in baseline of the public website demo agencies (App\Support\WebDemoContent):
 * photos and videos, menus, lesson plans, announcements, an incident, observations, itemised invoices. Idempotent; the
 * nightly kt:reset-web-demo runs it too, so the dates roll forward. Also: kt:seed-brightwood-demo --content and
 * kt:seed-starlight-demo --content.
 */
class WebDemoContentCommand extends Command
{
    protected $signature = 'kt:web-demo-content {--agency=* : only these public demo agencies} {--dry-run : show what it would work on, change nothing}';
    protected $description = 'Write the website demo content (photos, videos, menus, lesson plans, invoices...) for the public demo agencies';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $only = array_map('intval', (array) $this->option('agency'));
        $res = $only ? array_combine($only, array_map(fn ($a) => WebDemoContent::seedAgency($a, $dry), $only)) : WebDemoContent::run($dry);
        foreach ($res as $id => $r) $this->line("agency {$id}: " . json_encode($r));
        return self::SUCCESS;
    }
}
