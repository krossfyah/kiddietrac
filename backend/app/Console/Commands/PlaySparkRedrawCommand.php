<?php

namespace App\Console\Commands;

use App\Support\PlaySparkPicture;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Redraw Play Spark pictures with the current drawing pipeline (2026-11-27, v2: plan, draw, check). For ONE agency at
 * a time, newest ideas first, a small number per run: every drawing is a paid AI call (~$0.30) and they queue on the
 * pictures worker one at a time (~2 minutes each). Old pictures keep showing until their new one is ready.
 *
 *   php8.3 artisan kt:play-spark-redraw --agency=2 [--limit=20] [--lessons] [--dry-run]
 *
 * --lessons also takes the agency's weekly-plan activity pictures (newest first, within the same limit).
 * Ideas with no picture yet get one queued. Nothing is sent to anyone.
 */
class PlaySparkRedrawCommand extends Command
{
    protected $signature = 'kt:play-spark-redraw {--agency= : the agency id (required)} {--limit=20 : how many at most}
        {--lessons : include weekly lesson-plan activity pictures} {--dry-run : list what would be redrawn}';

    protected $description = 'Redraw one agency\'s recent Play Spark pictures with the current drawing (v2)';

    public function handle(): int
    {
        $a = (int) $this->option('agency');
        $limit = max(1, min(100, (int) $this->option('limit')));
        if ($a <= 0) {
            $this->error('Give --agency=ID.');

            return 1;
        }
        if (! \App\Support\AiBundle::on($a) || \App\Support\AiConfig::offFor($a)) {
            $this->warn("AI is not on for agency $a: nothing to draw.");

            return 0;
        }
        $todo = [];
        $ideas = DB::table('play_spark_ideas')->where('agency_id', $a)->whereNull('deleted_at')->orderByDesc('id')->limit($limit)->get(['id', 'title', 'idea']);
        foreach ($ideas as $i) {
            $row = PlaySparkPicture::find($a, 'idea', (string) $i->id);
            if ($row && $row->status === 'ready' && (int) ($row->version ?? 1) >= PlaySparkPicture::VERSION) {
                continue;
            }
            $todo[] = ['idea', (string) $i->id, $i->title, (json_decode((string) $i->idea, true) ?: []) + ['title' => $i->title], $row ? $row->status : 'none'];
        }
        if ($this->option('lessons') && count($todo) < $limit) {
            $rows = DB::table('play_spark_pictures')->where('agency_id', $a)->where('kind', 'lesson')->where('status', 'ready')
                ->where('version', '<', PlaySparkPicture::VERSION)->orderByDesc('id')->limit($limit - count($todo))->get();
            foreach ($rows as $r) {
                $src = json_decode((string) $r->source, true) ?: [];
                $todo[] = ['lesson', (string) $r->ref, (string) ($src['title'] ?? $r->ref), $src, 'ready'];
            }
        }
        $todo = array_slice($todo, 0, $limit);
        foreach ($todo as [$kind, $ref, $title, $src, $st]) {
            $this->line(($this->option('dry-run') ? '[dry] ' : '') . "$kind $ref ($st): $title");
            if (! $this->option('dry-run')) {
                PlaySparkPicture::redraw($a, $kind, $ref, $src);
            }
        }
        $this->info(count($todo) . ' picture(s) ' . ($this->option('dry-run') ? 'would be' : 'were') . ' queued on the pictures worker.');

        return 0;
    }
}
