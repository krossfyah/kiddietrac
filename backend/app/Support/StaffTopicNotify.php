<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * WHO HEARS ABOUT IMMUNIZATIONS AND DRILLS (2026-11-09, Anthony: "add to settings to turn off immunization notifications
 * for educators and directors/admins ... also for fire drills same thing").
 *
 * agencies.settings.staff_topic_notifications = {immunization: {educator, centre_director, agency_admin}, drills: {…}},
 * every switch ON by default (what was sent before). Edited in Settings → Notification settings → 💉 Immunizations &
 * drills. Off for a role means NOTHING about that topic reaches someone in that role: no bell, no phone push, no email.
 *
 * One rule, enforced in two places so a new sender cannot miss it:
 *   - Notify::write drops bell/push rows of the TYPES below for anyone switched off;
 *   - every email sender of these topics filters its recipients through allows() (ImmunizationRemindersCommand,
 *     DrillController::store, drills:cadence-remind, drills:remind).
 * A person holding several roles hears if ANY of their roles is on. Platform admins (no agency role) always hear.
 * A NEW immunization or drill notification type goes in TYPES.
 */
final class StaffTopicNotify
{
    public const TOPICS = ['immunization' => '💉 Immunizations', 'drills' => '🔥 Fire & emergency drills'];

    /** Home visitors go with educators: they do the same job in someone's home. */
    public const ROLES = ['educator' => 'Educators & home visitors', 'centre_director' => 'Centre directors', 'agency_admin' => 'Agency admins'];

    /** Staff bell types that belong to each topic. */
    public const TYPES = ['immunization_record' => 'immunization', 'drill_recorded' => 'drills', 'drill_due' => 'drills',
        'drill_scheduled' => 'drills', 'drill_missed' => 'drills', 'fire_plan' => 'drills'];

    public static function settings(int $agencyId): array
    {
        $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $all = is_string($raw) ? (json_decode($raw, true) ?: []) : (array) $raw;
        $s = (array) ($all['staff_topic_notifications'] ?? []);
        $out = [];
        foreach (array_keys(self::TOPICS) as $t) {
            foreach (array_keys(self::ROLES) as $r) {
                $out[$t][$r] = (bool) ($s[$t][$r] ?? true);
            }
        }

        return $out;
    }

    public static function save(int $agencyId, array $in): array
    {
        DB::transaction(function () use ($agencyId, $in) {
            $raw = DB::table('agencies')->where('id', $agencyId)->lockForUpdate()->value('settings');
            $all = is_string($raw) ? (json_decode($raw, true) ?: []) : (array) $raw;
            $clean = [];
            foreach (array_keys(self::TOPICS) as $t) {
                foreach (array_keys(self::ROLES) as $r) {
                    $clean[$t][$r] = (bool) ($in[$t][$r] ?? true);
                }
            }
            $all['staff_topic_notifications'] = $clean;
            DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($all), 'updated_at' => now()]);
        });

        return self::settings($agencyId);
    }

    /** May this person be told about $topic by this agency? */
    public static function allows(int $agencyId, string $topic, int $userId): bool
    {
        if (! isset(self::TOPICS[$topic]) || ! $agencyId) {
            return true;
        }
        $s = self::settings($agencyId)[$topic];
        $held = array_map(fn ($r) => $r === 'home_visitor' ? 'educator' : $r, StaffNotifyRequirement::rolesIn($agencyId, $userId));
        $held = array_values(array_intersect(array_unique($held), array_keys(self::ROLES)));
        if (! $held) {
            return true;   // no role this setting governs (a platform admin)
        }
        foreach ($held as $r) {
            if ($s[$r]) {
                return true;
            }
        }

        return false;
    }

    /** Keep only the people who may hear about $topic. Accepts user ids. */
    public static function filter(int $agencyId, string $topic, array $userIds): array
    {
        return array_values(array_filter($userIds, fn ($u) => self::allows($agencyId, $topic, (int) $u)));
    }

    /** Keep only the email addresses whose account may hear about $topic (an address with no account is kept). */
    public static function filterEmails(int $agencyId, string $topic, array $emails): array
    {
        return array_values(array_filter($emails, function ($e) use ($agencyId, $topic) {
            $uid = DB::table('users')->whereRaw('LOWER(email) = ?', [mb_strtolower((string) $e)])->whereNull('deleted_at')->value('id');

            return ! $uid || self::allows($agencyId, $topic, (int) $uid);
        }));
    }

    /**
     * For Notify::write, where a row carries no agency: a person is switched off only if EVERY agency they work in
     * has switched their roles off for this topic.
     */
    public static function allowsAnywhere(string $topic, int $userId): bool
    {
        $agencies = DB::table('role_assignments as ra')->leftJoin('centres as c', 'c.id', '=', 'ra.centre_id')
            ->where('ra.user_id', $userId)->where('ra.active', 1)
            ->selectRaw('COALESCE(ra.agency_id, c.agency_id) as a')->pluck('a')->filter()->unique()->all();
        if (! $agencies) {
            return true;
        }
        foreach ($agencies as $a) {
            if (self::allows((int) $a, $topic, $userId)) {
                return true;
            }
        }

        return false;
    }
}
