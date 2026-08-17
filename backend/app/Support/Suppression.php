<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "Do not contact this agency" — ALL channels (2026-07-14).
 *
 * The email kill-switch (SuppressAgencyMail) only stopped email. On 2026-07-14 the
 * check-in reminder ran against every agency and reached 39 real iLearn parents by
 * email, 38 by in-app notification, and 16 of them by phone push — because push,
 * SMS and in-app notifications had no guard at all.
 *
 * So the rule now lives in ONE place and every outbound channel asks it:
 *
 *   • email — SuppressAgencyMail (MessageSending event)
 *   • push  — FcmService::sendToUser
 *   • SMS   — SmsController::sendOne
 *   • the scheduled jobs (reminders, daily summaries) skip suppressed agencies
 *     outright, so no in-app notification row is written either
 *
 * Configured by MAIL_SUPPRESS_AGENCIES in .env (kept as one setting so the switch
 * can't be half-flipped). Empty it to go live.
 */
final class Suppression
{
    private const CACHE_TTL = 120;

    /** 'denylist' (legacy env list) or 'allowlist' (only listed agencies may send). */
    public static function mode(): string
    {
        return strtolower(trim((string) config('suppression.mode', 'denylist'))) === 'allowlist'
            ? 'allowlist' : 'denylist';
    }

    /** Agency ids explicitly permitted to send, in ALLOWLIST mode. @return int[] */
    public static function allowAgencyIds(): array
    {
        return self::csvInts((string) config('suppression.allow_agencies', ''));
    }

    /** @return int[] */
    private static function csvInts(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(array_map(fn ($v) => (int) trim($v), explode(',', $raw))));
    }

    /**
     * Agency ids whose outbound comms are SUPPRESSED.
     *
     *  • denylist mode  — exactly the .env MAIL_SUPPRESS_AGENCIES list.
     *  • allowlist mode — every agency EXCEPT the ones in MAIL_ALLOW_AGENCIES, so a
     *    newly-created agency is OFF by default until it is explicitly allowed.
     *
     * config() not env() — env() returns null once config is cached, which
     * silently disabled this kill-switch (iLearn digests leaked, 2026-07-20).
     *
     * @return int[]
     */
    public static function agencyIds(): array
    {
        if (self::mode() === 'allowlist') {
            $allow = self::allowAgencyIds();
            sort($allow);

            return Cache::remember('kt.suppress.allowlist_supp:' . implode(',', $allow), self::CACHE_TTL, function () use ($allow) {
                $all = DB::table('agencies')->pluck('id')->map(fn ($i) => (int) $i)->all();

                return array_values(array_diff($all, $allow));
            });
        }

        return self::csvInts((string) config('suppression.agencies', ''));
    }

    /** Agency ids permitted to send (the complement of agencyIds()). @return int[] */
    public static function allowedAgencyIds(): array
    {
        $all = DB::table('agencies')->pluck('id')->map(fn ($i) => (int) $i)->all();

        return array_values(array_diff($all, self::agencyIds()));
    }

    public static function enabled(): bool
    {
        return self::agencyIds() !== [];
    }

    public static function isAgency(?int $agencyId): bool
    {
        if (! $agencyId) {
            return false;
        }

        // Two independent reasons to stay silent:
        //   1. the .env kill-switch (us, while testing)
        //   2. the agency's own master switch, in their settings (them)
        if (self::enabled() && in_array((int) $agencyId, self::agencyIds(), true)) {
            return true;
        }

        return ! self::agencyNotificationsEnabled((int) $agencyId);
    }

    /**
     * The agency's own master switch: Settings → "Send notifications and emails".
     * Default ON — an agency that has never touched it keeps working normally.
     */
    public static function agencyNotificationsEnabled(int $agencyId): bool
    {
        return Cache::remember('kt.agency_notifications:' . $agencyId, self::CACHE_TTL, function () use ($agencyId) {
            $settings = DB::table('agencies')->where('id', $agencyId)->value('settings');
            $decoded = $settings ? (json_decode((string) $settings, true) ?: []) : [];

            // Absent → enabled. Only an explicit false switches it off.
            return ($decoded['notifications_enabled'] ?? true) !== false;
        });
    }

    /**
     * Has this user's OWN agency explicitly switched notifications off in its
     * settings (the "Send notifications and emails" toggle)? This is ABSOLUTE —
     * unlike the .env testing kill-switch it is NOT exempted by
     * MAIL_SUPPRESS_ALLOWLIST. When an agency turns the toggle off, off means off.
     */
    public static function agencyOff(?int $userId): bool
    {
        if (! $userId) {
            return false;
        }
        $agencyId = self::agencyOfUser((int) $userId);

        return $agencyId ? ! self::agencyNotificationsEnabled($agencyId) : false;
    }

    /**
     * Is a centre switched on for email? Stored in centres.settings.email_enabled.
     * Absent → ON (default). Only an explicit false switches it off.
     */
    public static function centreEmailEnabled(int $centreId): bool
    {
        return Cache::remember('kt.centre_email:' . $centreId, self::CACHE_TTL, function () use ($centreId) {
            $settings = DB::table('centres')->where('id', $centreId)->value('settings');
            $decoded = $settings ? (json_decode((string) $settings, true) ?: []) : [];

            return ($decoded['email_enabled'] ?? true) !== false;
        });
    }

    /**
     * Is a room switched on for email? A room delivers only if its OWN switch is
     * on AND the centre above it is on (a room can't out-rank its centre).
     */
    public static function roomEmailEnabled(int $roomId): bool
    {
        return Cache::remember('kt.room_email:' . $roomId, self::CACHE_TTL, function () use ($roomId) {
            $room = DB::table('rooms')->where('id', $roomId)->first(['email_enabled', 'centre_id']);
            if (! $room) {
                return true;
            }
            if (isset($room->email_enabled) && (int) $room->email_enabled === 0) {
                return false;
            }

            return self::centreEmailEnabled((int) $room->centre_id);
        });
    }

    /**
     * Should this recipient be held back by the centre / room email switches?
     *
     * The pre-boarding gate. A user is BLOCKED only if they have at least one
     * centre / room tie AND *none* of them currently delivers — i.e. every centre
     * they belong to (and every room their children sit in) is switched off. A
     * single live channel is enough to let their mail through, so an educator who
     * also covers a switched-on centre still gets email.
     *
     * A user with NO centre / room tie at all (e.g. an agency-level admin) is not
     * touched here — they are governed by the agency master switch only.
     *
     * Not cached at the user level: it depends on the centre / room flags (which
     * ARE cached and forgotten on toggle), and only runs while a mail is actually
     * being sent, so a toggle takes effect immediately.
     */
    public static function blockedByCentreRoom(?int $userId): bool
    {
        if (! $userId) {
            return false;
        }

        // Staff: centres they hold an (active) role at.
        $staffCentreIds = DB::table('role_assignments')
            ->where('user_id', $userId)->where('active', true)
            ->whereNotNull('centre_id')
            ->pluck('centre_id')->map(fn ($v) => (int) $v)->unique()->values()->all();

        // Parent: their children's current rooms, plus the family centre as a
        // fallback for children not yet placed in a room.
        $familyIds = DB::table('guardians')->where('user_id', $userId)->pluck('family_id')->all();
        $roomIds = [];
        $familyCentreIds = [];
        if ($familyIds) {
            $familyCentreIds = DB::table('families')->whereIn('id', $familyIds)
                ->whereNotNull('centre_id')
                ->pluck('centre_id')->map(fn ($v) => (int) $v)->unique()->values()->all();

            $childIds = DB::table('children')->whereIn('family_id', $familyIds)
                ->whereNull('deleted_at')->pluck('id')->all();
            if ($childIds) {
                $today = now()->toDateString();
                $roomIds = DB::table('enrollments')->whereIn('child_id', $childIds)
                    ->whereNotNull('room_id')
                    ->where(function ($q) use ($today) {
                        $q->whereNull('end_date')->orWhere('end_date', '>=', $today);
                    })
                    ->pluck('room_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
            }
        }

        // No centre / room association → not gated at this layer.
        if (! $staffCentreIds && ! $roomIds && ! $familyCentreIds) {
            return false;
        }

        // Any one delivering channel means the recipient is NOT blocked.
        foreach ($staffCentreIds as $cid) {
            if (self::centreEmailEnabled($cid)) {
                return false;
            }
        }
        foreach ($roomIds as $rid) {
            if (self::roomEmailEnabled($rid)) {
                return false;
            }
        }
        if (! $roomIds) {
            foreach ($familyCentreIds as $cid) {
                if (self::centreEmailEnabled($cid)) {
                    return false;
                }
            }
        }

        // Had ties, none deliver → held back.
        return true;
    }

    /**
     * Is this user reachable at a suppressed agency? Covers staff (role
     * assignments) and guardians (through their family's centre).
     */
    public static function isUser(?int $userId): bool
    {
        if (! $userId) {
            return false;
        }

        // The .env kill-switch.
        if (self::enabled() && in_array((int) $userId, self::userIds(), true)) {
            return true;
        }

        // The agency's own master switch (they turned everything off themselves).
        $agencyId = self::agencyOfUser((int) $userId);

        return $agencyId ? ! self::agencyNotificationsEnabled($agencyId) : false;
    }

    /** Which agency is this user reachable at? (staff role, else their family's centre) */
    private static function agencyOfUser(int $userId): ?int
    {
        return Cache::remember('kt.agency_of_user:' . $userId, self::CACHE_TTL, function () use ($userId) {
            $id = DB::table('role_assignments')
                ->where('user_id', $userId)->where('active', true)
                ->whereNotNull('agency_id')
                ->value('agency_id');
            if ($id) return (int) $id;

            $viaCentre = DB::table('role_assignments as ra')
                ->join('centres as c', 'c.id', '=', 'ra.centre_id')
                ->where('ra.user_id', $userId)->where('ra.active', true)
                ->value('c.agency_id');
            if ($viaCentre) return (int) $viaCentre;

            $viaFamily = DB::table('guardians as g')
                ->join('families as f', 'f.id', '=', 'g.family_id')
                ->join('centres as c', 'c.id', '=', 'f.centre_id')
                ->where('g.user_id', $userId)
                ->value('c.agency_id');

            return $viaFamily ? (int) $viaFamily : null;
        });
    }

    /** Log what we stopped — silence with no trail is its own hazard. */
    public static function note(string $channel, int $userId, string $what): void
    {
        Log::warning('Suppressed ' . $channel . ' to a live-agency user', [
            'user_id' => $userId,
            'detail' => $what,
        ]);
    }

    /** @return int[] */
    private static function userIds(): array
    {
        $ids = self::agencyIds();
        sort($ids);
        $allow = self::allowedAgencyIds();
        sort($allow);

        return Cache::remember('kt.suppressed_user_ids:' . implode(',', $ids) . '|a:' . implode(',', $allow), self::CACHE_TTL, function () use ($ids, $allow) {
            $suppressed = self::usersAtAgencies($ids);
            if ($suppressed === []) {
                return [];
            }

            // A user also reachable at an ALLOWED agency is NOT suppressed — this is
            // what lets a shared / duplicate account still receive its allowed
            // agency's mail (decide by the sending agency, not by mere membership).
            $allowed = self::usersAtAgencies($allow);

            return array_values(array_diff($suppressed, $allowed));
        });
    }

    /** User ids reachable (staff role or guardian) at any of these agencies. @return int[] */
    private static function usersAtAgencies(array $agencyIds): array
    {
        if ($agencyIds === []) {
            return [];
        }

        $centreIds = DB::table('centres')->whereIn('agency_id', $agencyIds)->pluck('id');

        $staff = DB::table('role_assignments')
            ->where(function ($q) use ($agencyIds, $centreIds) {
                $q->whereIn('agency_id', $agencyIds)->orWhereIn('centre_id', $centreIds);
            })
            ->pluck('user_id');

        $guardians = DB::table('guardians as g')
            ->join('families as f', 'f.id', '=', 'g.family_id')
            ->whereIn('f.centre_id', $centreIds)
            ->pluck('g.user_id');

        return $staff->merge($guardians)
            ->map(fn ($i) => (int) $i)
            ->unique()
            ->values()
            ->all();
    }
}
