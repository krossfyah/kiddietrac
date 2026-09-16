<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Who gets a blind copy of an operational email.
 *
 * An agency admin or centre director is accountable for what their families are told,
 * but until now the only record that a closure notice or a change-of-provider letter
 * had gone out was a row in email_logs. They found out what a parent had been told by
 * being told by the parent. A blind copy puts it in their inbox at the moment it goes.
 *
 * TENANT RULE: the recipients are resolved from the agency that OWNS the message, never
 * from the caller's roles. An admin of agency 2 must never be copied on agency 6's mail —
 * a BCC leaks the parent's address as surely as a To does. Callers pass the agency of the
 * centre the message is about.
 *
 * ONE COPY, NOT N. These send paths loop over recipients one message at a time. Attaching
 * the BCC to every iteration would hand a director forty identical copies of one closure
 * notice, which is not oversight, it is a reason to filter the sender. Call sites attach
 * the list to the FIRST message only — see firstOnly().
 */
final class MailOversight
{
    /**
     * Active agency admins for this agency, plus directors of this centre.
     *
     * @param  int|null    $agencyId  agency that owns the message
     * @param  int|null    $centreId  narrow directors to one centre; null = all in agency
     * @param  array       $exclude   addresses already receiving it (To); deduped out
     * @return list<string>
     */
    public static function bccFor(?int $agencyId, ?int $centreId = null, array $exclude = []): array
    {
        if (! $agencyId) {
            return [];
        }

        $centreIds = $centreId
            ? [$centreId]
            : DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all();

        $q = DB::table('users as u')
            ->join('role_assignments as ra', 'ra.user_id', '=', 'u.id')
            ->where('ra.active', true)
            ->whereNull('u.deleted_at')
            ->where(function ($w) use ($agencyId, $centreIds) {
                // Agency admins: the whole agency is theirs.
                $w->where(function ($x) use ($agencyId) {
                    $x->where('ra.role', 'agency_admin')->where('ra.agency_id', $agencyId);
                });
                // Directors: only the centre the message is about.
                if ($centreIds) {
                    $w->orWhere(function ($x) use ($centreIds) {
                        $x->where('ra.role', 'centre_director')->whereIn('ra.centre_id', $centreIds);
                    });
                }
            });

        $emails = $q->distinct()->pluck('u.email')->all();

        /* A person switched off should not keep receiving copies of everything.

           FIXED 2026-08-25, same day this file was written: the list here was
           ['inactive','suspended'] — the phantom 'inactive' again (users.status has no
           such member) and, worse, 'deactivated' missing, which is the status every
           off-boarding path actually writes. The result was that Lloydene King, a
           DEACTIVATED centre director, was still being blind-copied on agency 6's
           operational mail. Caught by a walk-notification test, not by review.

           Audience::OFF_STATUSES is the one definition of "switched off". Reaching for
           a hand-written list is what caused this, twice in one day. */
        $active = DB::table('users')->whereIn('email', $emails)
            ->where(function ($w) {
                $w->whereNull('status')->orWhereNotIn('status', \App\Support\Audience::OFF_STATUSES);
            })
            ->pluck('email')->all();

        $lowerExclude = array_map('mb_strtolower', array_map('strval', $exclude));

        $out = [];
        foreach ($active as $e) {
            $e = trim((string) $e);
            if (! filter_var($e, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            if (in_array(mb_strtolower($e), $lowerExclude, true)) {
                continue;   // already a named recipient — a BCC as well is just a duplicate
            }
            $out[mb_strtolower($e)] = $e;
        }

        return array_values($out);
    }

    /**
     * The BCC list for the first message of a batch and an empty list thereafter.
     *
     * Usage inside a recipient loop:
     *   $bcc = MailOversight::bccFor($agencyId, $centreId, $addresses);
     *   foreach ($addresses as $i => $addr) {
     *       ... $m->bcc(MailOversight::firstOnly($bcc, $i)) ...
     *   }
     */
    public static function firstOnly(array $bcc, int $index): array
    {
        return $index === 0 ? $bcc : [];
    }
}
