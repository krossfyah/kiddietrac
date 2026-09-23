<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * WHO A BROADCAST GOES TO (2026-09-10).
 *
 * Lifted verbatim out of SmsController::resolveRecipients when voice announcements were
 * added, so that both channels ask the same question and get the same answer. Two
 * copies of an audience rule is how the Forms Manager ended up with five that disagreed
 * (see FormAudience), and an audience rule that drifts on one channel is a message
 * reaching a family the director thought they had excluded.
 *
 * The SMS branch is UNCHANGED -- same joins, same filters, same fail-closed `?: [0]`.
 * The only thing that varies by channel is the consent predicate, because consent to be
 * texted and consent to be telephoned are not the same permission.
 */
final class BroadcastAudience
{
    /**
     * Categories that ring a phone whether or not the person opted in to being
     * contacted, because the call is made necessary by a situation affecting their
     * child's health or safety. This is the TCPA's emergency-purposes exception and it
     * is deliberately a SHORT, CLOSED list -- everything not named here needs a yes.
     *
     * A standing "do not ring me" (users.voice_opt_out) still wins over all of these.
     */
    public const EMERGENCY_CATEGORIES = ['emergency', 'closure', 'evacuation', 'lockdown', 'illness'];

    public static function isEmergency(?string $category): bool
    {
        return in_array(strtolower(trim((string) $category)), self::EMERGENCY_CATEGORIES, true);
    }

    /**
     * @param  string  $channel  'sms' or 'voice'
     * @param  string|null  $category  only consulted for voice, to decide which consent applies
     */
    public static function resolve(int $agencyId, array $data, string $channel = 'sms', ?string $category = null): Collection
    {
        return self::query($agencyId, $data, $channel, $category, true)->get();
    }

    /* WHY NOTHING WAS SENT (2026-09-18).

       Anthony: "SMS broadcast - when sending to whole agency i don't see anything being
       sent."

       It was doing exactly what it was told. Consent is per person and almost nobody has
       given it: iLearn has 52 people on active assignments, 42 with a phone on file, and
       ONE opted in. Test Agency has 31, 22 with phones, and none. So an agency-wide send
       resolved to zero rows, the loop below never ran, and the endpoint answered
       {sent:0, skipped:0, total:0} — which the screen printed in GREEN as though it had
       worked.

       The silence is the real defect. Everything else on the send path that drops a
       person LOGS a skipped row with a reason — suppression, the agency switch, consent
       at sendOne() — so "why did that not send?" has an answer. The audience filter drops
       them BEFORE any of that, leaving nothing behind at all.

       So: count what each filter removes, and let the caller say so out loud. The
       counting runs the same query as the send with one predicate lifted out, rather than
       a second query written to match — two audience rules that can disagree is exactly
       the drift BroadcastAudience exists to prevent. */
    public static function breakdown(int $agencyId, array $data, string $channel = 'sms', ?string $category = null): array
    {
        $withConsent    = self::query($agencyId, $data, $channel, $category, true);
        $withoutConsent = self::query($agencyId, $data, $channel, $category, false);

        /* "In this audience" means everybody the audience names, INCLUDING the people with
           no phone number. A director reading "22 of 31" needs the 31 to be the staff list
           they have in their head, not a pre-filtered number that already hides nine. */
        $all = self::query($agencyId, $data, $channel, $category, false, false);

        $inAudience = $all->distinct()->count('u.id');
        $withPhone  = $withoutConsent->distinct()->count('u.id');
        $reachable  = $withConsent->distinct()->count('u.id');

        return [
            'in_audience'   => $inAudience,
            'no_phone'      => max(0, $inAudience - $withPhone),
            'not_consented' => max(0, $withPhone - $reachable),
            'reachable'     => $reachable,
            'channel'       => $channel,
            /* Voice tells a different story on an emergency category, where consent is not
               required — so say which rule was applied rather than leaving the reader to
               infer it from the numbers. */
            'consent_waived' => $channel === 'voice' && self::isEmergency($category),
        ];
    }

