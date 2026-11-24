<?php

namespace App\Console\Commands;

use App\Support\FoodPhotos;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill food photos on menus that were written before photos existed (2026-10-31).
 * Counts only, unless --agency and --apply are both given: real agencies are never changed by default.
 * Only items with no photo and not removed on purpose (image_source 'none') are touched; only confident local
 * matches (FoodPhotos::AUTO) are applied. Respects each agency's "Add food photos automatically" setting.
 */
class MenuPhotosCommand extends Command
{
    protected $signature = 'kt:menu-photos {--agency= : one agency} {--apply : write (needs --agency)} {--from= : week_start on/after (default: 8 weeks ago)}';
    protected $description = 'Count (or, for one agency with --apply, fill) food photos on existing weekly menus';

    public function handle(): int
    {
        $from = $this->option('from') ?: now()->subWeeks(8)->toDateString();
        $agency = $this->option('agency') ? (int) $this->option('agency') : null;
        $apply = (bool) $this->option('apply');
        if ($apply && ! $agency) {
            $this->error('--apply needs --agency');
            return self::FAILURE;
        }
        $q = DB::table('menu_items as i')->join('menu_weeks as w', 'w.id', '=', 'i.menu_week_id')
            ->join('centres as c', 'c.id', '=', 'w.centre_id')
            ->whereNull('i.image_url')->where(fn ($x) => $x->whereNull('i.image_source')->orWhere('i.image_source', '!=', 'none'))
            ->where('w.week_start', '>=', $from);
        if ($agency) $q->where('c.agency_id', $agency);
        $per = []; $done = 0; $names = [];
        foreach ($q->orderBy('i.id')->get(['i.id', 'i.name', 'c.agency_id']) as $it) {
            $a = (int) $it->agency_id;
            $per[$a] = $per[$a] ?? ['items' => 0, 'matchable' => 0, 'auto_on' => FoodPhotos::autoOn($a)];
            $per[$a]['items']++;
            $b = FoodPhotos::best((string) $it->name);
            if (! $b) { $names[$it->name] = ($names[$it->name] ?? 0) + 1; continue; }
            $per[$a]['matchable']++;
            if ($apply && $per[$a]['auto_on']) {
                DB::table('menu_items')->where('id', $it->id)->whereNull('image_url')
                    ->update(['image_url' => $b['thumb_url'], 'image_source' => 'auto', 'food_image_id' => $b['id']]);
                $done++;
            }
        }
        arsort($names);
        $this->line(json_encode(['from' => $from, 'per_agency' => $per, 'applied' => $done,
            'unmatched_top' => array_slice($names, 0, 25, true)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return self::SUCCESS;
    }
}
