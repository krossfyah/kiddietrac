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
    protected $signature = 'kiddietrac:revoke-off-tokens
                            {--dry-run : List what would be revoked}
                            {--purge-notifications : Also DELETE queued notifications for switched-off accounts}';

    protected $description = 'Revoke tokens and clear queued notifications for deactivated, suspended or deleted accounts';

    public function handle(): int
    {
        /* ONE definition of "switched off", shared with the front door — AuthController
           refuses these same statuses, so the two cannot drift apart. */
        $offStatuses = \App\Support\Audience::OFF_STATUSES;

        $off = DB::table('users')
            ->where(function ($q) use ($offStatuses) {
                $q->whereIn('status', $offStatuses)->orWhereNotNull('deleted_at');
            })
            ->pluck('id')->map(fn ($i) => (int) $i)->all();

        $dry = (bool) $this->option('dry-run');
        $tokens = $off ? DB::table('personal_access_tokens')->whereIn('tokenable_id', $off)
            ->get(['id', 'tokenable_id']) : collect();

        /* TOKENS WHOSE USER NO LONGER EXISTS. The query above selects users by status, so
           a token pointing at a hard-deleted row matches nothing and is never collected —
           42 were sitting here, the oldest from May. Inert, because Sanctum cannot resolve
           a missing tokenable, but they are credentials-shaped rows nobody is watching. */
        $orphans = DB::table('personal_access_tokens as t')
            ->where('t.tokenable_type', \App\Models\User::class)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('users as u')
                ->whereColumn('u.id', 't.tokenable_id'))
            ->pluck('t.id')->all();

        /* QUEUED NOTIFICATIONS. Written from 82 call sites with the query builder, so
           there is no observer to hook and no single writer to patch. Swept here for the
           same reason the tokens are: it closes the gap whichever path wrote the row.

           Not an active leak — nobody in this state can sign in to read their bell — but
           reactivate the account and a backlog naming children appears, covering exactly
           the period after they left. */
        /* A DIFFERENT SET FROM THE TOKENS, on purpose. A credential is cut the moment
           an account is switched off, temporary or not — but the FEED is only cleared for
           accounts that are not coming back. A suspension can be lifted, and somebody
           returning after a fortnight should not find that period wiped. */
        $goneForGood = DB::table('users')
            ->where(fn ($q) => $q->where('status', 'deactivated')->orWhereNotNull('deleted_at'))
            ->pluck('id')->map(fn ($i) => (int) $i)->all();

        $notes = $goneForGood ? DB::table('notifications')->whereIn('user_id', $goneForGood)->count() : 0;

        if (! $tokens->count() && ! $orphans && ! $notes) {
            return self::SUCCESS;
        }

        if ($dry) {
            foreach ($tokens as $t) {
                $this->line('  would revoke token '.$t->id.' (user '.$t->tokenable_id.')');
            }
            if ($orphans) {
                $this->line('  would delete '.count($orphans).' orphaned token(s) whose user no longer exists');
            }
            if ($notes) {
                $this->line('  would clear '.$notes.' queued notification(s) for deactivated/deleted accounts');
                foreach (DB::table('notifications')->whereIn('user_id', $goneForGood)
                    ->select('user_id', DB::raw('COUNT(*) n'))->groupBy('user_id')->get() as $r) {
                    $this->line('      user '.$r->user_id.': '.$r->n);
                }
            }

            return self::SUCCESS;
        }

        $byUser = $goneForGood ? DB::table('notifications')->whereIn('user_id', $goneForGood)
            ->select('user_id', DB::raw('COUNT(*) n'))->groupBy('user_id')->pluck('n', 'user_id')->all() : [];

        $n = $tokens->count() ? DB::table('personal_access_tokens')->whereIn('tokenable_id', $off)->delete() : 0;
        $nOrphan = $orphans ? DB::table('personal_access_tokens')->whereIn('id', $orphans)->delete() : 0;
        /* GATED OFF. This command runs on a schedule, so quietly adding a delete to it
           would remove hundreds of rows on the next tick with nobody having decided that.
           And "suspended" is not obviously permanent — somebody back from a fortnight's
           suspension should not find their feed emptied. Reported every run, deleted only
           when asked. */
        /* Runs on the schedule now that the rule is settled: nothing stays queued for an
           account that is not coming back. --purge-notifications is kept as a no-op alias
           so anything already calling it does not break. */
        $nNotes = $notes ? DB::table('notifications')->whereIn('user_id', $goneForGood)->delete() : 0;

        $this->info('Revoked '.$n.' token(s) from switched-off accounts.');
        if ($nOrphan) { $this->info('Deleted '.$nOrphan.' orphaned token(s) with no user.'); }
        if ($nNotes) { $this->info('Cleared '.$nNotes.' queued notification(s) for switched-off accounts.'); }

        if ($nNotes || $nOrphan) {
            \App\Support\Audit::write([
                'user_id' => null, 'agency_id' => null,
                'action' => 'security.offboard_swept', 'entity_type' => 'user', 'entity_id' => null,
                'payload' => json_encode([
                    'summary' => 'Cleared '.$nNotes.' queued notification(s) and '.$nOrphan
                        .' orphaned token(s) belonging to accounts that are deactivated, suspended or deleted',
                    'notifications_by_user' => $byUser,
                    'orphaned_tokens' => $nOrphan,
                ]),
            ]);
        }

        \App\Support\Audit::write([
            'user_id' => null, 'agency_id' => null,
            'action' => 'security.tokens_revoked', 'entity_type' => 'user', 'entity_id' => null,
            'payload' => json_encode(['tokens' => $n, 'users' => $tokens->pluck('tokenable_id')->unique()->values()]),
            'created_at' => now(),
        ]);

        return self::SUCCESS;
    }
}
