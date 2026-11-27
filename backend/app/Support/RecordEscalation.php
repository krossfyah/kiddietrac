<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * WHO GETS ESCALATED DAILY RECORDS (2026-11-26, Anthony: "escalated daily records - super admin should not be
 * receiving this from ilearn - is there a setting you can wire up to set who gets this").
 *
 * The evening daily-records reminder (records:remind, App\Support\RecordReminders) copies the educator's managers.
 * It used to pick them with role rows alone (agency_admin on the agency + centre_director on the centre), so a
 * platform admin holding agency_admin for access got every iLearn copy (202 emails, 2026-10-16 .. 11-26).
 *
 * agencies.settings.record_escalation = {roles: [...], user_ids: [...], channels: {email, bell, push}}
 *   - roles:    agency_admin (every centre), centre_director (directors placed at the EDUCATOR'S centre, CentreScope),
 *               auditor (agency-wide);
 *   - user_ids: specific people, only ever the agency's OWN staff (AgencySignatories::ownStaff), and each one only
 *               for centres they can see (agency-wide role, or a role at that centre): a person boundary, never wider;
 *   - channels: email (on the educator's email, CC, as before), bell (in-app), push (phone; needs the bell row).
 * Default = agency admins + the centre's directors, by email, exactly what went out before, minus outsiders.
 * NEVER a platform admin, integration account or reseller grant (AgencySignatories::outsiderIds), even when chosen.
 * Saving with no role and no person, or no channel, is refused: it is never silently nobody.
 * Each person can still switch the topic off for themselves: StaffNotifyPrefs topic staff_records (not a required
 * topic: it is not a safety alert, and StaffNotifyRequirement only governs phone permission and sound categories).
 */
final class RecordEscalation
{
    public const ROLES = [
        'agency_admin' => 'Agency admins',
        'centre_director' => 'Directors of the educator\'s centre',
        'auditor' => 'Auditors',
    ];

    public const CHANNELS = ['email' => 'Email', 'bell' => 'Bell in KiddieTrac', 'push' => 'Phone notification'];

    public const DEFAULTS = [
        'roles' => ['agency_admin', 'centre_director'],
        'user_ids' => [],
        'channels' => ['email' => true, 'bell' => false, 'push' => false],
    ];

    public static function settings(int $agencyId): array
    {
        $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $all = is_string($raw) ? (json_decode($raw, true) ?: []) : (array) $raw;
        $s = is_array($all['record_escalation'] ?? null) ? $all['record_escalation'] : [];

        return self::normalise($s + self::DEFAULTS);
    }

    private static function normalise(array $s): array
    {
        $roles = array_values(array_intersect(array_keys(self::ROLES), array_map('strval', (array) ($s['roles'] ?? []))));
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($s['user_ids'] ?? [])))));
        $ch = (array) ($s['channels'] ?? []);
        $out = ['email' => (bool) ($ch['email'] ?? false), 'bell' => (bool) ($ch['bell'] ?? false), 'push' => (bool) ($ch['push'] ?? false)];
        if ($out['push']) $out['bell'] = true;   // a phone notification is the bell row, rung on the phone

        return ['roles' => $roles, 'user_ids' => $ids, 'channels' => $out];
    }

    /**
     * Validate + store. Throws \InvalidArgumentException with a friendly sentence when nobody would be told.
     * Chosen people outside the agency's own staff are refused, not quietly dropped.
     */
    public static function save(int $agencyId, array $in): array
    {
        $s = self::normalise($in);
        if (! $s['roles'] && ! $s['user_ids']) {
            throw new \InvalidArgumentException('Choose at least one role or person to receive escalated daily records.');
        }
        if (! $s['channels']['email'] && ! $s['channels']['bell']) {
            throw new \InvalidArgumentException('Choose at least one way to tell them: email, the bell or a phone notification.');
        }
        if ($s['user_ids']) {
            $own = array_map(fn ($p) => (int) $p['id'], AgencySignatories::ownStaff($agencyId));
            $bad = array_diff($s['user_ids'], $own);
            if ($bad) {
                throw new \InvalidArgumentException('Only your agency\'s own staff can receive escalated daily records.');
            }
        }
        DB::transaction(function () use ($agencyId, $s) {
            $raw = DB::table('agencies')->where('id', $agencyId)->lockForUpdate()->value('settings');
            $all = is_string($raw) ? (json_decode($raw, true) ?: []) : (array) $raw;
            $all['record_escalation'] = $s;
            DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($all), 'updated_at' => now()]);
        });

        return self::settings($agencyId);
    }

    /** "Agency admins, Directors of the educator's centre + Safia Ali, by email" (audit summaries). */
    public static function describe(int $agencyId, array $s): string
    {
        $who = array_map(fn ($r) => self::ROLES[$r], $s['roles']);
        if ($s['user_ids']) {
            $names = DB::table('users')->whereIn('id', $s['user_ids'])->get(['first_name', 'last_name'])
                ->map(fn ($u) => trim($u->first_name . ' ' . $u->last_name))->all();
            $who[] = implode(', ', $names);
        }
        $how = array_keys(array_filter($s['channels']));

        return (implode(', ', $who) ?: 'nobody') . ' by ' . (implode(', ', array_map(fn ($c) => strtolower(self::CHANNELS[$c]), $how)) ?: 'nothing');
    }

    /**
     * The people told about an escalated reminder for an educator at $centreId: active, not switched off, real
     * addresses, never an outsider, never the educator themselves.
     *
     * @return array<int,object{id:int,email:?string,first_name:?string,last_name:?string}>  keyed by user id
     */
    public static function recipients(int $agencyId, int $centreId, int $educatorId, ?array $s = null): array
    {
        $s = $s ?? self::settings($agencyId);
        $centreOk = $centreId > 0 && DB::table('centres')->where('id', $centreId)->where('agency_id', $agencyId)->exists();
        $ids = [];

        if (in_array('agency_admin', $s['roles'], true) || in_array('centre_director', $s['roles'], true)) {
            // CentreScope::managers = agency admins + directors placed at that centre; keep the roles chosen.
            $want = array_values(array_intersect($s['roles'], ['agency_admin', 'centre_director']));
            $ids = array_merge($ids, CentreScope::managers($agencyId, $centreOk ? [$centreId] : [])
                ->whereIn('ra.role', $want)->pluck('u.id')->all());
        }
        if (in_array('auditor', $s['roles'], true)) {
            $ids = array_merge($ids, DB::table('role_assignments')->where('active', 1)->where('role', 'auditor')
                ->where('agency_id', $agencyId)->pluck('user_id')->all());
        }
        if ($s['user_ids']) {
            // Chosen people, only for centres they can see: an agency-wide role here, or any role at this centre.
            $ids = array_merge($ids, DB::table('role_assignments')->where('active', 1)->whereIn('user_id', $s['user_ids'])
                ->where(function ($w) use ($agencyId, $centreId, $centreOk) {
                    $w->where(fn ($x) => $x->whereIn('role', ['agency_admin', 'auditor'])->where('agency_id', $agencyId));
                    if ($centreOk) $w->orWhere(fn ($x) => $x->whereIn('role', ['centre_director', 'educator', 'home_visitor'])->where('centre_id', $centreId));
                })->pluck('user_id')->all());
        }

        $ids = array_values(array_diff(array_unique(array_map('intval', $ids)), AgencySignatories::outsiderIds(), [$educatorId]));
        if (! $ids) return [];
        $q = Audience::excludeService(Audience::excludeOff(DB::table('users as u')->whereIn('u.id', $ids)->whereNull('u.deleted_at')));

        return $q->orderBy('u.first_name')->get(['u.id', 'u.email', 'u.first_name', 'u.last_name'])->keyBy('id')->all();
    }
}
