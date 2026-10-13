<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * TEMPORARY PARENT ACCOUNTS (2026-10-13, Anthony: "the forms feature should create a temp
 * user if doesn't exist until a family is created where it should link/sync together, flag
 * temp users created in the daily/weekly emails requesting for family to be created or temp
 * user to be deleted if not used in 30 days (or configurable in the settings)" and "when
 * adding a new family and entering the name or email it should find if the temporary user
 * exists and link the two").
 *
 * A temporary parent is a guardian login at an agency that has NEVER been on a family —
 * in practice the account ManagedFormController::createPendingRecipient() makes when forms
 * are sent to an address nobody has a login for (Samantha Kicks, 2026-09-17).
 *
 *  - LINKING happens in AdminController::createFamily: a guardian email that matches an
 *    account already at this agency reuses that account (that rule predates this), and a
 *    temporary one is now invited properly if it never was. The wizard looks them up by
 *    name OR email as staff type (TempGuardianController::lookup) and offers the match.
 *  - The admin digest lists them (AdminDigest::tempGuardians) so somebody either adds the
 *    family or removes the account.
 *  - UNUSED ones are removed after remove_after_days (agencies.settings->temp_guardians,
 *    default 30, 0 = never): never signed in AND never signed a form. An account somebody
 *    has used is never removed automatically — it is flagged until a person decides.
 *    Removal is a soft delete (users.deleted_at) and is audited.
 */
final class TempGuardians
{
    public const KEY = 'temp_guardians';
    public const DEFAULTS = ['remove_after_days' => 30];

    public static function settings(int $agencyId): array
    {
        $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
        $mine = (isset($s[self::KEY]) && is_array($s[self::KEY])) ? $s[self::KEY] : [];
        $out = array_merge(self::DEFAULTS, array_intersect_key($mine, self::DEFAULTS));
        $out['remove_after_days'] = max(0, min(365, (int) $out['remove_after_days']));

        return $out;
    }

    public static function saveSettings(int $agencyId, array $next): array
    {
        $clean = ['remove_after_days' => max(0, min(365, (int) ($next['remove_after_days'] ?? self::DEFAULTS['remove_after_days'])))];
        DB::transaction(function () use ($agencyId, $clean) {
            $raw = DB::table('agencies')->where('id', $agencyId)->lockForUpdate()->value('settings');
            $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
            $s[self::KEY] = $clean;
            DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($s), 'updated_at' => now()]);
        });

        return $clean;
    }

    /** Users at this agency who hold a guardian role and have never been on any family. */
    public static function query(int $agencyId)
    {
        return DB::table('users as u')
            ->whereNull('u.deleted_at')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('role_assignments as ra')
                ->whereColumn('ra.user_id', 'u.id')->where('ra.role', 'guardian')
                ->where('ra.active', 1)->where('ra.agency_id', $agencyId))
            // Never on a family, anywhere — a parent whose family was withdrawn is not "temporary".
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('guardians as g')->whereColumn('g.user_id', 'u.id'))
            // A member of staff who also holds a guardian role is not a temporary parent.
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('role_assignments as rs')
                ->whereColumn('rs.user_id', 'u.id')->where('rs.active', 1)->where('rs.role', '!=', 'guardian'));
    }

    public static function isTemporary(int $agencyId, int $userId): bool
    {
        return self::query($agencyId)->where('u.id', $userId)->exists();
    }

    /** Rows for screens and the digest, newest first. $q narrows by name or email. */
    public static function list(int $agencyId, ?string $q = null, int $limit = 50): array
    {
        $days = self::settings($agencyId)['remove_after_days'];
        $rows = self::query($agencyId)
            ->when($q !== null && trim($q) !== '', function ($w) use ($q) {
                $t = '%' . str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower(trim($q))) . '%';
                $w->where(function ($x) use ($t) {
                    $x->whereRaw('LOWER(u.email) LIKE ?', [$t])
                      ->orWhereRaw("LOWER(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) LIKE ?", [$t])
                      ->orWhereRaw('LOWER(u.last_name) LIKE ?', [$t]);
                });
            })
            ->orderByDesc('u.created_at')->limit($limit)
            ->get(['u.id', 'u.first_name', 'u.last_name', 'u.email', 'u.status', 'u.created_at', 'u.last_login_at']);
        if ($rows->isEmpty()) return [];

        $ids = $rows->pluck('id')->all();
        $assigned = DB::table('managed_form_recipients')->whereIn('user_id', $ids)
            ->selectRaw('user_id, COUNT(DISTINCT managed_form_id) n')->groupBy('user_id')->pluck('n', 'user_id');
        $signed = DB::table('managed_form_signoffs')->whereIn('user_id', $ids)->whereNotNull('signed_at')
            ->selectRaw('user_id, COUNT(DISTINCT managed_form_id) n')->groupBy('user_id')->pluck('n', 'user_id');

        return $rows->map(function ($r) use ($assigned, $signed, $days) {
            $used = $r->last_login_at !== null || (int) ($signed[$r->id] ?? 0) > 0;
            return [
                'id' => (int) $r->id,
                'name' => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: $r->email,
                'first_name' => $r->first_name, 'last_name' => $r->last_name,
                'email' => $r->email,
                'status' => $r->status,
                'created_at' => $r->created_at,
                'last_login_at' => $r->last_login_at,
                'forms_assigned' => (int) ($assigned[$r->id] ?? 0),
                'forms_signed' => (int) ($signed[$r->id] ?? 0),
                'used' => $used,
                // When an UNUSED one will be removed; null for used ones or when removal is off.
                'removes_on' => (! $used && $days > 0) ? \Carbon\Carbon::parse($r->created_at)->addDays($days)->toDateString() : null,
            ];
        })->all();
    }

    /** Remove one temporary account (soft delete). Refuses anyone who is on a family. */
    public static function remove(int $agencyId, int $userId, ?int $byUserId, string $why): bool
    {
        if (! self::isTemporary($agencyId, $userId)) return false;
        $u = DB::table('users')->where('id', $userId)->first(['email', 'first_name', 'last_name']);
        DB::transaction(function () use ($agencyId, $userId) {
            DB::table('users')->where('id', $userId)->update(['deleted_at' => now(), 'updated_at' => now()]);
            DB::table('role_assignments')->where('user_id', $userId)->where('agency_id', $agencyId)->update(['active' => 0]);
            // Sign-ins it might still hold.
            DB::table('personal_access_tokens')->where('tokenable_type', 'App\\Models\\User')->where('tokenable_id', $userId)->delete();
        });
        try {
            Audit::write([
                'user_id' => $byUserId, 'agency_id' => $agencyId,
                'action' => 'user.temp_removed', 'entity_type' => 'user', 'entity_id' => $userId,
                'payload' => json_encode(['email' => $u->email ?? null, 'name' => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')),
                    'summary' => 'Removed the temporary (forms-only) parent account for ' . ($u->email ?? '#' . $userId) . ' — ' . $why . '.']),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
        }

        return true;
    }

    /** The unused accounts past the agency's limit. */
    public static function due(int $agencyId): array
    {
        $days = self::settings($agencyId)['remove_after_days'];
        if ($days <= 0) return [];
        $cut = now()->subDays($days)->toDateTimeString();

        return array_values(array_filter(self::list($agencyId, null, 500),
            fn ($r) => ! $r['used'] && (string) $r['created_at'] <= $cut));
    }
}
