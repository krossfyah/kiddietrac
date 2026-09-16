<?php

declare(strict_types=1);

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

/**
 * Sanctum's token model, with one change: last_used_at is written at most once a minute.
 *
 * Sanctum stamps last_used_at on EVERY authenticated request. On this deployment that is
 * one InnoDB write per API call, and the portal makes 7-21 calls per screen change — so
 * a handful of educators navigating at once was generating a continuous stream of writes
 * to a single hot table. Measured 2026-08-29: it was one of three writes firing on every
 * read, and reads are what the app overwhelmingly does.
 *
 * The column answers "when was this token last used", for which a minute is ample. The
 * value is still written, just not tens of times a second for the same token.
 */
class PersonalAccessToken extends SanctumToken
{
    /** Seconds between last_used_at writes for one token. */
    private const STAMP_EVERY = 60;

    public function save(array $options = [])
    {
        $dirty = $this->getDirty();

        // Only intervene when last_used_at is the ONLY thing changing — that is the
        // per-request stamp. Any other change (revocation, rename) saves normally.
        if ($dirty && array_keys($dirty) === ['last_used_at']) {
            $previous = $this->getOriginal('last_used_at');
            if ($previous && now()->diffInSeconds($previous, true) < self::STAMP_EVERY) {
                // Keep the in-memory value so callers still see it; skip the write.
                $this->syncOriginal();

                return true;
            }
        }

        return parent::save($options);
    }
}
