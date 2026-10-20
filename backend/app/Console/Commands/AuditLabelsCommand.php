<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\AuditLabels;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

/**
 * Lists every API write route that has no sentence in resources/audit-labels.txt (2026-10-20).
 * Such a route still shows a readable generated label in the audit log, but a person should
 * write the real one: run this after adding routes, and add the line it prints.
 */
final class AuditLabelsCommand extends Command
{
    protected $signature = 'kt:audit-labels {--quiet-ok : print nothing when complete}';

    protected $description = 'List API write routes missing a plain-English audit label';

    public function handle(): int
    {
        $missing = [];
        foreach (Route::getRoutes() as $r) {
            $uri = $r->uri();
            if (! str_starts_with($uri, 'api/')) {
                continue;
            }
            foreach ($r->methods() as $m) {
                if (! in_array($m, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                    continue;
                }
                if (! AuditLabels::has($m, $uri)) {
                    $missing[] = strtolower($m) . ' ' . preg_replace('#^api/v1/#', '', $uri)
                        . ' | ' . AuditLabels::generated(strtolower($m), $uri) . '   <- generated, write a real one';
                }
            }
        }
        $missing = array_values(array_unique($missing));
        if (! $missing) {
            if (! $this->option('quiet-ok')) {
                $this->info('Every API write route has an audit label.');
            }

            return self::SUCCESS;
        }
        $this->warn(count($missing) . ' write route(s) have no audit label in resources/audit-labels.txt:');
        foreach ($missing as $line) {
            $this->line('  ' . $line);
        }

        return self::FAILURE;
    }
}
