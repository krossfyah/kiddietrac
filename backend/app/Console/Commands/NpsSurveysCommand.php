<?php

namespace App\Console\Commands;

use App\Support\NpsSurvey;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * NPS surveys (2026-11-28), in the kt:minute "five" group: sends the pending invites of any round (a "Send now" or a
 * scheduled one), and once a day starts the rounds that are due (agency setting on, every N months, never a test
 * agency, plan has parent feedback). --dry-run lists what is due and pending without writing or sending.
 */
class NpsSurveysCommand extends Command
{
    protected $signature = 'kiddietrac:nps-surveys {--dry-run}';
    protected $description = 'Start due NPS survey rounds (daily) and send pending NPS invites';

    public function handle(): int
    {
        if ($this->option('dry-run')) {
            foreach (\Illuminate\Support\Facades\DB::table('agencies')->whereNull('deleted_at')->pluck('id') as $aid) {
                $s = NpsSurvey::settings((int) $aid);
                if ($s['enabled']) $this->line("agency $aid: on, every {$s['every_months']} months, next due {$s['next_due']}");
            }
            $this->line('pending invites: ' . \Illuminate\Support\Facades\DB::table('nps_invites')->where('status', 'pending')->count());

            return self::SUCCESS;
        }
        $day = now()->toDateString();
        if (! Cache::get('nps-surveys:day') || Cache::get('nps-surveys:day') !== $day) {
            Cache::put('nps-surveys:day', $day, 86400 * 2);
            foreach (NpsSurvey::startDue() as $aid => $r) $this->line("agency $aid: round {$r['survey_id']} started for {$r['invited']} people");
        }
        $d = NpsSurvey::deliver();
        if (array_sum($d)) $this->line(json_encode($d));

        return self::SUCCESS;
    }
}
