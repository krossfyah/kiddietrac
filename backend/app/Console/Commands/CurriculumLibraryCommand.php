<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\CurriculumUnits;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Load KiddieTrac's Ontario curriculum library (2026-11-03).
 *
 *   php8.3 artisan kt:curriculum-library            check, then insert/update the library units
 *   php8.3 artisan kt:curriculum-library --check    validate + safety lint only, write nothing
 *   php8.3 artisan kt:curriculum-library --lint     also list every "review" word (not just hard failures)
 *
 * Source: resources/kt/curriculum-library.json (a JSON array of units, see App\Support\CurriculumUnits).
 * IDEMPOTENT: units are matched on slug within the library (agency_id 0, kind 'unit'); a unit is
 * rewritten only when its content changed (content_version = crc32 of the cleaned JSON), so
 * running it twice changes nothing. Agency copies are never touched. A unit with a structural
 * problem, or an under-3 unit with a hard choking-hazard word, is NOT loaded.
 */
final class CurriculumLibraryCommand extends Command
{
    protected $signature = 'kt:curriculum-library {--check : validate only, write nothing} {--lint : list review words too} {--file= : another JSON file}';

    protected $description = 'Load / update the Ontario curriculum library units (idempotent, matched on slug)';

    public function handle(): int
    {
        $file = $this->option('file') ?: base_path('resources/kt/curriculum-library.json');
        $raw = @file_get_contents($file);
        $list = $raw ? json_decode($raw, true) : null;
        if (! is_array($list)) {
            $this->error('Cannot read a JSON array from ' . $file);
            return self::FAILURE;
        }
        $check = (bool) $this->option('check');
        $seen = [];
        $bad = 0;
        $ins = 0; $upd = 0; $same = 0;
        foreach ($list as $i => $u0) {
            $u = CurriculumUnits::clean(is_array($u0) ? $u0 : []);
            $label = sprintf('#%d %s (%s)', $i + 1, $u['title'], $u['slug'] ?: 'no slug');
            $problems = CurriculumUnits::problems($u);
            if ($u['slug'] !== '' && isset($seen[$u['slug']])) $problems[] = 'duplicate slug';
            $seen[$u['slug']] = true;
            $hits = CurriculumUnits::lint($u);
            $hard = array_values(array_filter($hits, fn ($h) => $h['level'] === 'hard'));
            foreach ($hard as $h) $problems[] = "hazard word '{$h['word']}' in {$h['where']}: {$h['text']}";
            if ($this->option('lint')) {
                foreach ($hits as $h) if ($h['level'] === 'review') $this->line("   review  $label  '{$h['word']}'  {$h['where']}: {$h['text']}");
            }
            if ($problems) {
                $bad++;
                $this->warn("SKIP $label");
                foreach ($problems as $p) $this->line('   - ' . $p);
                continue;
            }
            $cols = CurriculumUnits::columns($u);
            $version = crc32($cols['unit_data']);
            $row = DB::table('curriculum_templates')->where('kind', 'unit')->where('agency_id', 0)->where('slug', $u['slug'])->first();
            if ($row && (int) $row->content_version === $version) { $same++; continue; }
            if ($check) { $row ? $upd++ : $ins++; continue; }
            if ($row) {
                DB::table('curriculum_templates')->where('id', $row->id)->update($cols + ['content_version' => $version, 'visibility' => 'platform', 'updated_at' => now()]);
                $upd++;
            } else {
                DB::table('curriculum_templates')->insert($cols + [
                    'agency_id' => 0, 'centre_id' => null, 'visibility' => 'platform', 'is_featured' => 0, 'use_count' => 0,
                    'content_version' => $version, 'created_by_user_id' => null, 'created_at' => now(), 'updated_at' => now()]);
                $ins++;
            }
        }
        $total = DB::table('curriculum_templates')->where('kind', 'unit')->where('agency_id', 0)->count();
        $this->info(sprintf('%s%d new, %d updated, %d unchanged, %d skipped. Library units now: %d.',
            $check ? '[check] would be: ' : '', $ins, $upd, $same, $bad, $total));

        return $bad ? self::FAILURE : self::SUCCESS;
    }
}
