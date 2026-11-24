<?php

namespace App\Console\Commands;

use App\Support\FoodPhotos;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Load / refresh the shared food photo library (App\Support\FoodPhotos) from
 * storage/app/public/food-library/library.json, written with the images by the library builder
 * (each row: slug, name, synonyms, allergens, category, file, thumb, source_url, title, author, licence, licence_url).
 * Idempotent: upserts by slug; a slug no longer in the file is switched off, never deleted (menus may point at it).
 */
class FoodLibraryCommand extends Command
{
    protected $signature = 'kt:food-library {--dry-run : count only}';
    protected $description = 'Load the food photo library for weekly menus from storage/app/public/food-library/library.json';

    public function handle(): int
    {
        $path = storage_path('app/public/food-library/library.json');
        $rows = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (! is_array($rows) || ! $rows) {
            $this->error('No library.json at ' . $path);
            return self::FAILURE;
        }
        $dir = dirname($path);
        $ok = 0; $missing = []; $slugs = [];
        foreach ($rows as $r) {
            $slug = (string) ($r['slug'] ?? '');
            if ($slug === '' || ! is_file($dir . '/' . $r['file']) || ! is_file($dir . '/' . $r['thumb'])) { $missing[] = $slug ?: '?'; continue; }
            $slugs[] = $slug;
            if ($this->option('dry-run')) { $ok++; continue; }
            $vals = [
                'name' => mb_substr((string) $r['name'], 0, 120),
                'synonyms' => (string) ($r['synonyms'] ?? ''),
                'allergens' => mb_substr((string) ($r['allergens'] ?? ''), 0, 160) ?: null,
                'category' => mb_substr((string) ($r['category'] ?? 'lunch'), 0, 24),
                'url' => FoodPhotos::base() . FoodPhotos::LIB_PREFIX . $r['file'],
                'thumb_url' => FoodPhotos::base() . FoodPhotos::LIB_PREFIX . $r['thumb'],
                'source_url' => mb_substr((string) ($r['source_url'] ?? ''), 0, 500) ?: null,
                'title' => mb_substr((string) ($r['title'] ?? ''), 0, 255) ?: null,
                'author' => mb_substr((string) ($r['author'] ?? ''), 0, 160) ?: null,
                'licence' => mb_substr((string) ($r['licence'] ?? ''), 0, 60) ?: null,
                'licence_url' => mb_substr((string) ($r['licence_url'] ?? ''), 0, 255) ?: null,
                'active' => true,
                'updated_at' => now(),
            ];
            if (DB::table('food_images')->where('slug', $slug)->exists()) {
                DB::table('food_images')->where('slug', $slug)->update($vals);
            } else {
                DB::table('food_images')->insert($vals + ['slug' => $slug, 'created_at' => now()]);
            }
            $ok++;
        }
        $off = 0;
        if (! $this->option('dry-run') && $slugs) {
            $off = DB::table('food_images')->whereNotIn('slug', $slugs)->where('active', true)->update(['active' => false, 'updated_at' => now()]);
            FoodPhotos::forget();
        }
        $this->line(json_encode(['loaded' => $ok, 'missing_files' => $missing, 'switched_off' => $off, 'dry_run' => (bool) $this->option('dry-run')]));
        return self::SUCCESS;
    }
}
