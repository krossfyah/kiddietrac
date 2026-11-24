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

    /**
     * ONE LOOKUP PER REQUEST (2026-11-13, Anthony: "when the portal refreshes or saves that process moves very slow").
     * Sanctum's guard and five middleware (EnsurePasswordChanged, EnsureOnboarded, EnsureStrongSignIn,
     * EnforceSignInLocks, BrandingController) each looked the bearer token up, and then its user: a badge poll read
     * personal_access_tokens + users five times. The answer is kept on the request, so they share one model (and its
     * loaded tokenable). Web requests only: a queue worker's request object outlives the job. A token deleted during
     * the request (an expired parent session) is never handed out again.
     */
    public static function findToken($token)
    {
        if (app()->runningInConsole() || ! app()->bound('request')) return parent::findToken($token);
        $r = app('request');
        $key = '_kt_pat.' . hash('sha256', (string) $token);
        if ($r->attributes->has($key)) {
            $found = $r->attributes->get($key);
            return ($found && $found->exists) ? $found : null;
        }
        $found = parent::findToken($token);
        $r->attributes->set($key, $found);

        return $found;
    }

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