    /** @param bool $applyConsent  false counts the people consent is what excludes. */
    private static function query(int $agencyId, array $data, string $channel, ?string $category, bool $applyConsent, bool $requirePhone = true)
    {
        $q = DB::table('users as u')
            ->join('role_assignments as ra', 'ra.user_id', '=', 'u.id')
            ->where('ra.agency_id', $agencyId)
            ->where('ra.active', true)
            ->select('u.id', 'u.first_name', 'u.last_name', 'u.phone')
            ->distinct();

        if ($requirePhone) {
            $q->whereNotNull('u.phone')->where('u.phone', '!=', '');
        }

        if ($channel === 'voice') {
            /* A standing "do not ring me" is honoured for every category, emergency
               included -- somebody who has said it has usually said it for a reason we
               are not entitled to second-guess. */
            /* Conditional with the rest of the consent rule, so the breakdown's buckets
               add up: somebody who has said "do not ring me" belongs in `not_consented`,
               not silently outside every count. */
            if ($applyConsent) {
                $q->where(function ($w) {
                    $w->whereNull('u.voice_opt_out')->orWhere('u.voice_opt_out', 0);
                });
            }

            /* Anything that is not an emergency needs an actual yes. sms_opt_in is the
               only recorded consent to be contacted on this number, and using it here
               means a newsletter cannot be read down the phone to somebody who never
               agreed to be contacted at all. */
            if ($applyConsent && ! self::isEmergency($category)) {
                $q->where('u.sms_opt_in', 1);
            }
        } elseif ($applyConsent) {
            $q->where('u.sms_opt_in', 1);
        }

        if (($data['audience'] ?? '') === 'role' && ! empty($data['role'])) {
            $q->where('ra.role', $data['role']);
        }
        if (($data['audience'] ?? '') === 'centre' && ! empty($data['centre_id'])) {
            $q->where('ra.centre_id', $data['centre_id']);
        }

        // `guardians` is the family-to-user link. This joined `family_users`, which does
        // not exist on this database, so every family broadcast was a 500.
        if (($data['audience'] ?? '') === 'family' && ! empty($data['family_id'])) {
            $q->join('guardians as g', 'g.user_id', '=', 'u.id')
                ->where('g.family_id', $data['family_id']);
        }

        // A room means the people in it: the educators assigned to it, and the guardians
        // of the children enrolled in it. Anything less is not who you meant.
        if (($data['audience'] ?? '') === 'room' && ! empty($data['room_id'])) {
            $roomId = (int) $data['room_id'];

            $educators = DB::table('educator_rooms')->where('room_id', $roomId)->pluck('user_id');
            $guardians = DB::table('enrollments as e')
                ->join('children as ch', 'ch.id', '=', 'e.child_id')
                ->join('guardians as g', 'g.family_id', '=', 'ch.family_id')
                ->where('e.room_id', $roomId)
                ->whereNull('ch.deleted_at')
                ->where(function ($w) {
                    $w->whereNull('e.end_date')->orWhere('e.end_date', '>=', now()->toDateString());
                })
                ->pluck('g.user_id');

            $q->whereIn('u.id', $educators->merge($guardians)->unique()->values()->all() ?: [0]);
        }

        return $q;
    }

    /**
     * The id a narrowing audience cannot go without.
     *
     * A narrowing audience with nothing to narrow BY used to fall through to "everyone
     * in the agency". On a paid channel that turns a message for one room into a
     * message for every family the agency has -- and on the voice channel it turns it
     * into several hundred phone calls. Both controllers refuse it, using this.
     */
    public static function missingSelector(array $data): ?string
    {
        $needs = ['centre' => 'centre_id', 'room' => 'room_id', 'family' => 'family_id'];
        $audience = (string) ($data['audience'] ?? '');

        return (isset($needs[$audience]) && empty($data[$needs[$audience]])) ? $needs[$audience] : null;
    }

    /** The centre or room must belong to THIS agency -- the ids arrive from the client. */
    public static function assertOwned(int $agencyId, array $data): void
    {
        if (! empty($data['centre_id'])) {
            abort_unless(DB::table('centres')->where('id', $data['centre_id'])
                ->where('agency_id', $agencyId)->exists(), 403, 'Unknown centre.');
        }
        if (! empty($data['room_id'])) {
            abort_unless(DB::table('rooms as r')->join('centres as c', 'c.id', '=', 'r.centre_id')
                ->where('r.id', $data['room_id'])->where('c.agency_id', $agencyId)->exists(), 403, 'Unknown room.');
        }
        if (! empty($data['family_id'])) {
            abort_unless(DB::table('families as f')->join('centres as c', 'c.id', '=', 'f.centre_id')
                ->where('f.id', $data['family_id'])->where('c.agency_id', $agencyId)->exists(), 403, 'Unknown family.');
        }
    }
}
