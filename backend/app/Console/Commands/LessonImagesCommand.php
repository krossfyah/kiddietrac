<?php

namespace App\Console\Commands;

use App\Support\LessonImages;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill pictures on lesson plans written before pictures existed (2026-11-02) - see App\Support\LessonImages.
 * Counts only, unless --agency and --apply are both given: real agencies are never changed by default.
 * Only activities with no picture and not removed on purpose (image_source 'none') are touched, and only for agencies
 * whose "Add pictures automatically" is on. --probe=N looks up N distinct keyword terms (no AI, no plan is written; the
 * shared lesson_images library is filled) to estimate how many activities would get a picture.
 */
class LessonImagesCommand extends Command
{
    protected $signature = 'kt:lesson-images {--agency= : one agency} {--apply : write (needs --agency)}
        {--from= : week_starting on/after (default: this week)} {--probe=0 : look up this many distinct terms to estimate the hit rate}';
    protected $description = 'Count (or, for one agency with --apply, fill) pictures on existing lesson plans';

    public function handle(): int
    {
        $from = $this->option('from') ?: now()->startOfWeek()->toDateString();
        $agency = $this->option('agency') ? (int) $this->option('agency') : null;
        $apply = (bool) $this->option('apply');
        if ($apply && ! $agency) {
            $this->error('--apply needs --agency');
            return self::FAILURE;
        }

        $agencies = $agency ? [$agency] : DB::table('agencies')->pluck('id')->map(fn ($i) => (int) $i)->all();
        $per = []; $terms = [];
        foreach ($agencies as $a) {
            $ids = LessonImages::planIdsForAgency($a, $from);
            if (! $ids) continue;
            $row = ['plans' => count($ids), 'activities' => 0, 'with_picture' => 0, 'removed' => 0, 'routine_skipped' => 0,
                'missing' => 0, 'auto_on' => LessonImages::autoOn($a), 'filled' => 0];
            foreach ($ids as $id) {
                $p = DB::table('lesson_plans')->where('id', $id)->first(['plan_data', 'theme']);
                foreach ((array) ((json_decode((string) $p->plan_data, true) ?: [])['days'] ?? []) as $acts) {
                    foreach ((array) $acts as $x) {
                        if (! is_array($x) || trim((string) ($x['title'] ?? '')) === '') continue;
                        $row['activities']++;
                        if (! LessonImages::wants($x)) { ($x['image_source'] ?? null) === 'none' ? $row['removed']++ : $row['with_picture']++; continue; }
                        if (LessonImages::skippable((string) $x['title'])) { $row['routine_skipped']++; continue; }
                        $row['missing']++;
                        foreach (array_slice(LessonImages::keywords((string) $x['title'], $x['notes'] ?? null, $p->theme), 0, 1) as $t) {
                            $terms[$t] = ($terms[$t] ?? 0) + 1;
                        }
                    }
                }
                if ($apply && $row['auto_on']) {
                    $r = LessonImages::fillPlan($id, true, 120);
                    $row['filled'] += $r['filled'];
                }
            }
            $per[$a] = $row;
        }
        arsort($terms);

        $probe = null;
        if ((int) $this->option('probe') > 0) {
            $hit = 0; $tried = 0; $hits = []; $misses = [];
            foreach (array_slice(array_keys($terms), 0, (int) $this->option('probe')) as $t) {
                $tried++;
                $img = LessonImages::find($t, null, $t);
                if ($img) { $hit++; $hits[$t] = $img['title']; } else { $misses[] = $t; }
            }
            $probe = ['tried' => $tried, 'found' => $hit, 'hits' => $hits, 'misses' => $misses];
        }

        $this->line(json_encode(['from' => $from, 'per_agency' => $per, 'applied' => $apply,
            'distinct_terms' => count($terms), 'top_terms' => array_slice($terms, 0, 25, true), 'probe' => $probe],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }
}
