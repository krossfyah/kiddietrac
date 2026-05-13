<?php

namespace App\Console\Commands;

use App\Models\Child;
use App\Services\AiDigestService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * TestDailyDigest — Generate a single AI digest for QA.
 *
 * Use this BEFORE turning on the production cron to verify:
 *  - Anthropic API is correctly configured
 *  - Service can read events for a child
 *  - Digest body is factual, warm, and free of hallucinations
 *  - Cost per digest matches estimates
 *
 * Examples:
 *   php artisan kiddietrac:test-digest                       # auto-pick child with most events today
 *   php artisan kiddietrac:test-digest --child=42            # specific child
 *   php artisan kiddietrac:test-digest --date=2026-05-10     # specific day
 *   php artisan kiddietrac:test-digest --dry-run             # walk through but skip the API call
 */
class TestDailyDigest extends Command
{
    protected $signature = 'kiddietrac:test-digest
                            {--child= : Child ID (default: first child with most events on the date)}
                            {--date= : Date YYYY-MM-DD (default: today)}
                            {--dry-run : Skip the Claude API call, just walk through the setup}';

    protected $description = 'Generate a single AI daily digest for QA — verbose step-by-step output';

    public function handle(AiDigestService $service): int
    {
        $this->line('');
        $this->info('═══════════════════════════════════════════════════════');
        $this->info('  Kiddietrac AI Digest — Test Run');
        $this->info('═══════════════════════════════════════════════════════');
        $this->line('');

        // ─── Step 1: Anthropic config ──────────────────────────────
        $this->info('Step 1/6: Anthropic config check');
        $apiKey = config('services.anthropic.key');
        if (! $apiKey) {
            $this->error('  ✗ services.anthropic.key is NULL');
            $this->line('     Possible causes:');
            $this->line('       (a) config/services.php missing the anthropic block');
            $this->line('       (b) ANTHROPIC_API_KEY missing from .env');
            $this->line('       (c) Need to run: php artisan config:clear');
            return 1;
        }
        $masked = substr($apiKey, 0, 8) . '...' . substr($apiKey, -4);
        $model = config('services.anthropic.model', 'claude-haiku-4-5-20251001');
        $this->line("  ✓ API key:  {$masked}");
        $this->line("  ✓ Model:    {$model}");
        $this->line('');

        // ─── Step 2: Pick the child ────────────────────────────────
        $this->info('Step 2/6: Find a test child');
        $date = $this->option('date') ?: today()->toDateString();
        $childId = $this->option('child');

        if ($childId) {
            $child = Child::find($childId);
            if (! $child) {
                $this->error("  ✗ Child #{$childId} not found");
                return 1;
            }
        } else {
            $row = DB::table('daily_events')
                ->whereDate('occurred_at', $date)
                ->select('child_id', DB::raw('COUNT(*) as event_count'))
                ->groupBy('child_id')
                ->orderByDesc('event_count')
                ->first();
            if (! $row) {
                $this->error("  ✗ No daily events found on {$date}");
                $this->line('     Try a different --date, or log some events first.');
                return 1;
            }
            $child = Child::find($row->child_id);
            if (! $child) {
                $this->error("  ✗ Child #{$row->child_id} (most events) couldn't be loaded");
                return 1;
            }
        }

        $eventCount = DB::table('daily_events')
            ->where('child_id', $child->id)
            ->whereDate('occurred_at', $date)
            ->count();

        $this->line("  ✓ Child:    #{$child->id} {$child->display_name}");
        $this->line("  ✓ Date:     {$date}");
        $this->line("  ✓ Events:   {$eventCount} logged on this day");
        if ($eventCount === 0) {
            $this->error('  ✗ This child has no events on this date — service would skip.');
            return 1;
        }
        $this->line('');

        // ─── Step 3: Schema sanity ─────────────────────────────────
        $this->info('Step 3/6: Schema sanity check');
        $missingCols = [];
        foreach (['body', 'source_event_ids', 'model_used', 'tokens_used', 'language'] as $col) {
            if (! \Schema::hasColumn('ai_daily_digests', $col)) {
                $missingCols[] = $col;
            }
        }
        if (! empty($missingCols)) {
            $this->error('  ✗ Missing columns on ai_daily_digests: ' . implode(', ', $missingCols));
            $this->line('     Run: php artisan migrate');
            return 1;
        }
        $this->line('  ✓ All required columns present');
        $this->line('');

        // ─── Step 4: Existing digest? ──────────────────────────────
        $this->info('Step 4/6: Existing digest check');
        $existing = DB::table('ai_daily_digests')
            ->where('child_id', $child->id)
            ->where('digest_date', $date)
            ->first();
        if ($existing) {
            $this->warn('  ⚠ A digest already exists for this child on this date.');
            $this->line('    Running this will replace it (updateOrCreate).');
            if (! $this->option('no-interaction') && ! $this->confirm('Continue?', true)) {
                $this->line('  Cancelled.');
                return 0;
            }
        } else {
            $this->line('  ✓ No existing digest — will generate fresh');
        }
        $this->line('');

        // ─── Step 5: Dry run guard ─────────────────────────────────
        if ($this->option('dry-run')) {
            $this->info('Step 5/6: Dry run — would call Claude API but skipping');
            $this->line('  Remove --dry-run to actually generate a digest.');
            return 0;
        }

        // ─── Step 5b: Generate ─────────────────────────────────────
        $this->info('Step 5/6: Calling Claude API...');
        $started = microtime(true);

        try {
            $digest = $service->generate($child, $date);
        } catch (\Throwable $e) {
            $this->error('  ✗ Exception: ' . $e->getMessage());
            $this->line('  File: ' . $e->getFile() . ':' . $e->getLine());
            return 1;
        }

        $elapsedMs = (int) round((microtime(true) - $started) * 1000);

        if (! $digest) {
            $this->error('  ✗ Service returned null');
            $this->line('     Check ~/kiddietrac/backend/storage/logs/laravel.log for the underlying error.');
            return 1;
        }

        $this->line("  ✓ Response in {$elapsedMs}ms");
        $this->line('');

        // ─── Step 6: Display + cost ────────────────────────────────
        $this->info('Step 6/6: Generated digest');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->line($digest->body);
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->line('');

        $tokens = $digest->tokens_used ?? 0;
        // Claude Haiku 4.5: $0.25/1M input, $1.25/1M output. Assume 80/20 mix.
        $cost = $tokens * ((0.25 * 0.8 + 1.25 * 0.2) / 1_000_000);
        $this->line("  Tokens used:    {$tokens}");
        $this->line("  Cost estimate:  \$" . number_format($cost, 5) . " (Claude Haiku 4.5 pricing)");
        $this->line("  Saved to:       ai_daily_digests #{$digest->id}");
        $this->line('');

        $this->info('═══════════════════════════════════════════════════════');
        $this->info('  ✓ Test digest generated. Review the body above:');
        $this->info('    • Is it factually accurate (only mentions logged events)?');
        $this->info('    • Is the tone warm but not saccharine?');
        $this->info('    • Are there any hallucinations or invented details?');
        $this->info('    • Is it 2-4 sentences as instructed?');
        $this->info('═══════════════════════════════════════════════════════');
        $this->line('');

        return 0;
    }
}
