<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PRUNE DEAD API TOKENS.
 *
 * Every sign-in mints a token and nothing ever removed one: 796 live tokens, 252 of them
 * unused for over a month. Each is a full-access credential (`["*"]`) sitting in the table
 * long after the browser that earned it was closed.
 *
 * THE SAFETY RULE IS `expires_at IS NOT NULL`.
 *
 * Session tokens are minted with an expiry — all 790 of them, named after the browser's
 * user-agent. The handful WITHOUT an expiry were created deliberately to be long-lived,
 * and one of them is `ilearn-integration`, the live sync that had been used a minute
 * before this command was written. Pruning that would break the iLearn integration in a
 * way nobody would connect to a token cleanup for days.
 *
 * So this only ever touches tokens that carry an expiry, and a name allowlist guards the
 * same ground a second time in case an integration token is ever minted with one.
 */
class PruneTokens extends Command
{
    protected $signature = 'tokens:prune {--days=30 : Idle days after which a session token is pruned} {--dry-run : Report what would go, delete nothing}';

    protected $description = 'Remove expired and long-idle API session tokens (never integration tokens)';

    /** Names that must survive regardless — matched case-insensitively as substrings. */
    private const PROTECTED_NAMES = ['integration', 'service', 'webhook', 'sync'];

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $dry = (bool) $this->option('dry-run');
        $cutoff = now()->subDays($days);

        // Only tokens that carry an expiry are session tokens. See the class docblock.
        $base = fn () => DB::table('personal_access_tokens')->whereNotNull('expires_at')
            ->where(function ($q) {
                foreach (self::PROTECTED_NAMES as $n) {
                    $q->where('name', 'not like', '%' . $n . '%');
                }
            });

        $expired = (clone $base())->where('expires_at', '<', now());
        /* COALESCE: a token that was never used is judged on when it was minted, or a
           token nobody ever touched would live forever on a NULL comparison. */
        $idle = (clone $base())->whereRaw('COALESCE(last_used_at, created_at) < ?', [$cutoff]);

        $nExpired = (clone $expired)->count();
        $nIdle = (clone $idle)->where('expires_at', '>=', now())->count();  // idle but not yet expired
        $protectedCount = DB::table('personal_access_tokens')->whereNull('expires_at')->count();
        $total = DB::table('personal_access_tokens')->count();

        $this->line('tokens total:            ' . $total);
        $this->line('  expired:               ' . $nExpired);
        $this->line("  idle >{$days}d (unexpired): " . $nIdle);
        $this->line('  protected (no expiry):  ' . $protectedCount);

        if ($dry) {
            $this->info('dry run — nothing deleted');

            return self::SUCCESS;
        }

        $deleted = (clone $expired)->delete();
        $deleted += (clone $idle)->delete();

        $left = DB::table('personal_access_tokens')->count();
        $this->info("pruned {$deleted}; {$left} remain");

        if ($deleted > 0) {
            Log::info('Pruned API tokens', ['deleted' => $deleted, 'remaining' => $left, 'idle_days' => $days]);
        }

        return self::SUCCESS;
    }
}
