<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * THE PERMISSION GATE (permissions step 1, 2026-10-06).
 *
 * user + active agency -> the set of permission keys their roles grant there: the union
 * of the `roles.permissions` rows (Settings -> Roles & permissions) for every role the
 * user holds in that agency. A platform admin is always allowed ('*').
 *
 * Nothing BLOCKS on this yet. permissions.mode (platform setting) is
 *   off     - nothing reads the matrix (the old "Phase A: stored only")
 *   shadow  - PermissionShadow compares the matrix with what really happened and logs
 *             only the disagreements (permission_shadow_log). The default.
 *   enforce - refused in this step; it is switched on area by area after the shadow
 *             window (Settings & platform screens first).
 *
 * role_assignments.role is an ENUM (general_ci) and roles.key is varchar (unicode_ci):
 * the two are matched here in PHP, never joined in SQL, so the collations never meet.
 */
final class Permissions
{
    public const MODES = ['off', 'shadow', 'enforce'];
    public const SHADOW_DAYS = 14;
    private const REQ_KEY = 'kt.permissions';
    private const VER_KEY = 'permissions.ver';

    public static function mode(): string
    {
        $m = (string) PlatformSettings::get('permissions.mode', 'shadow');
        // 'enforce' cannot be on in this step even if a row says so.
        return in_array($m, ['off', 'shadow'], true) ? $m : 'shadow';
    }

    /** Switch the kill switch. Enforce is refused until the shadow window has been reviewed. */
    public static function setMode(string $mode): void
    {
        if ($mode === 'enforce') {
            throw new \DomainException('Enforcement is not available yet: the permission check is watch-only until the shadow window has been reviewed.');
        }
        if (! in_array($mode, ['off', 'shadow'], true)) {
            throw new \InvalidArgumentException('Mode must be off or shadow.');
        }
        PlatformSettings::set('permissions.mode', $mode);
        if ($mode === 'shadow' && ! PlatformSettings::get('permissions.shadow_started_at')) {
            PlatformSettings::set('permissions.shadow_started_at', now()->toDateTimeString());
        }
    }

    public static function shadowStartedAt(): ?string
    {
        $v = PlatformSettings::get('permissions.shadow_started_at');
        return $v ? (string) $v : null;
    }

    public static function shadowEndsOn(): ?string
    {
        $s = self::shadowStartedAt();
        return $s ? \Illuminate\Support\Carbon::parse($s)->addDays(self::SHADOW_DAYS)->toDateString() : null;
    }

    /** Call after any roles row changes so cached sets are rebuilt. */
    public static function bump(): void
    {
        Cache::forever(self::VER_KEY, (int) Cache::get(self::VER_KEY, 0) + 1);
    }

    public static function isPlatform(Request $request): bool
    {
        return UserRoles::has($request, 'platform_admin');
    }

    /** The agency the request acts in: the header when the user belongs there, else their first. */
    public static function agencyFor(Request $request): ?int
    {
        $rows = UserRoles::all($request);
        $hdr = (int) $request->header('X-Active-Agency-Id');
        if ($hdr > 0 && (self::isPlatform($request) || UserRoles::inAgency($request, $hdr))) {
            return $hdr;
        }
        foreach ($rows as $r) {
            if ($r->agency_id) {
                return (int) $r->agency_id;
            }
        }
        return null;
    }

    /** Role names the matrix knows about that this user holds in the agency. */
    public static function rolesIn(Request $request, ?int $agencyId): array
    {
        $names = [];
        foreach (UserRoles::all($request) as $r) {
            if ($agencyId && (int) $r->agency_id === $agencyId) {
                $names[] = (string) $r->role;
            }
        }
        // EnsureRole is agency-agnostic today, so a role held elsewhere still opens routes.
        if (! $names) {
            $names = UserRoles::names($request);
        }
        return array_values(array_intersect(array_unique($names), array_keys(PermissionMap::DEFAULTS)));
    }

    /**
     * Effective keys, or ['*'] for a platform admin. Per-request memo plus a short
     * cache (keyed by a version bumped whenever a role is saved).
     */
    public static function effective(Request $request): array
    {
        $user = $request->user();
        if (! $user) {
            return [];
        }
        if (self::isPlatform($request)) {
            return ['*'];
        }
        $agencyId = self::agencyFor($request);
        $memo = $request->attributes->get(self::REQ_KEY);
        if (is_array($memo) && $memo['uid'] === $user->id && $memo['aid'] === $agencyId) {
            return $memo['keys'];
        }
        $roles = self::rolesIn($request, $agencyId);
        sort($roles);
        $ck = 'perm.eff.' . (int) Cache::get(self::VER_KEY, 0) . '.' . ($agencyId ?: 0) . '.' . implode(',', $roles);
        $keys = $roles ? Cache::remember($ck, 60, fn () => self::compute($agencyId, $roles)) : [];
        $request->attributes->set(self::REQ_KEY, ['uid' => $user->id, 'aid' => $agencyId, 'keys' => $keys]);
        return $keys;
    }

    /** Union of the agency's rows for these roles; a role with no row falls back to the default. */
    public static function compute(?int $agencyId, array $roles): array
    {
        $rows = $agencyId
            ? DB::table('roles')->where('agency_id', $agencyId)->whereNull('deleted_at')->where('is_system', true)
                ->get(['key', 'permissions'])->keyBy(fn ($r) => (string) $r->key)
            : collect();
        $keys = [];
        foreach ($roles as $role) {
            $row = $rows->get($role);
            $set = $row ? (json_decode((string) $row->permissions, true) ?: []) : (PermissionMap::DEFAULTS[$role] ?? []);
            foreach ($set as $k) {
                $keys[(string) $k] = true;
            }
        }
        $keys = array_keys($keys);
        sort($keys);
        return $keys;
    }

    public static function allows(Request $request, string $key): bool
    {
        $eff = self::effective($request);
        return $eff === ['*'] || in_array($key, $eff, true);
    }
}
