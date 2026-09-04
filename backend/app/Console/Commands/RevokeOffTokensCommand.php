<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Cut off API tokens held by switched-off accounts.
 *
 * Deactivating blocked a fresh login but left existing tokens working, so somebody
 * already signed in stayed signed in — a deactivated parent still held two live tokens
 * and made authenticated calls days later.
 *
 * A sweep rather than a hook on the status write: it closes the gap whichever path set
 * the status, including a direct database edit.
 */
class RevokeOffTokensCommand extends Command
{
    protected $signature = 'kiddietrac:revoke-off-tokens {--dry-run : List what would be revoked}';

    protected $description = 'Revoke API tokens belonging to deactivated, suspended or deleted accounts';

    public function handle(): int
    {
        $off = DB::table('users')
            ->where(function ($q) {
                $q->whereIn('status', ['deactivated', 'suspended'])->orWhereNotNull('deleted_at');
            })
            ->pluck('id')->all();

        if (empty($off)) {
            return self::SUCCESS;
        }

        $tokens = DB::table('personal_access_tokens')->whereIn('tokenable_id', $off)->get(['id', 'tokenable_id']);
        if ($tokens->isEmpty()) {
            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            foreach ($tokens as $t) {
                $this->line('  would revoke token '.$t->id.' (user '.$t->tokenable_id.')');
            }

            return self::SUCCESS;
        }

        $n = DB::table('personal_access_tokens')->whereIn('tokenable_id', $off)->delete();
        $this->info('Revoked '.$n.' token(s) from switched-off accounts.');

        \App\Support\Audit::write([
            'user_id' => null, 'agency_id' => null,
            'action' => 'security.tokens_revoked', 'entity_type' => 'user', 'entity_id' => null,
            'payload' => json_encode(['tokens' => $n, 'users' => $tokens->pluck('tokenable_id')->unique()->values()]),
            'created_at' => now(),
        ]);

        return self::SUCCESS;
    }
}
